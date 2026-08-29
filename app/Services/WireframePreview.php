<?php

namespace App\Services;

use Illuminate\Support\HtmlString;

/**
 * What a level's untextured surfaces will actually look like.
 *
 * A swatch of the chosen colour would be a lie. The engine paints an untextured
 * surface twice: a solid fill of the colour knocked down to `SOLID_TINT` of its
 * brightness, and the grid lines over the top at full strength. Pick `#7fe0c9`
 * and what you get is a near-black wall with mint lines on it, so a picker that
 * shows you a mint square is showing you a colour the game never paints.
 *
 * So the preview is a room: back wall, side walls, ceiling and floor in one
 * point perspective, drawn the way `build/materials.ts` draws them, with the
 * accent along the edges where the surfaces meet. All three colours at once,
 * because how they read against each other is the actual decision.
 */
class WireframePreview
{
    /**
     * How much of its own brightness a solid fill keeps.
     *
     * Mirrors `SOLID_TINT` in `resources/js/lib/engine/build/constants.ts`,
     * which is the engine's copy and the one that decides what is drawn.
     * `ConstantsMatchTest` holds the two together.
     */
    public const SOLID_TINT = 0.11;

    /** The room, in one point perspective. The view is 320x180. */
    private const ACROSS = 320;

    private const DOWN = 180;

    /** Where the back wall sits, as a fraction in from each edge. */
    private const DEPTH = 0.3;

    public function render(string $wall, string $floor, string $accent): HtmlString
    {
        $left = self::ACROSS * self::DEPTH;
        $right = self::ACROSS - $left;
        $top = self::DOWN * self::DEPTH;
        $bottom = self::DOWN - $top;

        $parts = [
            // Ceiling and floor first, then the side walls over them, then the
            // back wall last — the same order the eye reads a room in.
            $this->surface([[0, 0], [self::ACROSS, 0], [$right, $top], [$left, $top]], $wall, 3),
            $this->surface([[0, self::DOWN], [self::ACROSS, self::DOWN], [$right, $bottom], [$left, $bottom]], $floor, 4),
            $this->surface([[0, 0], [$left, $top], [$left, $bottom], [0, self::DOWN]], $wall, 3),
            $this->surface([[self::ACROSS, 0], [$right, $top], [$right, $bottom], [self::ACROSS, self::DOWN]], $wall, 3),
            $this->surface([[$left, $top], [$right, $top], [$right, $bottom], [$left, $bottom]], $wall, 4),
        ];

        // The accent rides the edges where surfaces meet: the back wall's
        // outline, and the four lines running out to the corners of the view.
        $parts[] = sprintf(
            '<rect x="%s" y="%s" width="%s" height="%s" fill="none" stroke="%s" stroke-width="2"/>',
            $left,
            $top,
            $right - $left,
            $bottom - $top,
            e($accent),
        );

        foreach ([[0, 0, $left, $top], [self::ACROSS, 0, $right, $top], [0, self::DOWN, $left, $bottom], [self::ACROSS, self::DOWN, $right, $bottom]] as [$x1, $y1, $x2, $y2]) {
            $parts[] = sprintf(
                '<line x1="%s" y1="%s" x2="%s" y2="%s" stroke="%s" stroke-width="2"/>',
                $x1,
                $y1,
                $x2,
                $y2,
                e($accent),
            );
        }

        return new HtmlString(sprintf(
            '<svg viewBox="0 0 %d %d" class="w-full rounded-lg ring-1 ring-gray-950/10 dark:ring-white/20" style="display:block;background:#05070a" role="img" aria-label="How untextured surfaces will look">%s</svg>',
            self::ACROSS,
            self::DOWN,
            implode('', $parts),
        ));
    }

    /**
     * One surface: the dimmed fill, then its grid lines at full strength.
     *
     * The grid is drawn by walking the quad's opposite edges and joining them,
     * so a trapezoid in perspective gets lines that converge the way the floor
     * of a room does rather than an evenly spaced lattice pasted on top.
     *
     * @param  list<array{0: float|int, 1: float|int}>  $corners  Clockwise.
     */
    private function surface(array $corners, string $color, int $steps): string
    {
        $points = implode(' ', array_map(
            fn (array $corner): string => $corner[0].','.$corner[1],
            $corners,
        ));

        $parts = [sprintf(
            '<polygon points="%s" fill="%s"/>',
            $points,
            e($this->dimmed($color)),
        )];

        [$a, $b, $c, $d] = $corners;

        foreach ([[$a, $b, $d, $c], [$a, $d, $b, $c]] as [$from, $to, $otherFrom, $otherTo]) {
            for ($step = 1; $step < $steps; $step++) {
                $share = $step / $steps;

                $parts[] = sprintf(
                    '<line x1="%s" y1="%s" x2="%s" y2="%s" stroke="%s" stroke-width="1" stroke-opacity="0.85"/>',
                    round($from[0] + ($to[0] - $from[0]) * $share, 2),
                    round($from[1] + ($to[1] - $from[1]) * $share, 2),
                    round($otherFrom[0] + ($otherTo[0] - $otherFrom[0]) * $share, 2),
                    round($otherFrom[1] + ($otherTo[1] - $otherFrom[1]) * $share, 2),
                    e($color),
                );
            }
        }

        return implode('', $parts);
    }

    /**
     * A colour knocked down the way the engine knocks it down.
     *
     * Not simply `hex * 0.11`. Three converts a hex colour to linear light on
     * the way in, multiplies there, and encodes back to sRGB on the way out —
     * so `#7fe0c9` becomes `#2a2a2a`-ish rather than the near-black a naive
     * multiply gives. Getting this wrong makes the preview far darker than the
     * game, which is the one thing a preview must not be.
     */
    public function dimmed(string $color): string
    {
        $hex = ltrim($color, '#');

        if (strlen($hex) === 3) {
            $hex = $hex[0].$hex[0].$hex[1].$hex[1].$hex[2].$hex[2];
        }

        if (! preg_match('/^[0-9a-fA-F]{6}$/', $hex)) {
            return '#000000';
        }

        $out = '#';

        foreach (str_split($hex, 2) as $pair) {
            $channel = hexdec($pair) / 255;

            $linear = $channel <= 0.04045
                ? $channel / 12.92
                : (($channel + 0.055) / 1.055) ** 2.4;

            $dim = $linear * self::SOLID_TINT;

            $encoded = $dim <= 0.0031308
                ? $dim * 12.92
                : 1.055 * $dim ** (1 / 2.4) - 0.055;

            $out .= str_pad(dechex((int) round(max(0, min(1, $encoded)) * 255)), 2, '0', STR_PAD_LEFT);
        }

        return $out;
    }
}
