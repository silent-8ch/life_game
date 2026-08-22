import * as THREE from 'three';

/**
 * A backdrop nothing in a level could be mistaken for.
 *
 * Turned on with `?debug` in the address. Every leak this engine has been
 * chased for — a sliver down the edge of a portal, a flash along a room
 * boundary, a mouth that draws sky where it should draw a room — shows as a
 * few pixels of whatever stands behind everything else. Against the ordinary
 * sky those pixels are a pale blue against pale plaster and are genuinely hard
 * to be sure of, in a screenshot most of all.
 *
 * So in debug the backdrop becomes a magenta and green check. Neither colour
 * appears anywhere in the art, which is browns, creams and blues, so a single
 * pixel of either is a leak and nothing else. The check rather than a flat
 * fill because its size tells you how far away the hole is looking, and its
 * edges show whether the sliver is moving with the view or pinned to the
 * geometry.
 */

/** How many squares across the whole backdrop. */
const SQUARES = 32;

/** Pixels per square. Small on purpose: it is never meant to look good. */
const SQUARE = 16;

/** The two colours, chosen for appearing nowhere else. */
const INK = '#ff00d4';
const PAPER = '#00ff66';

export type ProbeBackdrop = {
    texture: THREE.Texture;
    dispose: () => void;
};

/** Whether the address asks for the loud backdrop. */
export function wantsProbeBackdrop(search: string): boolean {
    const asked = new URLSearchParams(search).get('debug');

    return asked !== null && asked !== '0' && asked !== 'false';
}

export function createProbeBackdrop(): ProbeBackdrop {
    const size = SQUARES * SQUARE;
    const canvas = document.createElement('canvas');

    canvas.width = size;
    canvas.height = size;

    const ink = canvas.getContext('2d');

    if (ink === null) {
        throw new Error('The probe backdrop needs a 2d canvas.');
    }

    for (let row = 0; row < SQUARES; row += 1) {
        for (let column = 0; column < SQUARES; column += 1) {
            ink.fillStyle = (row + column) % 2 === 0 ? INK : PAPER;
            ink.fillRect(column * SQUARE, row * SQUARE, SQUARE, SQUARE);
        }
    }

    const texture = new THREE.CanvasTexture(canvas);

    // Nearest, so a one-pixel sliver keeps a colour that can be matched exactly
    // rather than blending into something that looks almost like the wall.
    texture.magFilter = THREE.NearestFilter;
    texture.minFilter = THREE.NearestFilter;
    texture.colorSpace = THREE.SRGBColorSpace;

    return {
        texture,
        dispose: () => texture.dispose(),
    };
}

/**
 * Which boundary a painted face belongs to, as build-level tagged it.
 */
export type PaintedWall = {
    sector: string;
    index: number;
    from: { x: number; z: number };
    to: { x: number; z: number };
    beyond: string | null;
};

export type WallPaint = PaintedWall & {
    /** The colour it was given, as three 0-255 channels. */
    colour: [number, number, number];
    /** The same colour written the way a screenshot reports it. */
    css: string;
};

/**
 * Six levels per channel, evenly spread.
 *
 * A screenshot arrives as JPEG, which moves colours around by a few counts and
 * moves them most at the edge of a shape — which is exactly where a one-pixel
 * sliver lives. Steps of 51 survive that: anything read back can be rounded to
 * the nearest level and still land on the level it started at. It gives 216
 * colours, which is more boundaries than a room has ever had here.
 */
const LEVELS = [0, 51, 102, 153, 204, 255];

/** Reads a channel back off a screenshot to the level it was painted with. */
export function nearestLevel(value: number): number {
    return LEVELS.reduce((best, level) =>
        Math.abs(level - value) < Math.abs(best - value) ? level : best,
    );
}

/**
 * Paints every wall a colour of its own, and hands back what each one means.
 *
 * The faults being chased here are slivers a pixel or two wide, showing a
 * surface that should not be visible from where the player is standing. Their
 * own texture is no help: every wall in the house is the same cream paper, so
 * a sliver of the wrong one looks exactly like the right one. Given a colour
 * each, reading the sliver off a screenshot names the wall.
 *
 * Grey 26 is skipped for the first face so nothing is painted the colour of the
 * page behind the canvas.
 */
export function paintWalls(group: THREE.Object3D): WallPaint[] {
    const painted: WallPaint[] = [];
    const spent = new Set<string>();

    group.traverse((object) => {
        const flat = object.userData.flat as
            | { sector: string; height: number }
            | undefined;

        // Floors and ceilings all go one reserved colour. They are never the
        // thing being hunted, and left in their own textures their pixels can
        // land on a colour the legend uses and be read back as a wall that is
        // not there.
        if (flat !== undefined) {
            object.traverse((part) => {
                const mesh = part as THREE.Mesh;

                if (mesh.isMesh) {
                    mesh.material = new THREE.MeshBasicMaterial({
                        color: new THREE.Color().setRGB(
                            0,
                            0,
                            0,
                            THREE.SRGBColorSpace,
                        ),
                        side: THREE.DoubleSide,
                        fog: false,
                    });
                }
            });

            return;
        }

        const wall = object.userData.wall as PaintedWall | undefined;

        if (wall === undefined) {
            return;
        }

        // Spread through the 216 rather than counting up from black, so two
        // walls that meet are never two colours a screenshot could confuse.
        // Stepped from one, so nothing is painted the reserved black that
        // floors and ceilings take.
        const step = painted.length + 1;
        const red = LEVELS[(step * 5) % 6];
        const green = LEVELS[Math.floor(step / 6) % 6];
        const blue = LEVELS[Math.floor(step / 36) % 6];

        const key = `${red},${green},${blue}`;

        if (spent.has(key)) {
            // More faces than colours. Better to leave the extra ones unpainted
            // than to hand back a colour that means two things.
            return;
        }

        spent.add(key);

        // Set as sRGB, which is what the renderer writes out. Handed over as
        // plain numbers three.js would take them for linear values and the
        // picture would come back several shades lighter than the legend says,
        // which defeats the whole point of painting them.
        const colour = new THREE.Color().setRGB(
            red / 255,
            green / 255,
            blue / 255,
            THREE.SRGBColorSpace,
        );

        object.traverse((part) => {
            const mesh = part as THREE.Mesh;

            if (!mesh.isMesh) {
                return;
            }

            mesh.material = new THREE.MeshBasicMaterial({
                color: colour,
                side: THREE.DoubleSide,
                fog: false,
            });
        });

        painted.push({
            ...wall,
            colour: [red, green, blue],
            css: `rgb(${red}, ${green}, ${blue})`,
        });
    });

    return painted;
}

/** One stretch of a scanline that came back a single painted colour. */
export type ScanRun = {
    /** Columns the stretch covers, inclusive of `from`, exclusive of `to`. */
    from: number;
    to: number;
    css: string;
    /** The wall that colour belongs to, or null for anything unpainted. */
    wall: PaintedWall | null;
};

/**
 * Reads one row of a rendered frame back and says which wall each stretch is.
 *
 * This is the whole point of painting: a sliver two pixels wide down the edge
 * of a portal is a thing an eye argues about and a scan does not. Feed it the
 * row through the middle of the opening and it names, in order, every surface
 * across the view and exactly which columns each one holds.
 *
 * Needs the renderer built with preserveDrawingBuffer, which debug mode does.
 *
 * @param  legend  What `paintWalls` handed back.
 * @param  row     Which line of the canvas to read, in canvas pixels.
 */
export function scanRow(
    canvas: HTMLCanvasElement,
    legend: WallPaint[],
    row: number,
): ScanRun[] {
    const strip = document.createElement('canvas');

    strip.width = canvas.width;
    strip.height = 1;

    const ink = strip.getContext('2d', { willReadFrequently: true });

    if (ink === null) {
        throw new Error('Reading a scanline needs a 2d canvas.');
    }

    ink.drawImage(canvas, 0, -row);

    const pixels = ink.getImageData(0, 0, strip.width, 1).data;

    const byColour = new Map<string, PaintedWall>();

    for (const wall of legend) {
        byColour.set(wall.colour.join(','), wall);
    }

    const runs: ScanRun[] = [];

    for (let column = 0; column < strip.width; column += 1) {
        const at = column * 4;
        const key = [
            nearestLevel(pixels[at]),
            nearestLevel(pixels[at + 1]),
            nearestLevel(pixels[at + 2]),
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
        });
    }

    return runs;
}

/** A spot to drop the player on, read straight off a snapshot. */
export type ForcedSpot = {
    x: number;
    z: number;
    /** Degrees, exactly as a snapshot prints them. */
    yaw: number;
    pitch: number;
};

/**
 * Where `?at=` asks the player to start, if it asks at all.
 *
 * A snapshot records `x`, `z`, `yaw` and `pitch`, and until now the only way to
 * stand on one again was to write its position into the level's spawn. That
 * loses two things. The spawn's angle is the negative of the player's yaw, so
 * feeding a snapshot's yaw in unchanged aims the camera somewhere else
 * entirely and quietly — the view looks plausible, it is simply not the view
 * that was reported. And the spawn carries no pitch at all, so a fault only
 * visible looking down could not be reproduced from a snapshot at all.
 *
 * `?at=x,z,yaw,pitch` takes the four numbers in the order and the units a
 * snapshot prints them, so a reported spot can be pasted back without arithmetic.
 */
export function spotFromSearch(search: string): ForcedSpot | null {
    const asked = new URLSearchParams(search).get('at');

    if (asked === null) {
        return null;
    }

    const numbers = asked.split(',').map((part) => Number(part.trim()));

    if (numbers.length < 2 || numbers.some((value) => !Number.isFinite(value))) {
        return null;
    }

    return {
        x: numbers[0],
        z: numbers[1],
        yaw: numbers[2] ?? 0,
        pitch: numbers[3] ?? 0,
    };
}
