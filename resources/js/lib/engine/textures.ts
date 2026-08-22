import * as THREE from 'three';
import { TEXTURE_METRES } from '@/lib/engine/constants';

/**
 * Textures are square, tileable and shared: one loaded image per name, with the
 * repeat baked into each surface's own UVs rather than into the texture, so the
 * same stone can cover a wall and a floor at the same scale.
 */

const TEXTURE_PATH = '/sprites/textures';

/** Frames across the water strip. */
const WATER_FRAMES = 4;
const WATER_FRAME_SECONDS = 0.18;

export type TextureLibrary = {
    /** A tiling surface texture, or null when the surface has none. */
    surface: (name: string | null) => THREE.Texture | null;
    /** The animated water sheet, already framed. */
    water: () => THREE.Texture;
    /**
     * Turn on anisotropic filtering, to whatever this card manages.
     *
     * Separate from building the library because the renderer does not exist
     * yet when the level is built, and reaching for it there is a use before
     * its declaration that types do not catch and that shows up as a blank
     * page.
     */
    useRenderer: (renderer: THREE.WebGLRenderer) => void;
    /** Advance anything that animates. */
    tick: (seconds: number) => void;
    dispose: () => void;
};

/**
 * How many samples a texture may be read with when it is seen edge-on.
 *
 * Every wall and floor in a first-person view is seen at a glancing angle
 * somewhere, and a glancing angle is the case ordinary mipmapping cannot do
 * anything sensible with: one screen pixel covers a long thin footprint in the
 * texture, and picking a single mip level for it is either far too blurred
 * along one axis or far too sharp along the other. Too sharp is what crawls —
 * the level chosen flips from pixel to pixel and again from frame to frame as
 * the player moves, and the surface shimmers along its length. It reads as a
 * flicker at the join between two rooms, because a join is where two surfaces
 * are both at their most glancing.
 *
 * Anisotropic filtering is the fix for exactly this, and it was never switched
 * on: three.js leaves it at 1, which is off. Asked for here and clamped to
 * whatever the card actually supports.
 */
const WANTED_ANISOTROPY = 16;

function retro(texture: THREE.Texture, anisotropy: number): THREE.Texture {
    texture.colorSpace = THREE.SRGBColorSpace;
    texture.magFilter = THREE.NearestFilter;
    texture.minFilter = THREE.LinearMipmapLinearFilter;
    texture.wrapS = THREE.RepeatWrapping;
    texture.wrapT = THREE.RepeatWrapping;
    texture.anisotropy = anisotropy;

    return texture;
}

export function createTextureLibrary(): TextureLibrary {
    // Settled once the renderer exists and can be asked what it manages, which
    // is after the level is built. Anything loaded before then is brought up to
    // it at that point.
    let anisotropy = 1;

    const loader = new THREE.TextureLoader();
    const loaded = new Map<string, THREE.Texture>();

    let waterTexture: THREE.Texture | null = null;
    let waterFrame = 0;
    let waterElapsed = 0;

    const surface = (name: string | null): THREE.Texture | null => {
        if (name === null) {
            return null;
        }

        const existing = loaded.get(name);

        if (existing !== undefined) {
            return existing;
        }

        const texture = retro(
            loader.load(`${TEXTURE_PATH}/${name}.png`),
            anisotropy,
        );
        loaded.set(name, texture);

        return texture;
    };

    const water = (): THREE.Texture => {
        if (waterTexture === null) {
            waterTexture = retro(
                loader.load('/sprites/bg/water-surface.png'),
                anisotropy,
            );
            waterTexture.wrapS = THREE.ClampToEdgeWrapping;
            waterTexture.wrapT = THREE.ClampToEdgeWrapping;
            waterTexture.repeat.set(1 / WATER_FRAMES, 1);
        }

        return waterTexture;
    };

    return {
        surface,
        water,

        useRenderer: (renderer) => {
            anisotropy = Math.min(
                WANTED_ANISOTROPY,
                renderer.capabilities.getMaxAnisotropy(),
            );

            for (const texture of [...loaded.values(), waterTexture]) {
                if (texture !== null && texture.anisotropy !== anisotropy) {
                    texture.anisotropy = anisotropy;
                    texture.needsUpdate = true;
                }
            }
        },

        tick: (seconds) => {
            if (waterTexture === null) {
                return;
            }

            waterElapsed += seconds;

            while (waterElapsed >= WATER_FRAME_SECONDS) {
                waterElapsed -= WATER_FRAME_SECONDS;
                waterFrame = (waterFrame + 1) % WATER_FRAMES;
                waterTexture.offset.x = waterFrame / WATER_FRAMES;
            }
        },

        dispose: () => {
            for (const texture of loaded.values()) {
                texture.dispose();
            }

            waterTexture?.dispose();
            loaded.clear();
        },
    };
}

/**
 * Rescale a surface's UVs so one tile of its texture covers TEXTURE_METRES,
 * whatever the size of the surface.
 */
export function tileUvs(
    geometry: THREE.BufferGeometry,
    acrossMetres: number,
    downMetres: number,
): void {
    const uv = geometry.getAttribute('uv');

    for (let index = 0; index < uv.count; index++) {
        uv.setXY(
            index,
            (uv.getX(index) * acrossMetres) / TEXTURE_METRES,
            (uv.getY(index) * downMetres) / TEXTURE_METRES,
        );
    }

    uv.needsUpdate = true;
}

/**
 * Floors and ceilings come out of the shape triangulator with their UVs in
 * metres already, so they only need scaling down to tile size.
 */
export function tileFlatUvs(geometry: THREE.BufferGeometry): void {
    const uv = geometry.getAttribute('uv');

    for (let index = 0; index < uv.count; index++) {
        uv.setXY(
            index,
            uv.getX(index) / TEXTURE_METRES,
            uv.getY(index) / TEXTURE_METRES,
        );
    }

    uv.needsUpdate = true;
}

/**
 * Tiles a wall built as an explicit quad, whose four corners carry their own
 * heights.
 *
 * `tileUvs` scales a PlaneGeometry's unit UVs by the wall's size, which only
 * works while every corner is the same height. A wall under a slope is a
 * trapezoid, and scaling it that way shears the texture with the top edge —
 * bricks leaning over as the ceiling rises.
 *
 * So V comes from each corner's own height in metres rather than from its share
 * of the wall, which keeps the courses level whatever the top is doing. U still
 * runs along the wall.
 *
 * @param  along  Each vertex's distance along the wall, in metres.
 * @param  up     Each vertex's height, in metres, in the same order.
 */
export function tileWallUvs(
    geometry: THREE.BufferGeometry,
    along: number[],
    up: number[],
): void {
    const uv = geometry.getAttribute('uv');

    for (let index = 0; index < uv.count; index++) {
        uv.setXY(
            index,
            along[index] / TEXTURE_METRES,
            up[index] / TEXTURE_METRES,
        );
    }

    uv.needsUpdate = true;
}
