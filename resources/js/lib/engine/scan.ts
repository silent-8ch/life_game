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
 *
 * ## What it cannot see, which matters as much as what it can
 *
 * Reading the frame back needs `preserveDrawingBuffer`, and that is only on in
 * debug mode — where the probe backdrop replaces the background and every wall
 * is painted a flat legend colour. So a scan is **structurally blind** to
 * everything debug mode takes away:
 *
 * - **Textures**, and therefore texture crawl and aliasing of every kind. In
 *   the only mode it can read, there are no textures to shimmer.
 * - **The sky, the fog and the backdrop.** `createView` builds no sky dome at
 *   all when the probe is on, so "no sky in the readback" says nothing about
 *   whether sky appears in the real picture.
 * - **Anything that is motion rather than geometry.** It reads still frames on
 *   a fixed timestep, with the people deliberately frozen, so a flicker between
 *   two frames is invisible to it unless somebody asks it for two frames.
 *
 * A green diff here is evidence about geometry and nothing else. Read as
 * absence of a fault it would be worse than no tool, because it would be
 * believed.
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
 * Waits for a frame that is certainly this frame's, and says whether it got one.
 *
 * Raced against a timer, because a level that has stopped drawing would
 * otherwise wait for ever — and **the caller is told which won**. A cleared
 * buffer decodes as one flat colour across the whole row and reads as a single
 * wall filling the view, which is the most convincing lie available: a wall
 * filling the view is exactly what somebody chasing a portal fault expects to
 * be arguing about. Paused behind the verb menu, or in a background tab that
 * gets no animation frames at all, the honest answer is that nothing was read.
 *
 * The drawing buffer only holds the picture immediately after it was drawn.
 * Read it at any other moment and it can come back as whatever was last
 * composited, which reads as one flat colour across the whole row and looks
 * exactly like a wall filling the view. Raced against a timer, because a level
 * that has stopped drawing would otherwise hang whoever asked.
 */
export function afterAFreshFrame(): Promise<boolean> {
    return Promise.race([
        new Promise<boolean>((settle) =>
            requestAnimationFrame(() =>
                requestAnimationFrame(() => settle(true)),
            ),
        ),
        new Promise<boolean>((settle) =>
            window.setTimeout(() => settle(false), 250),
        ),
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
 * Prints the legend and hangs a reader on the window, for a person at a
 * console rather than a driver collecting captures.
 *
 * The legend goes to the console rather than on screen: it is one line per wall
 * in the level, far too much to read over the view, and it only has to be
 * looked up once a sliver has been caught in a picture. `scanRow(row)` names
 * every surface across that row and the columns each one holds, which settles
 * an argument about a two-pixel sliver that no amount of squinting at a
 * screenshot will.
 */
export function armConsoleScan(
    canvas: HTMLCanvasElement,
    legend: WallPaint[],
): void {
    console.log(
        `[debug] ${legend.length} walls painted. Read a colour off the picture, round each channel to the nearest of 0/51/102/153/204/255, and look it up here.`,
    );
    console.table(
        legend.map((wall) => ({
            css: wall.css,
            room: wall.sector,
            beyond: wall.beyond,
            corner: wall.index,
            from: `${wall.from.x},${wall.from.z}`,
            to: `${wall.to.x},${wall.to.z}`,
        })),
    );

    (window as unknown as { scanRow?: (row?: number) => unknown }).scanRow =
        async (row?: number) => {
            // A person at a console has not just drawn the frame, so this one
            // does wait for one — and says so plainly when none arrives, rather
            // than reading the buffer anyway and describing the cleared frame
            // as a single wall filling the view.
            if (!(await afterAFreshFrame())) {
                return 'The level is not drawing, so nothing was read. Unpause it, or bring the tab to the front, and ask again.';
            }

            return scanRow(canvas, legend, row ?? Math.floor(canvas.height / 2))
                .filter((run) => run.to - run.from > 1)
                .map((run) => ({
                    columns: `${run.from}-${run.to}`,
                    width: run.to - run.from,
                    css: run.css,
                    wall:
                        run.wall === null
                            ? 'unpainted (floor, ceiling, sprite or pane)'
                            : `${run.wall.sector} #${run.wall.index} -> ${run.wall.beyond ?? 'outside'} (${run.wall.from.x},${run.wall.from.z})-(${run.wall.to.x},${run.wall.to.z})`,
                }));
        };
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
