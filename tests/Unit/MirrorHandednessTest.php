<?php

use Symfony\Component\Process\Process;

/**
 * Which way round a mirror's camera is, and why the whole illusion rests on it.
 *
 * A reflection is left-handed. Built honestly, as `R · M`, the camera that
 * draws one has a determinant of −1, and three then reverses the winding of
 * every triangle in the pass without knowing it has: `WebGLRenderer` works out
 * `frontFaceCW` from the determinant of the **object** being drawn and never
 * looks at the camera's. Every single-sided material in the level is culled
 * inside out for the length of that pass.
 *
 * Almost everything this engine draws is `DoubleSide`, so almost everything
 * survived it and the fault looked like anything but culling. Three things did
 * not survive:
 *
 * - **the panes**, whose `ShaderMaterial` takes three's default of `FrontSide`.
 *   So a mirror's view held no mirrors, there was nothing for the next level to
 *   nest inside, and every mirror in the game showed exactly one bounce. Paul,
 *   four times over a day: *each mirror only shows what is on the direct other
 *   side of it, no recursion*.
 * - **the sky**, which is `BackSide` by construction and inverts to nothing.
 *   *eventually i see black.*
 * - **any prop drawn as a box**, which asks for no side at all.
 *
 * The fix is one more flip. Turning the camera left-for-right in its own x
 * makes the basis right-handed again, so nothing is culled; the picture it
 * draws is that same picture mirrored, and the pane reads it back flipped,
 * which is one subtraction in the fragment shader. These are the two halves of
 * that claim: that the handedness is fixed, and that the picture is otherwise
 * untouched.
 */

/**
 * @return array<string, mixed>
 */
function mirrorCamera(string $body, float $shift = 0): array
{
    $script = <<<JS
        const THREE = await import('three');
        const { buildMirrorPane } = await import(
            '@/lib/engine/build/mirrors.ts'
        );

        /** Only the parts of a build context a mirror actually reaches for. */
        const scene = { group: new THREE.Group(), targets: [], mirrors: [] };
        const ctx = {
            scene,
            materials: { track: (what) => what },
            topology: { seenFrom: () => ['hall'] },
        };

        // A wall across the far end of the room, facing back into it.
        // `shift` slides the whole room away from the world origin without
        // changing its shape or where the player stands in it.
        const shift = {$shift};
        const centre = new THREE.Vector3(shift, 1.5, -4 + shift);
        const normal = new THREE.Vector3(0, 0, 1);

        buildMirrorPane(
            ctx,
            { sector: { slug: 'hall' } },
            centre,
            normal,
            3,
            2.4,
        );

        const surface = scene.mirrors[0];

        // Standing off to one side and turned, so that nothing under test can
        // pass by symmetry alone.
        const player = new THREE.PerspectiveCamera(70, 16 / 9, 0.1, 200);
        player.position.set(0.7 + shift, 1.6, 1.2 + shift);
        player.rotation.set(-0.1, 0.25, 0.04);
        player.updateMatrixWorld(true);
        player.updateProjectionMatrix();

        const beyond = surface.aim(player);

        /** Where a world point lands on the screen, in 0..1 across and up. */
        const screenOf = (camera, point) => {
            const clip = new THREE.Vector4(point.x, point.y, point.z, 1)
                .applyMatrix4(camera.matrixWorldInverse)
                .applyMatrix4(camera.projectionMatrix);

            return [clip.x / clip.w * 0.5 + 0.5, clip.y / clip.w * 0.5 + 0.5];
        };

        /** The same, with the mirror's left-for-right turn taken back out. */
        const readOf = (camera, point) => {
            const at = screenOf(camera, point);

            return [1 - at[0], at[1]];
        };

        {$body}
        JS;

    $process = new Process([
        'node',
        '--experimental-strip-types',
        '--import',
        './tests/js/typescript-imports.mjs',
        '--input-type=module',
        '--eval',
        $script,
    ], dirname(__DIR__, 2));

    $process->mustRun();

    return json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR);
}

it('leaves a mirror camera right-handed, so nothing in the pass is culled', function (): void {
    $answer = mirrorCamera(<<<'JS'
        process.stdout.write(JSON.stringify({
            camera: beyond.matrixWorld.determinantAffine(),
            paneIsSingleSided: surface.mesh.material.side === THREE.FrontSide,
            reads: surface.mesh.material.uniforms.mirrored.value,
        }));
        JS);

    // The whole of the guard. A negative determinant here is three drawing
    // every front face as a back face for the length of the pass, and the panes
    // are the first thing to go — which is a mirror with no mirror in it.
    expect($answer['camera'])->toBeGreaterThan(0);

    // The pane material really is single-sided, so the flip above is load
    // bearing rather than belt and braces. If this ever becomes false the
    // comment explaining why the flip exists has gone stale, not the flip.
    expect($answer['paneIsSingleSided'])->toBeTrue();

    // And the shader is told to read back flipped. One without the other is a
    // mirror showing the room the wrong way round.
    expect($answer['reads'])->toBe(1);
});

it('turns the picture and changes nothing else about it', function (): void {
    $answer = mirrorCamera(<<<'JS'
        // Built here rather than imported: `R · M` is what the camera would be
        // without the turn, and the point is that the two agree.
        const away = normal.dot(centre) * 2;
        const { x, y, z } = normal;
        const reflection = new THREE.Matrix4().set(
            1 - 2 * x * x, -2 * x * y, -2 * x * z, away * x,
            -2 * y * x, 1 - 2 * y * y, -2 * y * z, away * y,
            -2 * z * x, -2 * z * y, 1 - 2 * z * z, away * z,
            0, 0, 0, 1,
        );

        const honest = new THREE.PerspectiveCamera();
        honest.matrixWorld.multiplyMatrices(reflection, player.matrixWorld);
        honest.matrixWorldInverse.copy(honest.matrixWorld).invert();
        honest.projectionMatrix.copy(beyond.projectionMatrix);

        // Scattered through the room, none of them on an axis of anything.
        const points = [
            new THREE.Vector3(0.3, 1.1, -1.4),
            new THREE.Vector3(-1.9, 0.4, -2.7),
            new THREE.Vector3(1.4, 2.2, -3.1),
            new THREE.Vector3(-0.6, 1.7, 0.5),
        ];

        process.stdout.write(JSON.stringify({
            apart: points.map((point) => {
                const read = readOf(beyond, point);
                const want = screenOf(honest, point);

                return Math.max(
                    Math.abs(read[0] - want[0]),
                    Math.abs(read[1] - want[1]),
                );
            }),
        }));
        JS);

    // Flipped back, the turned camera is the honest reflection to the last
    // figure a float carries. That is what makes the turn free: it buys the
    // handedness and costs nothing in the picture.
    foreach ($answer['apart'] as $apart) {
        expect($apart)->toBeLessThan(1e-9);
    }
});

it('lands a point on the mirror plane on the pixel the player sees it at', function (): void {
    $answer = mirrorCamera(<<<'JS'
        // On the wall itself, off to one side of its middle and below it.
        const onWall = new THREE.Vector3(-0.8, 0.9, -4);

        process.stdout.write(JSON.stringify({
            player: screenOf(player, onWall),
            read: readOf(beyond, onWall),
        }));
        JS);

    // The identity the screen-space read is built on: reflecting leaves the
    // mirror's own plane exactly where it was, so a point on it is drawn at the
    // same pixel by both cameras. That is why a pane may be sampled by where
    // its fragment lands rather than by projecting through the far camera, and
    // it is what keeps a mirror's edge from disagreeing with the wall it is set
    // into.
    expect($answer['read'][0])->toBeGreaterThan($answer['player'][0] - 1e-9)
        ->and($answer['read'][0])->toBeLessThan($answer['player'][0] + 1e-9)
        ->and($answer['read'][1])->toBeGreaterThan($answer['player'][1] - 1e-9)
        ->and($answer['read'][1])->toBeLessThan($answer['player'][1] + 1e-9);
});

it('stands the camera where it really is, and says so', function (): void {
    $answer = mirrorCamera(<<<'JS'
        const scale = new THREE.Vector3();
        beyond.matrixWorld.decompose(
            new THREE.Vector3(),
            new THREE.Quaternion(),
            scale,
        );

        process.stdout.write(JSON.stringify({
            says: beyond.position.toArray(),
            is: new THREE.Vector3()
                .setFromMatrixPosition(beyond.matrixWorld)
                .toArray(),
            scale: scale.toArray(),
        }));
        JS);

    // Three renders from matrixWorldInverse and never reads these, so nothing
    // fails loudly when they are wrong. `aim` reads the position to decide how
    // far off the wall it is standing, and `viewerAt` reads the quaternion to
    // turn every billboard in the pass.
    foreach ([0, 1, 2] as $axis) {
        expect(abs($answer['says'][$axis] - $answer['is'][$axis]))
            ->toBeLessThan(1e-9);
    }

    // And it comes apart cleanly, which is only true because the camera was
    // turned right-handed first. A reflection decomposes to a negative scale
    // and a quaternion that means nothing.
    foreach ($answer['scale'] as $along) {
        expect(abs($along - 1))->toBeLessThan(1e-9);
    }
});

it('draws the same mirror the same way wherever the room was built', function (): void {
    $here = mirrorCamera(<<<'JS'
        const plane = new THREE.Plane()
            .setFromNormalAndCoplanarPoint(normal, centre);
        const e = beyond.projectionMatrix.elements;
        const far = new THREE.Vector4(
            e[3] - e[2], e[7] - e[6], e[11] - e[10], e[15] - e[14],
        );

        process.stdout.write(JSON.stringify({
            off: Math.abs(plane.distanceToPoint(beyond.position)),
            far: Math.abs(far.w / Math.hypot(far.x, far.y, far.z)),
        }));
        JS);

    $faraway = mirrorCamera(<<<'JS'
        const plane = new THREE.Plane()
            .setFromNormalAndCoplanarPoint(normal, centre);
        const e = beyond.projectionMatrix.elements;
        const far = new THREE.Vector4(
            e[3] - e[2], e[7] - e[6], e[11] - e[10], e[15] - e[14],
        );

        process.stdout.write(JSON.stringify({
            off: Math.abs(plane.distanceToPoint(beyond.position)),
            far: Math.abs(far.w / Math.hypot(far.x, far.y, far.z)),
        }));
        JS, 40);

    // The same room, the same player, the same wall — moved forty metres from
    // the middle of the level. Nothing about the picture may depend on that,
    // and everything did: `aim` measured its distance to the wall from the
    // world origin, so this number was the room's own address rather than how
    // far the eye was standing off the glass.
    //
    // It decides two things. Under CLIP_MINIMUM the near plane is not tilted at
    // all and the wall behind the mirror is drawn across the view — the sky,
    // where a level has no room back there. Over it, the bias sets where the
    // far plane lands, and far enough out that collapses and the pane goes
    // black. Same room, some walls black and some showing sky, decided by
    // nothing but where it was built.
    expect(abs($here['off'] - $faraway['off']))->toBeLessThan(1e-9)
        ->and(abs($here['far'] - $faraway['far']))->toBeLessThan(1e-6);
});
