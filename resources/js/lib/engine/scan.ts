import type * as THREE from 'three';
import type { PortalSurface } from '@/lib/engine/portal-surface';
import { isBackdrop, nearestLevel, scanRow } from '@/lib/engine/probe-backdrop';
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
 * - **The sky and the backdrop.** No sky is built at all when the probe is on
 *   (the guard is in `level-viewport`, not `createView`), so nothing overwrites
 *   `scene.background` and "no sky in the readback" says nothing about whether
 *   sky appears in the real picture.
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
        /** Looking out of the level: the backdrop, where the sky would be. */
        backdrop?: true;
    }[];
};

export type ScanCapture = {
    level: string;
    /**
     * What each portal pane is holding, when asked for. Not the same picture as
     * the frame: a hugged pane covers the screen with the far camera's whole
     * frustum, so the canvas cannot answer what the pane itself contains.
     */
    panes?: { home: string; onto: string[]; reading: ScanReading | null }[];
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
 * Whether the address asks the level to photograph itself and file the result.
 *
 * `?scan` cannot answer questions about light. It needs a drawing buffer that
 * survives being read, which is only on in debug mode — and debug mode builds
 * no sky dome and paints every wall a flat legend colour. So a fault reported
 * as *black*, or as *a mirror full of sky*, is invisible to the one view that
 * can be driven without a person at the keyboard. A whole day went into
 * reasoning around that.
 *
 * This draws the ordinary picture, textures and sky and all, then takes the
 * same three views `F` takes — read back offscreen into a render target, so no
 * `preserveDrawingBuffer` and no debug mode — and posts them to where a
 * snapshot lands. The point is that it needs nobody at the keyboard: an address
 * is enough, so a spot somebody reports can be stood on and *seen* rather than
 * reconstructed.
 *
 * Local only, because that is where snapshots are written at all.
 */
export function wantsShots(search: string): boolean {
    return new URLSearchParams(search).has('shots');
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
        ...(run.backdrop === true ? { backdrop: true } : {}),
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
                        run.backdrop === true
                            ? 'the backdrop — you are looking out of the level'
                            : run.wall === null
                              ? 'unpainted (floor, ceiling, sprite or pane)'
                              : `${run.wall.sector} #${run.wall.index} -> ${run.wall.beyond ?? 'outside'} (${run.wall.from.x},${run.wall.from.z})-(${run.wall.to.x},${run.wall.to.z})`,
                }));
        };
}

/**
 * Reads a row of what a pane is actually holding, rather than of what reached
 * the screen.
 *
 * These are not the same picture and the difference is the whole reason this
 * exists. A pane within `PANE_CLEARANCE` of the eye is squared up and blown up
 * to cover the view, so the canvas shows the far camera's entire frustum rather
 * than the mouth's silhouette — read the canvas and you learn what the player
 * sees, which at that range is not what the portal holds.
 *
 * Decoded against the same legend, so a run names the wall it is, or the
 * backdrop, in a pane's own texture.
 */
/**
 * A render target's pixel, brought into the space the legend is written in.
 *
 * The canvas is read back already encoded for display; a render target is not,
 * so the same colour comes out of the two with different bytes. Read one as if
 * it were the other and every colour lands on the wrong legend entry — the
 * backdrop check decoded as `room-2` and `room-3` before this, which is the
 * fabrication bug over again in the instrument built to investigate it.
 */
export function encoded(channel: number): number {
    const value = channel / 255;
    const shown =
        value <= 0.0031308
            ? value * 12.92
            : 1.055 * Math.pow(value, 1 / 2.4) - 0.055;

    return Math.round(shown * 255);
}

export function readPane(
    renderer: THREE.WebGLRenderer,
    pane: PortalSurface,
    legend: WallPaint[],
    depth = 0,
    at = 0.5,
): ScanReading | null {
    const target = pane.peek(depth);

    if (target === null) {
        return null;
    }

    const row = Math.floor(target.height * at);
    const pixels = new Uint8Array(target.width * 4);

    renderer.readRenderTargetPixels(target, 0, row, target.width, 1, pixels);

    const byColour = new Map<string, WallPaint>();

    for (const wall of legend) {
        byColour.set(wall.colour.join(','), wall);
    }

    const runs: ScanRun[] = [];

    for (let column = 0; column < target.width; column++) {
        const key = [
            nearestLevel(encoded(pixels[column * 4])),
            nearestLevel(encoded(pixels[column * 4 + 1])),
            nearestLevel(encoded(pixels[column * 4 + 2])),
        ].join(',');

        const last = runs[runs.length - 1];

        if (last !== undefined && last.css === key) {
            last.to = column + 1;

            continue;
        }

        runs.push({
            from: column,
            to: column + 1,
            css: key,
            wall: byColour.get(key) ?? null,
            ...(isBackdrop(key) ? { backdrop: true } : {}),
        });
    }

    return {
        row,
        at: Number(at.toFixed(3)),
        runs: runs
            .filter((run) => run.to - run.from >= NARROWEST)
            .map(describe),
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
