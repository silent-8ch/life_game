import { dimmed } from '@/lib/wireframe';

/**
 * What a level's untextured surfaces will actually look like.
 *
 * A swatch of the chosen colour would be a lie. The engine paints an untextured
 * surface twice: a solid fill of the colour knocked down to `SOLID_TINT` of its
 * brightness, and the grid lines over the top at full strength. Pick `#7fe0c9`
 * and what you get is a near-black wall with mint lines on it, so a picker that
 * shows a mint square is showing a colour the game never paints.
 *
 * So the preview is a room in one point perspective, drawn the way
 * `build/materials.ts` draws one, with the accent along the edges where the
 * surfaces meet. All three at once, because how they read against each other is
 * the actual decision.
 *
 * The same picture is built in PHP by `App\Services\WireframePreview` for the
 * admin form. Two of them because they are two stacks, not because the design
 * is in doubt; change one and change the other.
 */

const ACROSS = 320;
const DOWN = 180;

/** Where the back wall sits, as a fraction in from each edge. */
const DEPTH = 0.3;

type Corner = [number, number];

/**
 * One surface: the dimmed fill, then its grid at full strength.
 *
 * The grid joins opposite edges rather than being a lattice pasted on top, so a
 * trapezoid in perspective gets lines that converge the way a room's floor
 * does.
 */
function Surface({
    corners,
    color,
    steps,
}: {
    corners: [Corner, Corner, Corner, Corner];
    color: string;
    steps: number;
}) {
    const [a, b, c, d] = corners;
    const lines: [Corner, Corner][] = [];

    for (const [from, to, otherFrom, otherTo] of [
        [a, b, d, c],
        [a, d, b, c],
    ] as [Corner, Corner, Corner, Corner][]) {
        for (let step = 1; step < steps; step++) {
            const share = step / steps;

            lines.push([
                [
                    from[0] + (to[0] - from[0]) * share,
                    from[1] + (to[1] - from[1]) * share,
                ],
                [
                    otherFrom[0] + (otherTo[0] - otherFrom[0]) * share,
                    otherFrom[1] + (otherTo[1] - otherFrom[1]) * share,
                ],
            ]);
        }
    }

    return (
        <>
            <polygon
                points={corners.map((at) => at.join(',')).join(' ')}
                fill={dimmed(color)}
            />
            {lines.map(([from, to], at) => (
                <line
                    key={at}
                    x1={from[0]}
                    y1={from[1]}
                    x2={to[0]}
                    y2={to[1]}
                    stroke={color}
                    strokeWidth={1}
                    strokeOpacity={0.85}
                />
            ))}
        </>
    );
}

export default function WireframePreview({
    wall,
    floor,
    accent,
}: {
    wall: string;
    floor: string;
    accent: string;
}) {
    const left = ACROSS * DEPTH;
    const right = ACROSS - left;
    const top = DOWN * DEPTH;
    const bottom = DOWN - top;

    return (
        <svg
            viewBox={`0 0 ${ACROSS} ${DOWN}`}
            className="block w-full rounded border border-slate-700"
            style={{ background: '#05070a' }}
            role="img"
            aria-label="How untextured surfaces will look"
        >
            {/* Ceiling and floor, then the side walls, then the back wall —
                the order the eye reads a room in. */}
            <Surface
                corners={[
                    [0, 0],
                    [ACROSS, 0],
                    [right, top],
                    [left, top],
                ]}
                color={wall}
                steps={3}
            />
            <Surface
                corners={[
                    [0, DOWN],
                    [ACROSS, DOWN],
                    [right, bottom],
                    [left, bottom],
                ]}
                color={floor}
                steps={4}
            />
            <Surface
                corners={[
                    [0, 0],
                    [left, top],
                    [left, bottom],
                    [0, DOWN],
                ]}
                color={wall}
                steps={3}
            />
            <Surface
                corners={[
                    [ACROSS, 0],
                    [right, top],
                    [right, bottom],
                    [ACROSS, DOWN],
                ]}
                color={wall}
                steps={3}
            />
            <Surface
                corners={[
                    [left, top],
                    [right, top],
                    [right, bottom],
                    [left, bottom],
                ]}
                color={wall}
                steps={4}
            />

            {/* The accent rides the edges where surfaces meet. */}
            <rect
                x={left}
                y={top}
                width={right - left}
                height={bottom - top}
                fill="none"
                stroke={accent}
                strokeWidth={2}
            />
            {(
                [
                    [0, 0, left, top],
                    [ACROSS, 0, right, top],
                    [0, DOWN, left, bottom],
                    [ACROSS, DOWN, right, bottom],
                ] as [number, number, number, number][]
            ).map(([x1, y1, x2, y2], at) => (
                <line
                    key={at}
                    x1={x1}
                    y1={y1}
                    x2={x2}
                    y2={y2}
                    stroke={accent}
                    strokeWidth={2}
                />
            ))}
        </svg>
    );
}
