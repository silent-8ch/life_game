<?php

use Symfony\Component\Process\Process;

/**
 * How deep the panes go, and that it stops moving.
 *
 * `reach` is one number for the whole level, so every chain in it ends at the
 * same depth and moving that number moves **every** ending at once. The end of
 * a chain is where a mirror comes out of the picture, so what a moving `reach`
 * looks like is every reflection in the room changing together, twice a second,
 * for ever.
 *
 * ## The measurement this exists because of
 *
 * Paul, standing perfectly still in the middle of his four-mirror room: *the
 * walls still flicker when the user is not moving.* That contradicted a
 * measurement taken here which found **zero** movement over 239 frames with the
 * camera fixed — and the measurement was the thing that was wrong. Its stub
 * panes rendered nothing, so a pass cost no time, so the clock half of the
 * controller never fired at all and the only thing left that could move the
 * depth was the geometry. It measured a machine on which the budget can never
 * bind, which is not a machine anybody plays on.
 *
 * So the panes here **burn real time**, and the frame cost carries ordinary
 * noise on top — which is what a texture upload, a collection, or another tab
 * waking up does to a frame. With that and a camera that never moves at all,
 * the old controller swung between six levels and seven on six frames of 199.
 *
 * A controller that can climb back to a depth it has already failed at will
 * oscillate, and patience only sets the period. The fix is that it cannot: a
 * level given up on stops being on offer.
 */

/**
 * @return array<string, mixed>
 */
function paneDepth(string $body): array
{
    $script = <<<JS
        const THREE = await import('three');
        const { prepareReflections } = await import('@/lib/engine/reflections.ts');
        const { PANE_MILLISECONDS } = await import('@/lib/engine/constants.ts');

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

it('stops moving the depth once it has settled, on a camera that never moves',
    function (): void {
        $answer = paneDepth(<<<'JS'
            // A machine on which this room lands near the clock's allowance,
            // with ordinary frame-to-frame noise on top.
            run(220, PANE_MILLISECONDS / 120);

            const settled = run(200, PANE_MILLISECONDS / 120);

            let changes = 0;

            for (let n = 1; n < settled.length; n++) {
                if (settled[n] !== settled[n - 1]) {
                    changes++;
                }
            }

            process.stdout.write(JSON.stringify({
                changes,
                deepest: Math.max(...settled),
                shallowest: Math.min(...settled),
            }));
            JS);

        // Not "rarely" — never. Every chain in the level ends at this depth, so
        // one change is every reflection in the room changing together, and the
        // old controller did it about twice a second for ever.
        expect($answer['changes'])->toBe(0)
            ->and($answer['deepest'])->toBe($answer['shallowest']);

        // And it has not simply given up: a room of mirrors is worth several
        // bounces even on a machine that cannot afford many.
        expect($answer['shallowest'])->toBeGreaterThanOrEqual(3);
    });

it('climbs again when the room really does get cheaper', function (): void {
    $answer = paneDepth(<<<'JS'
        run(300, PANE_MILLISECONDS / 120);

        const dear = run(30, PANE_MILLISECONDS / 120);
        const cheap = run(900, PANE_MILLISECONDS / 1200);

        process.stdout.write(JSON.stringify({
            before: dear.at(-1),
            after: cheap.at(-1),
        }));
        JS);

    // A ceiling that only ever fell would hold a player who walks out of a hall
    // of mirrors and into a corridor at the hall's depth for the rest of the
    // level. It lifts on evidence that the room has changed — the cost under
    // half its allowance for three seconds running — which is nothing ordinary
    // noise can look like.
    expect($answer['after'])->toBeGreaterThan($answer['before']);
});
