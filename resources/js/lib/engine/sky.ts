import * as THREE from 'three';
import type { Sky } from '@/types';

/**
 * The sky is the scene's background: a direction, not a thing.
 *
 * It used to be a 90m sphere with the panorama on the inside, which meant it
 * could be walked towards and had to be teleported to whichever camera was
 * drawing — once a frame for the player and once for every one of the six
 * hundred-odd pane passes a mirrored room costs. That was `SkyDome.follow`, and
 * a whole rule in `.ai/rules/game.md` existed to say it must never be missed,
 * because a finite dome parked at the player is a ball of sky sitting off to
 * one side of a portal's camera.
 *
 * `scene.background` has no position at all. Three draws it from the camera's
 * orientation with the translation dropped, so it is right for every camera
 * without being told anything, and the rule stops existing rather than being
 * obeyed more carefully.
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

/**
 * The extension every sky file has. Written down once here and once in
 * `LevelAssets::SKY_EXTENSION`; `ConstantsMatchTest` holds the two together.
 */
export const SKY_EXTENSION = 'png';

/** Where a sky's art lives, for the engine and for the editor's preview. */
export function skyUrl(image: string): string {
    return `${BACKDROP_PATH}/${image}.${SKY_EXTENSION}`;
}

export type SkyBackdrop = {
    /**
     * Ready to hand to `scene.background` — but only once it has loaded. Until
     * then it has no height, and three has nothing to convert.
     */
    texture: THREE.Texture;
    /** Resolves with the texture once it is worth showing. */
    ready: Promise<THREE.Texture>;
    dispose: () => void;
};

export function createSky(sky: Sky): SkyBackdrop {
    const loader = new THREE.TextureLoader();

    let settle: (texture: THREE.Texture) => void = () => {};
    const ready = new Promise<THREE.Texture>((resolve) => {
        settle = resolve;
    });

    const panorama = loader.load(skyUrl(sky.image), (loaded) => settle(loaded));

    // **The line the whole thing hangs on.** Without it three sees a plain 2D
    // texture, falls through its cube branch, and paints the background as a
    // full-screen quad with the panorama stretched flat across it. That still
    // moves with the camera and still looks like a sky at a glance, so nothing
    // errors and nothing looks broken — it is simply wrong, in a way only
    // somebody who knows what a panorama should do will catch. With it, three
    // converts the equirectangular image into a cube map once, caches it, and
    // draws it the way a skybox is meant to be drawn.
    panorama.mapping = THREE.EquirectangularReflectionMapping;

    // Load-bearing twice over: it governs the one-time conversion to a cube,
    // and it governs the offscreen readback in `capture.ts`, which encodes by
    // hand because a render target gets no colour conversion of its own. Drop
    // it and screenshots come out dark.
    panorama.colorSpace = THREE.SRGBColorSpace;
    // Applied while the conversion samples this image: an equirectangular
    // panorama joins up with itself across, and must not bleed at the poles.
    panorama.wrapS = THREE.RepeatWrapping;
    panorama.wrapT = THREE.ClampToEdgeWrapping;
    // Smoothed, unlike everything else. The sprites and the wall textures are
    // drawn to be blocky and want their texels left alone; the sky is a
    // photograph of the sky, and stepping it into squares only makes it look
    // like a mistake. Nothing else in the picture is stepped now either — the
    // renderer draws at the size it is shown at — so this is no longer the odd
    // one out that it was.
    panorama.magFilter = THREE.LinearFilter;
    panorama.minFilter = THREE.LinearMipmapLinearFilter;
    panorama.generateMipmaps = true;

    return {
        texture: panorama,
        ready,

        // Disposing the source is enough. Three ties the cube it built to this
        // texture's lifetime and lets it go with it, so there is no second
        // thing to remember.
        dispose: () => {
            panorama.dispose();
        },
    };
}
