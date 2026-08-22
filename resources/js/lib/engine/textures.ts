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
    /** Advance anything that animates. */
    tick: (seconds: number) => void;
    dispose: () => void;
};

function retro(texture: THREE.Texture): THREE.Texture {
    texture.colorSpace = THREE.SRGBColorSpace;
    texture.magFilter = THREE.NearestFilter;
    texture.minFilter = THREE.LinearMipmapLinearFilter;
    texture.wrapS = THREE.RepeatWrapping;
    texture.wrapT = THREE.RepeatWrapping;

    return texture;
}

export function createTextureLibrary(): TextureLibrary {
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

        const texture = retro(loader.load(`${TEXTURE_PATH}/${name}.png`));
        loaded.set(name, texture);

        return texture;
    };

    const water = (): THREE.Texture => {
        if (waterTexture === null) {
            waterTexture = retro(loader.load('/sprites/bg/water-surface.png'));
            waterTexture.wrapS = THREE.ClampToEdgeWrapping;
            waterTexture.wrapT = THREE.ClampToEdgeWrapping;
            waterTexture.repeat.set(1 / WATER_FRAMES, 1);
        }

        return waterTexture;
    };

    return {
        surface,
        water,

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
