import * as THREE from 'three';
import { BACKDROP_LAG, SKY_RADIUS } from '@/lib/engine/constants';
import type { Sky } from '@/types';

/**
 * The sky is a drum around the player: the gradient on the outside, and the
 * horizon layers stacked inside it. The whole thing follows the player so it
 * can never be reached — the trick Doom and Duke Nukem both used — while the
 * horizon layers trail a little way behind, which is what gives them parallax.
 */

const BACKDROP_PATH = '/sprites/bg';

/** The sky strips hold four variants side by side. */
const SKY_VARIANTS = 4;

/** How many times a horizon strip wraps around its band. */
const BACKDROP_WRAPS = 3;

/** Each layer sits a little inside the last, and slides a little faster. */
const LAYER_INSET = 0.06;

/**
 * A horizon layer is a band standing on the horizon. The artwork has its
 * silhouette along the bottom of the strip, so the band starts at eye level and
 * rises, which puts the hills above the garden wall rather than behind it.
 */
const LAYER_HEIGHT = 0.62;

/** How far a layer dips below the horizon, so it never floats. */
const LAYER_DROP = 0.08;

export type SkyDome = {
    object: THREE.Object3D;
    /** Keep the sky centred on the player and slide the layers as they walk. */
    follow: (x: number, y: number, z: number) => void;
    dispose: () => void;
};

function drum(
    radius: number,
    height: number,
    centreY: number,
    material: THREE.Material,
): THREE.Mesh {
    const geometry = new THREE.CylinderGeometry(
        radius,
        radius,
        height,
        48,
        1,
        true,
    );

    const mesh = new THREE.Mesh(geometry, material);
    mesh.position.y = centreY;

    return mesh;
}

export function createSky(sky: Sky): SkyDome {
    const loader = new THREE.TextureLoader();
    const object = new THREE.Group();

    const geometries: THREE.BufferGeometry[] = [];
    const materials: THREE.Material[] = [];
    const textures: THREE.Texture[] = [];
    const lagging: { band: THREE.Object3D; lag: number }[] = [];

    const gradient = loader.load(`${BACKDROP_PATH}/${sky.image}.png`);
    gradient.colorSpace = THREE.SRGBColorSpace;
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
    // One cell of the strip, wrapped once around the whole sky.
    gradient.repeat.set(1 / SKY_VARIANTS, 1);
    gradient.offset.x = sky.variant / SKY_VARIANTS;
    textures.push(gradient);

    const gradientMaterial = new THREE.MeshBasicMaterial({
        map: gradient,
        side: THREE.BackSide,
        fog: false,
        depthWrite: false,
    });
    materials.push(gradientMaterial);

    // A sphere rather than a drum, so there is no hole to see when looking up.
    const dome = new THREE.Mesh(
        new THREE.SphereGeometry(SKY_RADIUS, 32, 16),
        gradientMaterial,
    );
    geometries.push(dome.geometry);
    object.add(dome);

    if (sky.theme !== null) {
        sky.layers.forEach((layer, index) => {
            const radius = SKY_RADIUS * (1 - LAYER_INSET * (index + 1));
            const texture = loader.load(
                `${BACKDROP_PATH}/${sky.theme}_${layer}.png`,
            );

            texture.colorSpace = THREE.SRGBColorSpace;
            texture.wrapS = THREE.RepeatWrapping;
            texture.wrapT = THREE.ClampToEdgeWrapping;
            texture.repeat.set(BACKDROP_WRAPS, 1);
            textures.push(texture);

            const material = new THREE.MeshBasicMaterial({
                map: texture,
                side: THREE.BackSide,
                transparent: true,
                depthWrite: false,
                fog: false,
            });
            materials.push(material);

            // Nearer layers stand taller on the horizon and drift faster.
            const height = radius * LAYER_HEIGHT * (1 + index * 0.12);
            const band = drum(
                radius,
                height,
                height * (0.5 - LAYER_DROP),
                material,
            );

            geometries.push(band.geometry);
            object.add(band);

            // The nearer the layer, the further it falls behind the player.
            lagging.push({ band, lag: BACKDROP_LAG * (index + 1) });
        });
    }

    // The sky is scenery, never something to bump into or draw over.
    object.renderOrder = -1;
    object.traverse((child) => {
        child.frustumCulled = false;
        child.raycast = () => undefined;
    });

    return {
        object,

        follow: (x, y, z) => {
            // The sky itself is infinitely far off: it goes exactly where the
            // player goes, so walking never changes it and turning pans across
            // it. Only the horizon layers are close enough to have parallax,
            // and they get it by trailing slightly behind — which spreads them
            // as you walk towards them rather than sliding them sideways.
            object.position.set(x, y, z);

            for (const layer of lagging) {
                layer.band.position.x = -x * layer.lag;
                layer.band.position.z = -z * layer.lag;
            }
        },

        dispose: () => {
            for (const geometry of geometries) {
                geometry.dispose();
            }

            for (const material of materials) {
                material.dispose();
            }

            for (const texture of textures) {
                texture.dispose();
            }
        },
    };
}
