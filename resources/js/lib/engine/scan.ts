import { nearestLevel, scanRow } from '@/lib/engine/probe-backdrop';
import type { ScanRun, WallPaint } from '@/lib/engine/probe-backdrop';

/**
 * A readback of what the engine actually drew, as data.
 *
 * Every other test in this project asserts on structure — geometry, colliders,
 * the order of a frame's passes. None of them looks at the picture, and the
 * rules that matter most describe failures whose only symptom is on screen: a
 * bright hairline down the rim of a portal, a sliver of the wrong wall at a
 * corner, a screenful of sky where a corridor should be. Those have always been
 * eye-verified, which means they are verified when somebody happens to walk
 * past them.
 *
 * This is the argument against that. `?debug` paints every wall in the level a
 * colour of its own and `?at=` stands the player on an exact spot, so a frame
 * can be rendered again and read back: which surface holds which columns, by
 * name, across a row of the real picture. A dozen spots read before a change
 * and after it is a diff, and a diff is not an opinion.
 *
 * It is a tool for the project, not scaffolding for one refactor. The obvious
 * next use is pinning the portal and mirror rules that nothing pins today.
 */

/** Where down the frame to read, as fractions of its height. */
const DEFAULT_ROWS = [0.25, 0.5, 0.75];

/** Runs narrower than this are dropped: they are edge pixels, not surfaces. */
const NARROWEST = 2;

export type ScanReading = {
    /** The row read, in canvas pixels, and where that is down the frame. */
    row: number;
    at: number;
    runs: {
        from: number;
        to: number;
        width: number;
        css: string;
        /** The wall this stretch belongs to, named, or null for anything else. */
        wall: string | null;
    }[];
};

export type ScanCapture = {
    level: string;
    /** Where the camera stood, exactly as `?at=` was given it. */
    spot: string;
    width: number;
    height: number;
    readings: ScanReading[];
};

/** Whether the address asks for a readback rather than a game. */
export function wantsScan(search: string): boolean {
    return new URLSearchParams(search).has('scan');
}

/**
 * Which rows to read, in canvas pixels.
 *
 * `?scan` alone reads three rows across the height. `?scan=0.5` reads one, and
 * a list reads each of them. Fractions rather than pixels, so a capture taken
 * on one screen can be compared with one taken on another.
 */
export function scanRowsOf(search: string, height: number): number[] {
    const asked = new URLSearchParams(search).get('scan') ?? '';

    const fractions = asked
        .split(',')
        .map((part) => Number(part.trim()))
        .filter((value) => Number.isFinite(value) && value > 0 && value < 1);

    return (fractions.length > 0 ? fractions : DEFAULT_ROWS).map((fraction) =>
        Math.floor(height * fraction),
    );
}

/**
 * Waits for a frame that is certainly this frame's.
 *
 * The drawing buffer only holds the picture immediately after it was drawn.
 * Read it at any other moment and it can come back as whatever was last
 * composited, which reads as one flat colour across the whole row and looks
 * exactly like a wall filling the view. Raced against a timer, because a level
 * that has stopped drawing would otherwise hang whoever asked.
 */
export function afterAFreshFrame(): Promise<void> {
    return Promise.race([
        new Promise<void>((settle) =>
            requestAnimationFrame(() => requestAnimationFrame(() => settle())),
        ),
        new Promise<void>((settle) => window.setTimeout(settle, 250)),
    ]);
}

/** One stretch of a row, named rather than numbered. */
function describe(run: ScanRun): ScanReading['runs'][number] {
    return {
        from: run.from,
        to: run.to,
        width: run.to - run.from,
        css: run.css,
        wall:
            run.wall === null
                ? null
                : `${run.wall.sector}#${run.wall.index}->${run.wall.beyond ?? 'outside'}`,
    };
}

/**
 * Reads the given rows of the frame that is in the drawing buffer now.
 *
 * Needs the renderer built with `preserveDrawingBuffer`, which debug mode is,
 * and the walls painted, which is what the legend is. The caller must have just
 * drawn the frame, either by drawing it itself or by waiting for one.
 *
 * A scan draws its own frames rather than waiting for the browser to: an
 * automated run happens in a tab nobody is looking at, and a tab nobody is
 * looking at gets no animation frames at all. Waiting for one there does not
 * time out — it simply never happens.
 */
export function readNow(
    canvas: HTMLCanvasElement,
    legend: WallPaint[],
    rows: number[],
): ScanReading[] {
    return rows.map((row) => ({
        row,
        at: Number((row / canvas.height).toFixed(3)),
        runs: scanRow(canvas, legend, row)
            .filter((run) => run.to - run.from >= NARROWEST)
            .map(describe),
    }));
}

/**
 * The one line a driver reads, and the object a person pokes at in the console.
 *
 * Written to `window.scanCapture` and printed with a marker on the front, so a
 * driver can wait for the marker in the console rather than polling the page.
 */
export function publishScan(capture: ScanCapture): void {
    (window as unknown as { scanCapture?: ScanCapture }).scanCapture = capture;

    console.log(`[scan] ${JSON.stringify(capture)}`);
}

/** The legend's colours, rounded the way a screenshot reports them. */
export function legendKey(legend: WallPaint[]): Record<string, string> {
    return Object.fromEntries(
        legend.map((wall) => [
            `rgb(${wall.colour.map(nearestLevel).join(',')})`,
            `${wall.sector}#${wall.index}`,
        ]),
    );
}
