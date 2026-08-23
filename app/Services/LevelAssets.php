<?php

namespace App\Services;

use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;

/**
 * What the level editor has to work with: the textures, skies and backdrop
 * themes that are actually sitting in public/sprites. Nothing is registered by
 * hand — drop a file in the folder and it turns up in the editor.
 */
class LevelAssets
{
    /**
     * Which set of sprite sheets people are drawn from. The sheets live in a
     * folder of their own per style, and the name of the style is part of every
     * filename in it. This is the only place it is written down: the engine is
     * told which style to use through the level payload.
     */
    public const STYLE = 'realistic';

    /**
     * How tall each person stands, in metres, tallest first.
     *
     * @var array<string, float>
     */
    public const HEIGHTS = [
        'paul' => 1.85,
        'paul-toon' => 1.85,
        'wade' => 1.80,
        'wade-toon' => 1.80,
        'krystal' => 1.70,
        'krystal-toon' => 1.70,
        'boots' => 1.70,
        'luna' => 1.66,
        'luna-toon' => 1.66,
        'luke' => 1.62,
        'luke-toon' => 1.62,
        'william' => 1.55,
        'william-toon' => 1.55,
    ];

    /**
     * The people a new level is populated with. The stylised toons and the
     * debug figures are drawable and castable but not residents, so a new room
     * does not fill up with a dozen extra people; they are placed only when a
     * level deliberately casts them.
     *
     * @var list<string>
     */
    public const RESIDENTS = ['paul', 'wade', 'krystal', 'luna', 'luke', 'william'];

    /** Who a level gets if nobody has said who the player is. */
    public const PLAYER = 'paul';

    /**
     * The residents a new level is populated with, tallest first. The player is
     * one of them: which is being played is a matter for the level, and there
     * is nothing to stop the rest of you wandering about while you are at it.
     *
     * This is the auto-placed set, not everyone who can be drawn — see
     * `roster()` for that.
     *
     * @return list<string>
     */
    public function household(): array
    {
        return array_values(array_filter(
            array_keys(self::HEIGHTS),
            fn (string $sprite): bool => in_array($sprite, self::RESIDENTS, strict: true)
                && in_array($sprite, $this->sprites(), strict: true),
        ));
    }

    /**
     * Everyone who can be cast into a level — every person with both sheets and
     * a height, tallest first. A superset of `household()`: the toons and debug
     * figures are here, selectable and placeable, without being auto-placed.
     *
     * @return list<string>
     */
    public function roster(): array
    {
        return array_values(array_filter(
            array_keys(self::HEIGHTS),
            fn (string $sprite): bool => in_array($sprite, $this->sprites(), strict: true),
        ));
    }

    private const TEXTURE_PATH = 'sprites/textures';

    private const BACKDROP_PATH = 'sprites/bg';

    private const PROP_PATH = 'sprites/props';

    /**
     * Tiling surface textures, by name.
     *
     * @return list<string>
     */
    public function textures(): array
    {
        return $this->pngNamesIn(self::TEXTURE_PATH);
    }

    /**
     * Cutout art for props, by name.
     *
     * Kept apart from the tiling textures rather than thrown in with them.
     * They differ in kind — a surface texture is opaque, square and seamless; a
     * prop carries alpha, has a real aspect ratio and never repeats — and
     * mixing them puts forty doors and pot plants in the wall-texture dropdown.
     *
     * @return list<string>
     */
    public function props(): array
    {
        return $this->pngNamesIn(self::PROP_PATH);
    }

    /**
     * Skies, each a strip of four variants. A variant is one equirectangular
     * panorama: it wraps the whole dome, so the horizon sits at eye level
     * rather than underfoot the way a plain gradient's did.
     *
     * @return list<string>
     */
    public function skies(): array
    {
        return array_values(array_filter(
            $this->pngNamesIn(self::BACKDROP_PATH),
            fn (string $name): bool => Str::startsWith($name, 'sky-'),
        ));
    }

    /**
     * Backdrop themes and the numbered layers each one has, furthest first.
     *
     * @return array<string, list<int>>
     */
    public function backdrops(): array
    {
        $themes = [];

        foreach ($this->pngNamesIn(self::BACKDROP_PATH) as $name) {
            if (! preg_match('/^(?<theme>.+)_(?<layer>\d+)$/', $name, $matches)) {
                continue;
            }

            $themes[$matches['theme']][] = (int) $matches['layer'];
        }

        foreach ($themes as $theme => $layers) {
            sort($layers);
            $themes[$theme] = array_values(array_unique($layers));
        }

        ksort($themes);

        return $themes;
    }

    /**
     * People an actor can be drawn as: anyone with both sheets in the style's
     * folder.
     *
     * @return list<string>
     */
    public function sprites(): array
    {
        $names = $this->pngNamesIn('sprites/'.self::STYLE);
        $sprites = [];

        foreach ($names as $name) {
            if (! preg_match('/^(?<sprite>.+)-'.self::STYLE.'-cardinal-aligned-4step$/', $name, $matches)) {
                continue;
            }

            if (in_array($this->sheetName($matches['sprite'], 'diagonal'), $names, strict: true)) {
                $sprites[] = $matches['sprite'];
            }
        }

        sort($sprites);

        return $sprites;
    }

    /**
     * The styles a level may be drawn in: every `sprites/<style>` folder that
     * holds at least one person's sheets. `realistic` has always been there;
     * dropping a `stylized` folder of `-stylized-cardinal-aligned-4step` sheets
     * in beside it makes it an option with nothing else to register.
     *
     * @return list<string>
     */
    public function styles(): array
    {
        $styles = [];

        foreach (File::directories(public_path('sprites')) as $directory) {
            $style = basename($directory);
            foreach ($this->pngNamesIn('sprites/'.$style) as $name) {
                if (str_ends_with($name, '-'.$style.'-cardinal-aligned-4step')) {
                    $styles[] = $style;
                    break;
                }
            }
        }

        sort($styles);

        return $styles;
    }

    /**
     * The file a person's sheet is stored in, without the folder or extension.
     */
    public function sheetName(string $sprite, string $kind): string
    {
        return sprintf('%s-%s-%s-aligned-4step', $sprite, self::STYLE, $kind);
    }

    /**
     * Where a person's sheet sits under the public folder.
     */
    public function sheetPath(string $sprite, string $kind): string
    {
        return sprintf('sprites/%s/%s.png', self::STYLE, $this->sheetName($sprite, $kind));
    }

    /**
     * @return list<string>
     */
    private function pngNamesIn(string $path): array
    {
        $directory = public_path($path);

        if (! File::isDirectory($directory)) {
            return [];
        }

        $names = collect(File::files($directory))
            ->filter(fn ($file): bool => $file->getExtension() === 'png')
            ->map(fn ($file): string => $file->getFilenameWithoutExtension())
            ->values()
            ->all();

        sort($names);

        return $names;
    }
}
