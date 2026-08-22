<?php

namespace App\Http\Requests;

use App\Enums\ActorBehaviour;
use App\Enums\ConditionType;
use App\Enums\DoorSwing;
use App\Enums\EffectType;
use App\Enums\ThingKind;
use App\Enums\ThingRender;
use App\Enums\ThingUvMode;
use App\Enums\Verb;
use App\Models\Item;
use App\Models\Level;
use App\Models\LevelSector;
use App\Models\LevelSectorEdge;
use App\Models\LevelVertex;
use App\Services\LevelAssets;
use App\Services\PersonStats;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * A whole map as the editor drew it: the level's own settings, and every sector
 * with its corners. What arrives replaces what is stored.
 */
class UpdateLevelMapRequest extends FormRequest
{
    public function authorize(): bool
    {
        /** @var Level $level */
        $level = $this->route('level');

        return $this->user()?->can('update', $level) ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $assets = app(LevelAssets::class);
        $textures = Rule::in($assets->textures());
        $props = Rule::in($assets->props());

        return [
            'name' => ['required', 'string', 'max:255'],
            'description' => ['required', 'string'],
            'playerSprite' => ['required', 'string', Rule::in($assets->sprites())],
            'spawn' => ['required', 'array'],
            'spawn.x' => ['required', 'numeric'],
            'spawn.z' => ['required', 'numeric'],
            'spawn.angle' => ['required', 'numeric'],
            'ceilingHeight' => ['required', 'numeric', 'min:0.5', 'max:64'],

            'sky' => ['nullable', 'array'],
            'sky.image' => ['required_with:sky', 'string', Rule::in($assets->skies())],
            'sky.variant' => ['required_with:sky', 'integer', 'between:0,3'],
            'sky.theme' => ['nullable', 'string', Rule::in(array_keys($assets->backdrops()))],
            'sky.layers' => ['nullable', 'array'],
            'sky.layers.*' => ['integer', 'between:1,9'],

            'sectors' => ['present', 'array', 'max:200'],
            'sectors.*.slug' => ['required', 'string', 'max:255'],
            'sectors.*.name' => ['required', 'string', 'max:255'],
            'sectors.*.floorHeight' => ['required', 'numeric', 'between:-64,64'],
            'sectors.*.ceilingHeight' => ['required', 'numeric', 'between:-64,64'],
            'sectors.*.floorSlope' => ['sometimes', 'numeric', 'between:-8,8'],
            'sectors.*.floorSlopeEdge' => ['nullable', 'integer', 'min:0'],
            'sectors.*.ceilingSlope' => ['sometimes', 'numeric', 'between:-8,8'],
            'sectors.*.ceilingSlopeEdge' => ['nullable', 'integer', 'min:0'],
            'sectors.*.floorTexture' => ['nullable', 'string', $textures],
            'sectors.*.ceilingTexture' => ['nullable', 'string', $textures],
            'sectors.*.wallTexture' => ['nullable', 'string', $textures],
            'sectors.*.isSky' => ['required', 'boolean'],
            'sectors.*.isWater' => ['required', 'boolean'],
            'sectors.*.points' => ['required', 'array', 'min:3', 'max:64'],
            'sectors.*.points.*.x' => ['required', 'numeric', 'between:-512,512'],
            'sectors.*.points.*.z' => ['required', 'numeric', 'between:-512,512'],
            'sectors.*.points.*.wallTexture' => ['nullable', 'string', $textures],
            'sectors.*.points.*.blocks' => ['required', 'boolean'],
            'sectors.*.points.*.isMirror' => ['required', 'boolean'],
            'sectors.*.points.*.isSky' => ['nullable', 'boolean'],
            'sectors.*.points.*.portalLink' => ['nullable', 'string', 'max:64', 'regex:/^[a-z0-9-]+$/'],

            'things' => ['present', 'array', 'max:200'],
            'things.*.slug' => ['required', 'string', 'max:255'],
            'things.*.name' => ['required', 'string', 'max:255'],
            'things.*.description' => ['required', 'string'],
            'things.*.kind' => ['required', Rule::enum(ThingKind::class)],
            'things.*.sprite' => ['nullable', 'string', Rule::in($assets->sprites())],
            'things.*.behaviour' => ['nullable', Rule::enum(ActorBehaviour::class)],

            // A person's own numbers, or nothing at all and they get their
            // sprite's. All seven or none: a half-written block reads fine in
            // the editor and surprises you two months later.
            'things.*.stats' => ['nullable', 'array', 'size:'.count(PersonStats::ATTRIBUTES)],
            'things.*.stats.*' => ['required', 'integer', 'between:'.PersonStats::MINIMUM.','.PersonStats::MAXIMUM],
            'things.*.speed' => ['required', 'numeric', 'between:0,10'],
            // Either kind of picture. A box wants a tiling texture; a billboard
            // or a cross wants cutout art from the props folder, and which one
            // is right depends on how the thing is drawn rather than on what it
            // is. Accepting both here and letting the picker offer the sensible
            // one beats two columns that mean the same thing.
            'things.*.texture' => ['nullable', 'string', Rule::in([...$assets->textures(), ...$assets->props()])],
            // Omittable rather than required, the way stats already are. Every
            // one has a column default that is what a thing was doing before
            // there was a choice, so a payload that says nothing about how a
            // thing is drawn still saves and still draws the same. Checked
            // strictly when they are sent.
            'things.*.render' => ['sometimes', Rule::enum(ThingRender::class)],
            'things.*.planeCount' => ['sometimes', 'integer', 'in:2,3'],
            'things.*.uvMode' => ['sometimes', Rule::enum(ThingUvMode::class)],
            'things.*.textureAlt' => ['nullable', 'string', $props],
            'things.*.altFlag' => ['nullable', 'string', 'max:255'],
            'things.*.animationFrames' => ['sometimes', 'integer', 'between:1,16'],
            'things.*.animationFps' => ['sometimes', 'numeric', 'between:0.1,60'],
            'things.*.x' => ['required', 'numeric', 'between:-512,512'],
            'things.*.z' => ['required', 'numeric', 'between:-512,512'],
            'things.*.elevation' => ['required', 'numeric', 'between:-64,64'],
            'things.*.width' => ['required', 'numeric', 'between:0.05,64'],
            'things.*.depth' => ['required', 'numeric', 'between:0.05,64'],
            'things.*.height' => ['required', 'numeric', 'between:0.05,64'],
            'things.*.angle' => ['required', 'numeric'],
            'things.*.isSolid' => ['required', 'boolean'],
            'things.*.isDoor' => ['sometimes', 'boolean'],
            'things.*.swing' => ['sometimes', Rule::enum(DoorSwing::class)],
            'things.*.openAngle' => ['sometimes', 'numeric', 'between:15,180'],
            'things.*.openSeconds' => ['sometimes', 'numeric', 'between:0.05,10'],
            'things.*.isOpen' => ['sometimes', 'boolean'],
            'things.*.opensFlag' => ['nullable', 'string', 'max:255'],

            // What the player can do to a thing. Absent means the thing has
            // none, which is most of them; present and empty clears them.
            'things.*.interactions' => ['nullable', 'array', 'max:32'],
            'things.*.interactions.*.verb' => ['required', Rule::enum(Verb::class)],
            'things.*.interactions.*.response' => ['required', 'string', 'max:2000'],
            'things.*.interactions.*.priority' => ['required', 'integer', 'between:0,255'],
            'things.*.interactions.*.requiredItem' => ['nullable', 'string', 'max:255'],
            'things.*.interactions.*.conditions' => ['nullable', 'array', 'max:16'],
            'things.*.interactions.*.conditions.*.type' => ['required', Rule::enum(ConditionType::class)],
            'things.*.interactions.*.conditions.*.subject' => ['required', 'string', 'max:255'],
            'things.*.interactions.*.conditions.*.value' => ['nullable', 'string', 'max:255'],
            'things.*.interactions.*.effects' => ['nullable', 'array', 'max:16'],
            'things.*.interactions.*.effects.*.type' => ['required', Rule::enum(EffectType::class)],
            'things.*.interactions.*.effects.*.subject' => ['required', 'string', 'max:255'],
            'things.*.interactions.*.effects.*.value' => ['nullable', 'string', 'max:255'],
        ];
    }

    /**
     * A portal is a pair, so a link name has to be used exactly twice. One end
     * on its own would put the player somewhere there is nothing to arrive at.
     *
     * @return array<int, callable>
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                /** @var array<int, array<string, mixed>> $sectors */
                $sectors = $this->input('sectors', []);
                $ends = [];

                foreach ($sectors as $index => $sector) {
                    $this->checkSlopes($validator, $index, $sector);

                    /** @var array<int, array<string, mixed>> $points */
                    $points = $sector['points'] ?? [];

                    foreach ($points as $point) {
                        $link = $point['portalLink'] ?? null;

                        if (is_string($link) && $link !== '') {
                            $ends[$link] = ($ends[$link] ?? 0) + 1;
                        }
                    }
                }

                /** @var array<int, array<string, mixed>> $things */
                $things = $this->input('things', []);
                $slugs = [];

                foreach ($things as $index => $thing) {
                    $slug = $thing['slug'] ?? null;

                    if (is_string($slug) && isset($slugs[$slug])) {
                        $validator->errors()->add(
                            "things.{$index}.slug",
                            "Two things are both called \"{$slug}\"."
                        );
                    }

                    if (is_string($slug)) {
                        $slugs[$slug] = true;
                    }

                    // A person is drawn from a sprite sheet, not built as a box.
                    if (($thing['kind'] ?? null) === ThingKind::Actor->value
                        && ($thing['sprite'] ?? null) === null) {
                        $validator->errors()->add(
                            "things.{$index}.sprite",
                            'A person needs a sprite to be drawn from.'
                        );
                    }

                    $this->checkStats($validator, $index, $thing);
                    $this->checkAltTexture($validator, $index, $thing);
                    $this->checkDoor($validator, $index, $thing, $sectors);
                }

                $this->checkItemsExist($validator, $things);

                foreach ($ends as $link => $count) {
                    if ($count !== 2) {
                        $validator->errors()->add(
                            'sectors',
                            "The portal \"{$link}\" needs exactly two walls; it has {$count}."
                        );
                    }
                }
            },
        ];
    }

    /**
     * A sloped room has to hinge on a wall it has, and has to stay a room.
     *
     * Both surfaces are planes, so the largest and smallest gap between them
     * are always at a corner. Sampling the corners is therefore exact rather
     * than an approximation, and no amount of walking the interior would find
     * anything the corners miss.
     *
     * The flat clamp in prepareForValidation still handles the flat case by
     * quietly raising the ceiling. A slope cannot be fixed that way — there is
     * no single number to raise — so this refuses instead of guessing.
     *
     * @param  array<string, mixed>  $sector
     */
    private function checkSlopes(Validator $validator, int|string $index, array $sector): void
    {
        /** @var array<int, array<string, mixed>> $points */
        $points = $sector['points'] ?? [];
        $corners = count($points);

        $floorSlope = (float) ($sector['floorSlope'] ?? 0);
        $ceilingSlope = (float) ($sector['ceilingSlope'] ?? 0);
        $floorHinge = $sector['floorSlopeEdge'] ?? null;
        $ceilingHinge = $sector['ceilingSlopeEdge'] ?? null;

        foreach ([
            ['floorSlope', 'floorSlopeEdge', $floorSlope, $floorHinge],
            ['ceilingSlope', 'ceilingSlopeEdge', $ceilingSlope, $ceilingHinge],
        ] as [$slopeKey, $hingeKey, $slope, $hinge]) {
            if ($slope !== 0.0 && $hinge === null) {
                $validator->errors()->add(
                    "sectors.{$index}.{$hingeKey}",
                    'A sloped surface has to name the wall it hinges on.'
                );
            }

            if ($hinge !== null && $corners > 0 && (int) $hinge >= $corners) {
                $validator->errors()->add(
                    "sectors.{$index}.{$hingeKey}",
                    "This room has {$corners} walls, so it has no wall {$hinge}."
                );
            }
        }

        if ($floorSlope === 0.0 && $ceilingSlope === 0.0) {
            return;
        }

        if ($corners < 3 || ! $this->hingesAreUsable($corners, $floorHinge, $ceilingHinge)) {
            return;
        }

        $sample = new LevelSector([
            'floor_height' => (float) ($sector['floorHeight'] ?? 0),
            'ceiling_height' => (float) ($sector['ceilingHeight'] ?? 0),
            'floor_slope' => $floorSlope,
            'floor_slope_edge' => $floorHinge,
            'ceiling_slope' => $ceilingSlope,
            'ceiling_slope_edge' => $ceilingHinge,
        ]);

        $sample->setRelation('edges', collect($points)->map(
            fn (array $point): LevelSectorEdge => tap(
                new LevelSectorEdge,
                fn (LevelSectorEdge $edge) => $edge->setRelation(
                    'vertex',
                    new LevelVertex(['x' => (float) $point['x'], 'z' => (float) $point['z']]),
                )
            )
        ));

        foreach ($sample->corners() as [$x, $z]) {
            if ($sample->ceilingAt($x, $z) < $sample->floorAt($x, $z)) {
                $validator->errors()->add(
                    "sectors.{$index}.ceilingSlope",
                    'That slope puts the ceiling under the floor in one of the corners.'
                );

                return;
            }
        }
    }

    private function hingesAreUsable(int $corners, mixed $floorHinge, mixed $ceilingHinge): bool
    {
        foreach ([$floorHinge, $ceilingHinge] as $hinge) {
            if ($hinge !== null && (int) $hinge >= $corners) {
                return false;
            }
        }

        return true;
    }

    /**
     * A door has to stand in a doorway.
     *
     * The hole in the wall and the thing in the hole are authored separately —
     * two runs of wall with a gap, and a thing placed in the gap — so nothing
     * has ever stopped them drifting apart. Move a wall and the door is left
     * standing in the middle of a room; place one carelessly and it is inside
     * solid brick. Both look plausible in plan and neither is found until
     * somebody walks at it.
     *
     * So a door is checked against the boundaries near it: at least one has to
     * be a real doorway, meaning shared by two rooms and open from both sides.
     * Passability belongs to the boundary rather than to one room's idea of it,
     * so both sides are read.
     *
     * @param  array<string, mixed>  $thing
     * @param  array<int, array<string, mixed>>  $sectors
     */
    private function checkDoor(
        Validator $validator,
        int|string $index,
        array $thing,
        array $sectors,
    ): void {
        if (($thing['isDoor'] ?? false) !== true) {
            return;
        }

        $x = (float) ($thing['x'] ?? 0);
        $z = (float) ($thing['z'] ?? 0);

        // How far from a boundary still counts as standing in it. A door is
        // placed by eye on a grid, so it wants more slack than a hinge does.
        $reach = max(
            (float) ($thing['width'] ?? 0),
            (float) ($thing['depth'] ?? 0),
        ) / 2 + 0.5;

        foreach ($this->doorwaysNear($sectors, $x, $z, $reach) as $found) {
            if ($found) {
                return;
            }
        }

        $validator->errors()->add(
            "things.{$index}.isDoor",
            'A door has to stand in a doorway — a wall shared by two rooms and open from both sides.'
        );
    }

    /**
     * Whether each boundary within reach of a spot is a doorway.
     *
     * @param  array<int, array<string, mixed>>  $sectors
     * @return iterable<bool>
     */
    private function doorwaysNear(array $sectors, float $x, float $z, float $reach): iterable
    {
        /** @var array<string, list<bool>> $sides */
        $sides = [];

        foreach ($sectors as $sector) {
            /** @var array<int, array<string, mixed>> $points */
            $points = $sector['points'] ?? [];
            $corners = count($points);

            for ($at = 0; $at < $corners; $at++) {
                $from = $points[$at];
                $to = $points[($at + 1) % $corners];

                if (! $this->within($x, $z, $from, $to, $reach)) {
                    continue;
                }

                // Keyed so the same boundary from either room lands together,
                // however each of them wound it.
                $ends = [
                    sprintf('%.3f,%.3f', (float) $from['x'], (float) $from['z']),
                    sprintf('%.3f,%.3f', (float) $to['x'], (float) $to['z']),
                ];

                sort($ends);

                $sides[implode('|', $ends)][] = (bool) ($from['blocks'] ?? false);
            }
        }

        foreach ($sides as $blocks) {
            // Two rooms naming it, and neither of them blocking it.
            yield count($blocks) === 2 && ! in_array(true, $blocks, true);
        }
    }

    /**
     * @param  array<string, mixed>  $from
     * @param  array<string, mixed>  $to
     */
    private function within(float $x, float $z, array $from, array $to, float $reach): bool
    {
        $fromX = (float) $from['x'];
        $fromZ = (float) $from['z'];
        $spanX = (float) $to['x'] - $fromX;
        $spanZ = (float) $to['z'] - $fromZ;
        $length = $spanX * $spanX + $spanZ * $spanZ;

        if ($length <= 0.0) {
            return false;
        }

        $along = max(0.0, min(1.0, (($x - $fromX) * $spanX + ($z - $fromZ) * $spanZ) / $length));

        return hypot(
            $x - ($fromX + $along * $spanX),
            $z - ($fromZ + $along * $spanZ),
        ) <= $reach;
    }

    /**
     * An alternate texture and the flag that swaps it in are a pair.
     *
     * Either one alone means nothing — a second texture nothing can reach, or a
     * flag naming a texture that does not exist — and both failures are silent
     * at render time. Turned away rather than stored.
     *
     * @param  array<string, mixed>  $thing
     */
    private function checkAltTexture(Validator $validator, int|string $index, array $thing): void
    {
        $texture = $thing['textureAlt'] ?? null;
        $flag = $thing['altFlag'] ?? null;

        if (($texture === null) === ($flag === null)) {
            return;
        }

        $validator->errors()->add(
            $texture === null ? "things.{$index}.textureAlt" : "things.{$index}.altFlag",
            'An alternate texture and the flag that shows it go together, or neither does.'
        );
    }

    /**
     * A stat block belongs to a person, and it names the seven attributes and
     * nothing else. A typo is turned away rather than stored, since nothing
     * reads these yet and a misspelled key would sit there unnoticed.
     *
     * @param  array<string, mixed>  $thing
     */
    private function checkStats(Validator $validator, int|string $index, array $thing): void
    {
        $stats = $thing['stats'] ?? null;

        if ($stats === null) {
            return;
        }

        if (($thing['kind'] ?? null) !== ThingKind::Actor->value) {
            $validator->errors()->add(
                "things.{$index}.stats",
                'Only a person has stats.'
            );

            return;
        }

        if (! is_array($stats)) {
            return;
        }

        $named = array_keys($stats);
        sort($named);

        $wanted = PersonStats::ATTRIBUTES;
        sort($wanted);

        if ($named !== $wanted) {
            $validator->errors()->add(
                "things.{$index}.stats",
                'A stat block names exactly: '.implode(', ', PersonStats::ATTRIBUTES).'.'
            );
        }
    }

    /**
     * Every item slug an interaction names has to be one the game really has,
     * so that a typo breaks here rather than at the moment a child tries the
     * door and nothing happens.
     *
     * @param  array<int, array<string, mixed>>  $things
     */
    private function checkItemsExist(Validator $validator, array $things): void
    {
        /** @var Level $level */
        $level = $this->route('level');

        $known = Item::query()
            ->where('game_id', $level->game_id)
            ->pluck('slug')
            ->flip();

        $wantsAnItem = [
            EffectType::GiveItem->value,
            EffectType::RemoveItem->value,
        ];

        foreach ($things as $thingIndex => $thing) {
            /** @var array<int, array<string, mixed>> $interactions */
            $interactions = $thing['interactions'] ?? [];

            foreach ($interactions as $index => $interaction) {
                $where = "things.{$thingIndex}.interactions.{$index}";
                $required = $interaction['requiredItem'] ?? null;

                if (is_string($required) && $required !== '' && ! $known->has($required)) {
                    $validator->errors()->add(
                        "{$where}.requiredItem",
                        "There is no item called \"{$required}\" in this game."
                    );
                }

                /** @var array<int, array<string, mixed>> $effects */
                $effects = $interaction['effects'] ?? [];

                foreach ($effects as $at => $effect) {
                    $subject = $effect['subject'] ?? null;

                    if (! in_array($effect['type'] ?? null, $wantsAnItem, strict: true)) {
                        continue;
                    }

                    if (is_string($subject) && ! $known->has($subject)) {
                        $validator->errors()->add(
                            "{$where}.effects.{$at}.subject",
                            "There is no item called \"{$subject}\" in this game."
                        );
                    }
                }
            }
        }
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'sectors.*.points.min' => 'A room needs at least three corners.',
        ];
    }

    protected function prepareForValidation(): void
    {
        /** @var array<int, array<string, mixed>> $sectors */
        $sectors = $this->input('sectors', []);

        // A ceiling below its own floor would turn the room inside out.
        foreach ($sectors as $index => $sector) {
            $floor = (float) ($sector['floorHeight'] ?? 0);
            $ceiling = (float) ($sector['ceilingHeight'] ?? 0);

            if ($ceiling < $floor) {
                $sectors[$index]['ceilingHeight'] = $floor;
            }
        }

        $this->merge(['sectors' => $sectors]);
    }
}
