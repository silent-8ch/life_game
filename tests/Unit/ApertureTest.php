<?php

use Symfony\Component\Process\Process;

/**
 * How far a chain of mirrors is followed, and what decides it.
 *
 * A pane used to recurse into whatever fell inside its camera's frustum. A
 * frustum is the whole screen, so in a room with four mirrored walls every pane
 * saw the other three at every level and the tree branched by three per bounce:
 * sixteen bounces asks for 43 million passes against a budget of ninety-six.
 * What the budget then decided was not how deep the room went but **which
 * branch got the depth**, and it decided by array order — so a perfectly
 * symmetric room came out with one wall deep and three shallow, which is what
 * Paul found with four captures ninety degrees apart from one spot.
 *
 * A pane is not a screen. It is a hole, and what can be seen through a hole is
 * bounded by the hole. These pin the arithmetic that says so.
 */

/**
 * @return array<string, mixed>
 */
function apertureAnswer(string $body): array
{
    $script = <<<JS
        const THREE = await import('three');
        const {
            anAperture,
            apertureOf,
            copyAperture,
            flipAcross,
            narrow,
            worthDrawing,
            WHOLE_SCREEN,
        } = await import('@/lib/engine/aperture.ts');

        /** hall-of-mirrors: an 8m square room, every wall a mirror. */
        const HALF = 4;
        const HEIGHT = 3;
        const WALLS = {
            north: [new THREE.Vector3(0, 1.5, -HALF), new THREE.Vector3(0, 0, 1)],
            south: [new THREE.Vector3(0, 1.5, HALF), new THREE.Vector3(0, 0, -1)],
            east: [new THREE.Vector3(HALF, 1.5, 0), new THREE.Vector3(-1, 0, 0)],
            west: [new THREE.Vector3(-HALF, 1.5, 0), new THREE.Vector3(1, 0, 0)],
        };

        const TURNED = new THREE.Matrix4().makeScale(-1, 1, 1);
        const made = {};

        for (const [name, [centre, normal]] of Object.entries(WALLS)) {
            const away = normal.dot(centre) * 2;
            const { x, y, z } = normal;
            const reflection = new THREE.Matrix4().set(
                1 - 2 * x * x, -2 * x * y, -2 * x * z, away * x,
                -2 * y * x, 1 - 2 * y * y, -2 * y * z, away * y,
                -2 * z * x, -2 * z * y, 1 - 2 * z * z, away * z,
                0, 0, 0, 1,
            );

            const geometry = new THREE.PlaneGeometry(2 * HALF, HEIGHT);
            geometry.computeBoundingBox();

            const mesh = new THREE.Mesh(geometry);
            mesh.position.copy(centre);
            mesh.rotation.y = Math.atan2(normal.x, normal.z);
            mesh.updateMatrixWorld(true);

            made[name] = { mesh, reflection };
        }

        /** How much of the screen is left at each step of a chain of walls. */
        const walk = (chain) => {
            const camera = new THREE.PerspectiveCamera(75, 1.6, 0.1, 200);
            camera.position.set(0.5, 1.6, 0.5);
            camera.updateMatrixWorld(true);
            camera.updateProjectionMatrix();

            let from = camera;
            let aperture = copyAperture(WHOLE_SCREEN, anAperture());
            const left = [];

            for (const name of chain) {
                const { mesh, reflection } = made[name];
                const rect = apertureOf(mesh, from, anAperture());
                const kept = rect === null
                    ? null
                    : narrow(aperture, rect, anAperture());

                if (kept === null || !worthDrawing(kept)) {
                    break;
                }

                left.push(
                    ((kept.right - kept.left) / 2) * ((kept.top - kept.bottom) / 2),
                );

                aperture = flipAcross(kept, anAperture());

                const out = new THREE.PerspectiveCamera(75, 1.6, 0.1, 200);
                out.matrixAutoUpdate = false;
                out.matrixWorldAutoUpdate = false;
                out.matrixWorld
                    .multiplyMatrices(reflection, from.matrixWorld)
                    .multiply(TURNED);
                out.matrixWorld.decompose(out.position, out.quaternion, out.scale);
                out.matrixWorldInverse.copy(out.matrixWorld).invert();
                out.projectionMatrix.copy(from.projectionMatrix);

                from = out;
            }

            return left;
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

it('lets two mirrors facing each other run the whole way down', function (): void {
    $answer = apertureAnswer(<<<'JS'
        const straight = Array.from(
            { length: 16 },
            (_, at) => (at % 2 === 0 ? 'north' : 'south'),
        );

        process.stdout.write(JSON.stringify({ left: walk(straight) }));
        JS);

    // A corridor is the case made entirely of depth, and the opening test must
    // never be the thing that ends one. Two mirrors facing each other across a
    // room lose a little of the opening per bounce and never lose all of it,
    // so the chain runs until `PORTAL_BOUNCES` stops it and not before.
    expect($answer['left'])->toHaveCount(16);

    // Shrinking, and monotonically. A chain whose opening grows is a chain that
    // has lost track of which way it is going.
    $previous = 1.0;

    foreach ($answer['left'] as $share) {
        expect($share)->toBeLessThan($previous);
        $previous = $share;
    }
});

it('closes a chain that turns a corner twice', function (): void {
    $answer = apertureAnswer(<<<'JS'
        process.stdout.write(JSON.stringify({
            cornering: walk(['north', 'east', 'north', 'east', 'north', 'east']),
            zigzag: walk(['north', 'south', 'east', 'west', 'north', 'south']),
        }));
        JS);

    // This is the whole reason a room of four mirrors could not be made deep.
    // A mirror off to one side is a sliver through the first bounce and nothing
    // through the second, so the chain ends itself — and the passes it was
    // going to cost go to the corridor instead. Same panes, same budget, and
    // the depth lands where the picture is rather than where the array order
    // sent it.
    expect(count($answer['cornering']))->toBeLessThan(4);
    expect(count($answer['zigzag']))->toBeLessThan(5);
});

it('treats a pane it cannot measure as covering everything', function (): void {
    $answer = apertureAnswer(<<<'JS'
        const camera = new THREE.PerspectiveCamera(75, 1.6, 0.1, 200);
        camera.updateMatrixWorld(true);
        camera.updateProjectionMatrix();

        const whole = apertureOf({}, camera, anAperture());

        process.stdout.write(JSON.stringify({
            left: whole.left,
            right: whole.right,
            bottom: whole.bottom,
            top: whole.top,
        }));
        JS);

    // Nothing to measure is not the same as nothing there. Too generous costs
    // a pass; too mean loses a reflection that was really there, and loses it
    // silently — so a surface that cannot be measured is never culled by the
    // measurement.
    expect($answer)->toBe([
        'left' => -1,
        'right' => 1,
        'bottom' => -1,
        'top' => 1,
    ]);
});

it('flips the opening for a mirror, because its target is drawn flipped',
    function (): void {
        $answer = apertureAnswer(<<<'JS'
            const right = { left: 0.2, right: 0.8, bottom: -0.5, top: 0.5 };

            process.stdout.write(JSON.stringify({
                flipped: flipAcross(right, anAperture()),
            }));
            JS);

        // A mirror's camera carries a left-for-right turn so that its basis
        // stays right-handed — without it three culls every single-sided
        // surface in the level inside out. The picture it draws is therefore
        // the reflected room flipped, and the pane's shader flips it back.
        //
        // So a rectangle on the right of the screen is on the left inside the
        // target. Miss this and every chain through a mirror hunts for its
        // reflections down the wrong side of the view, which prunes exactly the
        // branches that were really there and keeps the ones that were not.
        expect($answer['flipped'])->toBe([
            'left' => -0.8,
            'right' => -0.2,
            'bottom' => -0.5,
            'top' => 0.5,
        ]);
    });
