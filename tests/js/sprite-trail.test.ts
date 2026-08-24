import { describe, expect, it } from 'vitest';
import * as THREE from 'three';
import { createSpriteActor } from '@/lib/engine/sprite-actor';

/**
 * Drive an actor across a room for a number of frames at a given speed, moving
 * every frame, then report how its motion streak came out. The ghosts live in
 * the scene beside the body, so they are the scene's mesh children that are not
 * the body itself.
 */
function run(speed: number): { ghosts: number; visible: number; maxOpacity: number } {
    const scene = new THREE.Scene();
    const actor = createSpriteActor('paul-toon', 1.85, 'realistic');
    scene.add(actor.object);

    for (let i = 0; i < 40; i++) {
        const x = i * 0.5; // half a metre a frame — genuinely moving
        actor.place(x, 0, 0, 0, x, speed);
        actor.faceViewer(x, -5, 0); // a viewer in front of the path
    }

    const ghosts = scene.children.filter(
        (child): child is THREE.Mesh =>
            child !== actor.object && (child as THREE.Mesh).isMesh === true,
    );
    const visible = ghosts.filter((g) => g.visible);
    const opacities = visible.map(
        (g) => (g.material as THREE.MeshBasicMaterial).opacity,
    );

    return {
        ghosts: ghosts.length,
        visible: visible.length,
        maxOpacity: opacities.length ? Math.max(...opacities) : 0,
    };
}

describe('motion streak', () => {
    it('leaves a fading trail of ghosts when a character moves fast', () => {
        const fast = run(40);

        expect(fast.ghosts).toBe(8);
        expect(fast.visible).toBeGreaterThan(0);
        expect(fast.maxOpacity).toBeGreaterThan(0);
        expect(fast.maxOpacity).toBeLessThanOrEqual(0.5);
    });

    it('leaves no trail at a normal walking speed', () => {
        expect(run(4).visible).toBe(0);
    });
});
