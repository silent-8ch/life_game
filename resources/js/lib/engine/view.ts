import * as THREE from 'three';
import {
    BACKGROUND_COLOR,
    FAR_PLANE,
    FIELD_OF_VIEW,
    NEAR_PLANE,
} from '@/lib/engine/constants';
import type { ProbeBackdrop } from '@/lib/engine/probe-backdrop';
import { boundsOf } from '@/lib/engine/sectors';
import type { Level } from '@/types';

/**
 * The three things a level is drawn with, and how far it has to see.
 *
 * All of it follows from the level and from whether this is a debug run:
 * nothing here knows about the player, the frame loop, or React.
 */

const FOG_NEAR = 8;
const FOG_FAR = 60;

export type LevelView = {
    scene: THREE.Scene;
    camera: THREE.PerspectiveCamera;
    renderer: THREE.WebGLRenderer;
    /** Matches the canvas to its container. Does nothing while it has no size. */
    resize: () => void;
    dispose: () => void;
};

/**
 * How far the camera has to be able to see.
 *
 * `FAR_PLANE` is what an ordinary level needs, and it is kept as tight as that
 * on purpose: walls sit a centimetre apart where they are inset, and the
 * further the far plane goes the less depth there is to tell them apart with.
 *
 * But somebody who makes a person a hundred metres tall would rather see all of
 * them than keep the precision, and the far plane is what was cutting the top
 * off. So it opens up exactly as far as the level asks and no further.
 */
export function reachOf(level: Level): number {
    const bounds = boundsOf(level.sectors);
    const across = Math.hypot(
        bounds.maxX - bounds.minX,
        bounds.maxZ - bounds.minZ,
    );
    const tallest = level.things.reduce(
        (most, thing) => Math.max(most, thing.height),
        0,
    );
    const highest = level.sectors.reduce(
        (most, sector) => Math.max(most, sector.ceilingHeight),
        0,
    );

    return Math.max(FAR_PLANE, across + tallest * 1.2 + highest + 10);
}

/**
 * @param  probe  The debug backdrop, if this is a debug run. It stands in for
 *                the background and takes the fog away with it: fog fades a
 *                leak towards the wall colour, which is the one thing that
 *                makes a sliver hard to be sure of.
 */
export function createView(
    level: Level,
    container: HTMLElement,
    probe: ProbeBackdrop | null,
): LevelView {
    const scene = new THREE.Scene();

    if (probe === null) {
        scene.background = new THREE.Color(BACKGROUND_COLOR);
        scene.fog = new THREE.Fog(BACKGROUND_COLOR, FOG_NEAR, FOG_FAR);
    } else {
        scene.background = probe.texture;
    }

    const camera = new THREE.PerspectiveCamera(
        FIELD_OF_VIEW,
        1,
        NEAR_PLANE,
        reachOf(level),
    );

    camera.rotation.order = 'YXZ';

    // The game draws at the size it is shown at.
    //
    // It used to render into a buffer a third of the canvas across and blow it
    // up, which is nine times fewer pixels and a deliberate look — but the look
    // was set when the textures were about 128 texels to the metre against
    // roughly 117 screen pixels to the metre, near enough one to one. It is not
    // a style so much as a match that no longer holds, and it throws away
    // exactly the high-frequency detail the lighting work is about to add:
    // lightmaps are indifferent to screen resolution, but a normal map's relief
    // is per-pixel and a third-resolution buffer discards it.
    const renderer = new THREE.WebGLRenderer({
        // Antialiasing blends a one-pixel sliver into its neighbours, and a
        // blended colour matches nothing in the legend. Debug wants the hard
        // edges, and the buffer kept so a frame can be read back.
        antialias: probe === null,
        preserveDrawingBuffer: probe !== null,
        // Depth kept as a logarithm rather than spread evenly. The far plane
        // opens up as far as a level asks — somebody a thousand metres tall
        // wants a thousand metres of it — and spread evenly there is not enough
        // left over to tell two walls a centimetre apart from each other. It
        // costs the early depth test, which is a fair price for walls that do
        // not shimmer.
        logarithmicDepthBuffer: true,
    });

    renderer.domElement.style.width = '100%';
    renderer.domElement.style.height = '100%';
    renderer.domElement.style.display = 'block';
    container.appendChild(renderer.domElement);

    const resize = (): void => {
        const { clientWidth, clientHeight } = container;

        if (clientWidth === 0 || clientHeight === 0) {
            return;
        }

        renderer.setSize(clientWidth, clientHeight, false);
        camera.aspect = clientWidth / clientHeight;
        camera.updateProjectionMatrix();
    };

    return {
        scene,
        camera,
        renderer,
        resize,
        dispose: () => renderer.dispose(),
    };
}
