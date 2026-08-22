import * as THREE from 'three';
import { paintWalls } from '@/lib/engine/probe-backdrop';
import type { WallPaint } from '@/lib/engine/probe-backdrop';
import { encoded } from '@/lib/engine/scan';

/**
 * The three pictures a ticket carries, taken from a game already running.
 *
 * ## Why this does not turn on `preserveDrawingBuffer`
 *
 * The scan harness reads frames back off the canvas, which needs the drawing
 * buffer kept, and `createView` only asks for that when the probe is on. The
 * obvious way to let a normal session photograph itself is therefore to turn
 * the flag on for everybody — and that was rejected. `preserveDrawingBuffer`
 * and `antialias` are **context attributes**: WebGL reads them once, when the
 * context is created, and nothing can change them afterwards. So a key cannot
 * "switch into capture mode for one frame" — the choice is made at startup for
 * the whole session or not at all, and made at startup it costs every frame the
 * player ever draws, to serve a key most of them will never press.
 *
 * So nothing here touches the canvas. Each picture is drawn again into an
 * offscreen render target and read back with `readRenderTargetPixels`, which
 * needs no context flag and costs nothing until somebody asks. What it costs
 * when they do ask is three extra renders and three synchronous readbacks — a
 * visible hitch of tens of milliseconds on the frame the key is pressed, which
 * is the right place to spend it, since the player has just told us they have
 * stopped to complain about something.
 *
 * ## The two conversions that are easy to get wrong
 *
 * A render target is **linear** and read back bottom-up. A canvas is **encoded
 * for display** and written top-down. Skip the first and every colour lands on
 * the wrong legend entry, which is the bug the scan harness already had once
 * and documented; skip the second and the picture is upside down, which at
 * least announces itself.
 */

/** What `paintWalls` needs put back afterwards. */
type BorrowedMaterial = {
    mesh: THREE.Mesh;
    material: THREE.Material | THREE.Material[];
};

export type ShotKind = 'normal' | 'wireframe' | 'walls';

export type CapturedShots = {
    shots: Record<ShotKind, Blob>;
    /**
     * What each colour in the `walls` picture means. Without it that picture
     * decodes to nothing: `paintWalls` hands out colours by walking the scene
     * with a running counter, so which colour is which wall belongs to this
     * build of this level and cannot be recovered from the pixels.
     */
    legend: WallPaint[];
};

/**
 * Every material in the scene, so the player's own view survives being
 * photographed.
 *
 * `paintWalls` assigns over `mesh.material` and keeps no way back. That is
 * fine in debug mode, where the whole session is painted and nobody is
 * playing, and not fine here: this runs in the middle of somebody's game, and
 * without this they would be left staring at a level in flat legend colours
 * with no way to undo it.
 */
export function rememberMaterials(group: THREE.Object3D): BorrowedMaterial[] {
    const borrowed: BorrowedMaterial[] = [];

    group.traverse((object) => {
        const mesh = object as THREE.Mesh;

        if (mesh.isMesh) {
            borrowed.push({ mesh, material: mesh.material });
        }
    });

    return borrowed;
}

/**
 * Puts back what `rememberMaterials` took note of.
 *
 * The materials `paintWalls` made are disposed on the way past. They are one
 * per wall and made fresh on every capture, so leaving them to the collector
 * would leak a level's worth of GPU programs every time somebody reports
 * something.
 */
export function restoreMaterials(borrowed: BorrowedMaterial[]): void {
    for (const { mesh, material } of borrowed) {
        const painted = mesh.material;

        if (painted !== material) {
            for (const one of Array.isArray(painted) ? painted : [painted]) {
                one.dispose();
            }
        }

        mesh.material = material;
    }
}

/**
 * A render target's pixels, turned into what a canvas wants.
 *
 * Two changes at once, because they are the same walk: rows come off the GPU
 * bottom-up and go into an `ImageData` top-down, and the colours arrive linear
 * and have to be encoded for display before anything looks at them or compares
 * them to the legend.
 *
 * Alpha is passed through untouched — it is not a colour and encoding it would
 * make every opaque pixel slightly transparent.
 */
export function displayPixels(
    pixels: Uint8Array,
    width: number,
    height: number,
): Uint8ClampedArray<ArrayBuffer> {
    // Backed by a plain ArrayBuffer on purpose. `ImageData` will not take an
    // array that might be over a SharedArrayBuffer, and the default type of a
    // bare `new Uint8ClampedArray(n)` leaves that open.
    const out = new Uint8ClampedArray(new ArrayBuffer(width * height * 4));

    for (let row = 0; row < height; row++) {
        const from = row * width * 4;
        const to = (height - 1 - row) * width * 4;

        for (let column = 0; column < width * 4; column += 4) {
            out[to + column] = encoded(pixels[from + column]);
            out[to + column + 1] = encoded(pixels[from + column + 1]);
            out[to + column + 2] = encoded(pixels[from + column + 2]);
            out[to + column + 3] = pixels[from + column + 3];
        }
    }

    return out;
}

/**
 * Draws the scene once into an offscreen target and hands back the pixels.
 *
 * The renderer's own target is put back before returning, so a capture that
 * throws halfway cannot leave the game drawing into somewhere the player
 * cannot see.
 */
function drawOffscreen(
    renderer: THREE.WebGLRenderer,
    scene: THREE.Scene,
    camera: THREE.Camera,
    target: THREE.WebGLRenderTarget,
): Uint8Array {
    const was = renderer.getRenderTarget();

    try {
        renderer.setRenderTarget(target);
        renderer.render(scene, camera);

        const pixels = new Uint8Array(target.width * target.height * 4);

        renderer.readRenderTargetPixels(
            target,
            0,
            0,
            target.width,
            target.height,
            pixels,
        );

        return pixels;
    } finally {
        renderer.setRenderTarget(was);
    }
}

/**
 * The largest picture the server will take, from
 * `StoreSupportTicketRequest::MAX_SHOT_PIXELS`.
 *
 * Repeated rather than shared because there is nothing to share it through —
 * but a capture that comes back too large is rejected after the player has
 * already waited for it and written their note, so it is worth being sure here
 * rather than finding out over the wire.
 */
export const MAX_SHOT_PIXELS = 2000;

/**
 * How big to draw the pictures, given the buffer the game is drawing into.
 *
 * The game renders at the size it is shown at, so its buffer is the honest
 * size to report a fault at — a picture of what the player saw should have the
 * pixels the player saw. It used to be a third of that and small enough to send
 * whatever the window; now a large window does need bringing down, and by whole
 * steps rather than by an arbitrary factor, so what survives is a clean
 * fraction of what was on screen.
 */
export function shotSize(
    width: number,
    height: number,
): { width: number; height: number } {
    const longest = Math.max(width, height);
    const step =
        longest <= MAX_SHOT_PIXELS ? 1 : Math.ceil(longest / MAX_SHOT_PIXELS);

    return {
        width: Math.max(1, Math.floor(width / step)),
        height: Math.max(1, Math.floor(height / step)),
    };
}

/**
 * Swaps every material for a wireframe of itself, and hands back nothing —
 * `rememberMaterials` is what puts it right.
 *
 * The wireframe view is the one that shows geometry rather than surface: a
 * wall in the wrong place, a sliver, a room drawn inside another room. Those
 * are invisible in the normal picture when everything wears the same texture,
 * which in this game it usually does.
 */
export function wireframeScene(group: THREE.Object3D): void {
    group.traverse((object) => {
        const mesh = object as THREE.Mesh;

        if (!mesh.isMesh) {
            return;
        }

        mesh.material = new THREE.MeshBasicMaterial({
            color: 0x00ff66,
            wireframe: true,
            fog: false,
        });
    });
}

/**
 * Pixels to a PNG, through a canvas because that is the only encoder a browser
 * offers.
 *
 * PNG rather than JPEG deliberately. The `walls` picture is decoded by colour
 * against the legend, and JPEG moves colours around most at the edge of a
 * shape — which is exactly where the one-pixel slivers being hunted live.
 * `paintWalls` spaces its levels 51 apart to survive that, and there is no
 * reason to spend the margin when these pictures are a few hundred pixels
 * across to begin with.
 */
async function toPng(
    pixels: Uint8ClampedArray<ArrayBuffer>,
    width: number,
    height: number,
): Promise<Blob> {
    const canvas = document.createElement('canvas');
    canvas.width = width;
    canvas.height = height;

    const context = canvas.getContext('2d');

    if (context === null) {
        throw new Error('No 2d context to encode the picture with.');
    }

    context.putImageData(new ImageData(pixels, width, height), 0, 0);

    return new Promise((resolve, reject) => {
        canvas.toBlob((blob) => {
            if (blob === null) {
                reject(new Error('The picture could not be encoded.'));

                return;
            }

            resolve(blob);
        }, 'image/png');
    });
}

/**
 * Takes the three pictures a ticket carries, and puts the scene back.
 *
 * Order matters. The normal picture is taken before anything in the scene has
 * been touched, so what the player was actually looking at survives even if
 * painting or wireframing goes wrong afterwards. And the restore is in a
 * `finally`, because leaving somebody's game standing in flat legend colours
 * would be a worse fault than whatever they stopped to report.
 */
export async function captureShots(
    renderer: THREE.WebGLRenderer,
    scene: THREE.Scene,
    camera: THREE.Camera,
    group: THREE.Object3D,
): Promise<CapturedShots> {
    const buffer = new THREE.Vector2();
    renderer.getDrawingBufferSize(buffer);

    const { width, height } = shotSize(buffer.x, buffer.y);
    const target = new THREE.WebGLRenderTarget(width, height);
    const borrowed = rememberMaterials(group);

    try {
        const normal = displayPixels(
            drawOffscreen(renderer, scene, camera, target),
            width,
            height,
        );

        wireframeScene(group);

        const wireframe = displayPixels(
            drawOffscreen(renderer, scene, camera, target),
            width,
            height,
        );

        // Back to the player's own materials before painting, or `paintWalls`
        // walks a scene that has already been changed out from under it and
        // paints the wireframes instead of the walls.
        restoreMaterials(borrowed);

        const legend = paintWalls(group);

        const walls = displayPixels(
            drawOffscreen(renderer, scene, camera, target),
            width,
            height,
        );

        return {
            shots: {
                normal: await toPng(normal, width, height),
                wireframe: await toPng(wireframe, width, height),
                walls: await toPng(walls, width, height),
            },
            legend,
        };
    } finally {
        // Safe to run after the restore above: a mesh already holding its own
        // material is left alone and nothing is disposed twice.
        restoreMaterials(borrowed);
        target.dispose();
    }
}
