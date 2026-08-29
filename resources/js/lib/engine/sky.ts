import * as THREE from 'three';
import { SKY_RADIUS } from '@/lib/engine/constants';
import type { Sky } from '@/types';

/**
 * The sky is a dome around the player. The whole thing follows the player so it
 * can never be reached — the trick Doom and Duke Nukem both used — which is
 * what makes it read as infinitely far off rather than as a wall.
 *
 * One file is one sky, whole. The panoramas used to be packed four to a strip
 * and a cell slid onto the dome with `repeat`/`offset`, which assumed every sky
 * file held exactly four of them — so a single-image sky dropped into the
 * folder was cut into quarters and each quarter stretched around the whole sky.
 *
 * There were parallax horizon layers inside the dome once, too: bands of hills
 * or rooftops standing on the horizon, each trailing a little further behind
 * the player than the last. Paul's ruling was that they did not look good. The
 * art is in `public/sprites/bg/retired/layers`, and the `backdrop_theme` and
 * `backdrop_layers` columns still hold what levels were given; nothing reads
 * them.
 */

const BACKDROP_PATH = '/sprites/bg';

export type SkyDome = {
    object: THREE.Object3D;
    /** Keep the sky centred on whoever is looking. */
    follow: (x: number, y: number, z: number) => void;
    dispose: () => void;
};

export function createSky(sky: Sky): SkyDome {
    const loader = new THREE.TextureLoader();
    const object = new THREE.Group();

    const gradient = loader.load(`${BACKDROP_PATH}/${sky.image}.png`);
    gradient.colorSpace = THREE.SRGBColorSpace;
    // Wrapped across, because an equirectangular panorama joins up with
    // itself: the left edge and the right edge are the same direction.
    gradient.wrapS = THREE.RepeatWrapping;
    gradient.wrapT = THREE.ClampToEdgeWrapping;
    // Smoothed, unlike everything else. The sprites and the wall textures are
    // drawn to be blocky and want their texels left alone; the sky is a
    // photograph of the sky, and stepping it into squares only makes it look
    // like a mistake. Nothing else in the picture is stepped now either — the
    // renderer draws at the size it is shown at — so this is no longer the odd
    // one out that it was.
    gradient.magFilter = THREE.LinearFilter;
    gradient.minFilter = THREE.LinearMipmapLinearFilter;
    gradient.generateMipmaps = true;

    const gradientMaterial = new THREE.MeshBasicMaterial({
        map: gradient,
        side: THREE.BackSide,
        fog: false,
        depthWrite: false,
    });

    // A sphere rather than a drum, so there is no hole to see when looking up.
    const dome = new THREE.Mesh(
        new THREE.SphereGeometry(SKY_RADIUS, 32, 16),
        gradientMaterial,
    );
    object.add(dome);

    // The sky is scenery, never something to bump into or draw over.
    object.renderOrder = -1;
    object.traverse((child) => {
        child.frustumCulled = false;
        child.raycast = () => undefined;
    });

    return {
        object,

        // The sky is infinitely far off: it goes exactly where the viewer goes,
        // so walking never changes it and turning pans across it. It has to
        // follow whichever camera is drawing, not just the player — a pane pass
        // renders from somewhere else entirely, and a dome parked at the player
        // hangs a slab of sky across the middle of that view.
        follow: (x, y, z) => {
            object.position.set(x, y, z);
        },

        dispose: () => {
            dome.geometry.dispose();
            gradientMaterial.dispose();
            gradient.dispose();
        },
    };
}
