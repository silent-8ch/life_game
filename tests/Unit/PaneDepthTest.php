<?php

use Symfony\Component\Process\Process;

/**
 * How deep the panes go, and that nothing but the room decides it.
 *
 * There is no draw budget. What bounds a frame is `aperture.ts` — a branch ends
 * where its reflection stops overlapping the one showing it — with
 * `PORTAL_BOUNCES` as a backstop the geometry reaches first. Paul made that
 * call: *what happens when we remove the budget? safety for the engine should
 * be the level designer's job.*
 *
 * ## Why the two budgets before it both had to go
 *
 * A running count of passes, spent depth-first, is an **ordering** and not a
 * budget: the corridor is walked first and drills to the bottom, and the
 * branches beside it meet an empty purse and draw a room with no mirrors in it.
 * *Many mirrors straight ahead, but reflections to the side showing as walls* —
 * 8 of the 12 passes at the first bounce rendered bare walls.
 *
 * One depth for the whole frame, moved between frames to hold the cost near a
 * target, fixes that unfairness and buys a swing instead. Every chain ends at
 * that depth, so moving it moves every ending at once, and a wall blinks on and
 * off at the back of every reflection in the room together. *The walls
 * flicker*; and once that was slowed down, *the walls still flicker when the
 * user is not moving*, because a controller that can climb back to a depth it
 * has already failed at oscillates whatever its patience.
 *
 * ## What these two assert, and why they burn real time to do it
 *
 * The panes here cost real milliseconds, because the fault this file exists to
 * catch is invisible without it. An earlier measurement of the standing case
 * found **zero** movement over 239 frames and was worthless: its stub panes
 * rendered nothing, so a pass cost no time, so the clock the controller read
 * never moved. It measured a machine on which no budget can ever bind, which is
 * not a machine anybody plays on, and Paul's report beat it.
 *
 * So the cost is swung by a factor of forty here, and what is asserted is that
 * the depth does not notice.
 */

/**
 * @return array<string, mixed>
 */
function paneDepth(string $body): array
{
    $script = <<<JS
        const THREE = await import('three');
        const { prepareReflections } = await import('@/lib/engine/reflections.ts');

        const TURNED = new THREE.Matrix4().makeScale(-1, 1, 1);
        const SIDES = 4;
        const RADIUS = 4;
        const HEIGHT = 3;

        // A deterministic wobble, so a run can be compared with the last one.
        let seed = 12345;
        const wobble = () => {
            seed = (seed * 1103515245 + 12345) & 0x7fffffff;

            return seed / 0x7fffffff;
        };

        /** What a pass costs, standing in for a GPU that is really there. */
        let costPerPass = 0;
        const burn = (ms) => {
            const until = performance.now() + ms;

            while (performance.now() < until) {
                // Deliberately nothing.
            }
        };

        const log = [];

        const panes = Array.from({ length: SIDES }, (_, at) => {
            const turn = (2 * Math.PI * at) / SIDES;
            const normal = new THREE.Vector3(-Math.sin(turn), 0, -Math.cos(turn));
            const across = 2 * RADIUS * Math.tan(Math.PI / SIDES);
            const centre = new THREE.Vector3(
                RADIUS * Math.sin(turn),
                HEIGHT / 2,
                RADIUS * Math.cos(turn),
            );

            const away = normal.dot(centre) * 2;
            const { x, y, z } = normal;
            const reflection = new THREE.Matrix4().set(
                1 - 2 * x * x, -2 * x * y, -2 * x * z, away * x,
                -2 * y * x, 1 - 2 * y * y, -2 * y * z, away * y,
                -2 * z * x, -2 * z * y, 1 - 2 * z * z, away * z,
                0, 0, 0, 1,
            );

            const geometry = new THREE.PlaneGeometry(across, HEIGHT);
            geometry.computeBoundingBox();
            geometry.computeBoundingSphere();

            const mesh = new THREE.Mesh(geometry, new THREE.MeshBasicMaterial());
            mesh.name = 'wall-' + at;
            mesh.position.copy(centre);
            mesh.rotation.y = Math.atan2(normal.x, normal.z);
            mesh.updateMatrixWorld(true);

            const beyond = new WeakMap();

            return {
                name: mesh.name,
                home: 'room',
                onto: ['room'],
                mirrored: true,
                partner: mesh,
                behind: [],
                blocking: [],
                image: [],
                facing: normal.clone(),
                bounds: new THREE.Sphere()
                    .copy(geometry.boundingSphere)
                    .applyMatrix4(mesh.matrixWorld),
                mesh,
                settle: () => {},
                release: () => {},
                hug: () => {},
                aim: (from) => {
                    let out = beyond.get(from);

                    if (out === undefined) {
                        out = new THREE.PerspectiveCamera(75, 1.6, 0.1, 200);
                        out.matrixAutoUpdate = false;
                        out.matrixWorldAutoUpdate = false;
                        beyond.set(from, out);
                    }

                    out.matrixWorld
                        .multiplyMatrices(reflection, from.matrixWorld)
                        .multiply(TURNED);
                    out.matrixWorld.decompose(out.position, out.quaternion, out.scale);
                    out.matrixWorldInverse.copy(out.matrixWorld).invert();
                    out.projectionMatrix.copy(from.projectionMatrix);
                    out.projectionMatrixInverse.copy(from.projectionMatrixInverse);
                    out.far = from.far;

                    return out;
                },
                render: (r, s, from, depth) => {
                    log.push(depth);
                    burn(costPerPass);
                },
                show: () => {},
                viewerAt: () => ({ x: 0, z: 0, yaw: 0 }),
                readable: () => null,
            };
        });

        // Paul's own spot and heading, to the decimal: the middle of the room,
        // equidistant from all four mirrors. **The camera never moves.**
        const camera = new THREE.PerspectiveCamera(75, 1.6, 0.1, 200);
        camera.rotation.order = 'YXZ';
        camera.rotation.y = (-64.16 * Math.PI) / 180;
        camera.rotation.x = (-2.39 * Math.PI) / 180;
        camera.position.set(0, 1.6, 0);
        camera.updateMatrixWorld(true);
        camera.updateProjectionMatrix();

        const nobody = { faceViewer: () => {}, object: { visible: false } };
        const frame = prepareReflections(
            panes,
            [],
            nobody,
            { faceViewer: () => {} },
            { faceViewer: () => {} },
            camera,
            null,
        );

        /** Runs frames at a given cost per pass and returns the depth of each. */
        const run = (frames, share) => {
            const depths = [];

            for (let n = 0; n < frames; n++) {
                log.length = 0;
                costPerPass = share * (0.85 + 0.3 * wobble());
                frame({}, {});
                depths.push(Math.max(...log));
            }

            return depths;
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

    $process->setTimeout(180);
    $process->mustRun();

    return json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR);
}

it('draws the same depth however long a frame takes', function (): void {
    $answer = paneDepth(<<<'JS'
        // Cheap frames, then frames costing forty times as much, then cheap
        // again. A machine falling off a cliff and climbing back on.
        const cheap = run(40, 0.002);
        const dear = run(40, 0.08);
        const back = run(40, 0.002);

        const all = [...cheap, ...dear, ...back];
        let changes = 0;

        for (let n = 1; n < all.length; n++) {
            if (all[n] !== all[n - 1]) {
                changes++;
            }
        }

        process.stdout.write(JSON.stringify({
            changes,
            cheap: cheap.at(-1),
            dear: dear.at(-1),
            back: back.at(-1),
        }));
        JS);

    // **Nothing about how long a frame took can reach how deep the next one
    // goes**, and that is the whole of why the flicker cannot return.
    //
    // Two budgets were tried before this. A running count spent depth-first is
    // an ordering rather than a budget, and gave *many mirrors straight ahead,
    // reflections to the side showing as walls*. One depth for the frame, moved
    // between frames to hold the cost near a target, gave *the walls flicker* —
    // and, once slowed down, *the walls still flicker when the user is not
    // moving*, because a controller that can climb back to a depth it has
    // already failed at oscillates whatever its patience.
    //
    // Paul: *what happens when we remove the budget? safety for the engine
    // should be the level designer's job.* This is that, asserted: the frame
    // cost swings by a factor of forty and the depth does not move at all.
    expect($answer['changes'])->toBe(0)
        ->and($answer['dear'])->toBe($answer['cheap'])
        ->and($answer['back'])->toBe($answer['cheap']);
});

it('reaches its full depth on the very first frame', function (): void {
    $answer = paneDepth(<<<'JS'
        const first = run(1, 0.001);
        const later = run(120, 0.001);

        process.stdout.write(JSON.stringify({
            first: first[0],
            later: later.at(-1),
        }));
        JS);

    // There is no ramp, because there is nothing to ramp. The controller that
    // used to climb a level at a time took fifteen seconds to reach the depth a
    // mirror room affords, and over that ramp bare wall covered a fifth of the
    // screen on the first frame and was still a twentieth after five seconds —
    // which is what a player actually looked at on walking in.
    expect($answer['first'])->toBe($answer['later'])
        ->and($answer['first'])->toBeGreaterThanOrEqual(8);
});
