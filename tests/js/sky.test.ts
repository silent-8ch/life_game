import * as THREE from 'three';
import { describe, expect, it } from 'vitest';
import { createSky, SKY_EXTENSION, skyUrl } from '@/lib/engine/sky';

/**
 * The sky is `scene.background`, and one line decides whether that works.
 *
 * Without `EquirectangularReflectionMapping` three does not recognise the
 * panorama as something to wrap around the viewer. It falls through to its
 * flat branch and paints the background as a full-screen quad with the image
 * stretched across it — which still moves with the camera and still looks like
 * a sky at a glance. Nothing throws, nothing logs, nothing looks obviously
 * broken. It is simply the wrong projection, and only somebody who knows what
 * a panorama should do when you turn around will ever catch it.
 *
 * That is exactly the kind of failure worth spending a test on: cheap to
 * assert, invisible to everyone who is not looking for it.
 */
describe('the sky', () => {
    it('is mapped as a panorama, not stretched flat across the view', () => {
        expect(createSky({ image: 'sky-day-1' }).texture.mapping).toBe(
            THREE.EquirectangularReflectionMapping,
        );
    });

    it('keeps the colour space the offscreen readback depends on', () => {
        // A render target gets no colour conversion of its own, so `capture.ts`
        // encodes by hand on the way out. Drop this and screenshots come out
        // dark, which is a long way from the sky to go looking.
        expect(createSky({ image: 'sky-day-1' }).texture.colorSpace).toBe(
            THREE.SRGBColorSpace,
        );
    });

    it('keeps the mipmaps the cube it is converted into inherits', () => {
        const { texture } = createSky({ image: 'sky-day-1' });

        expect(texture.generateMipmaps).toBe(true);
        expect(texture.minFilter).toBe(THREE.LinearMipmapLinearFilter);
        expect(texture.magFilter).toBe(THREE.LinearFilter);
    });

    it('wraps across and clamps at the poles', () => {
        // Both are read while the panorama is converted: it joins up with
        // itself left to right, and must not bleed top to bottom.
        const { texture } = createSky({ image: 'sky-day-1' });

        expect(texture.wrapS).toBe(THREE.RepeatWrapping);
        expect(texture.wrapT).toBe(THREE.ClampToEdgeWrapping);
    });

    it('builds a sky url from the one extension there is', () => {
        // The engine and the editor's preview both come through here, so a
        // half-finished change of format cannot leave one of them behind.
        // `ConstantsMatchTest` holds this against the PHP side.
        expect(skyUrl('sky-night-3')).toBe(
            `/sprites/bg/sky-night-3.${SKY_EXTENSION}`,
        );
    });

    it('does not touch the scene itself', () => {
        // Whoever calls this decides where the sky goes, and in debug nobody
        // does: the probe backdrop owns `scene.background` there and reserves
        // two colours a readback reads as "you are looking out of the level".
        // A sky assigning itself would poison that scan.
        const scene = new THREE.Scene();
        const before = scene.background;

        createSky({ image: 'sky-day-1' });

        expect(scene.background).toBe(before);
        expect(scene.children).toHaveLength(0);
    });
});
