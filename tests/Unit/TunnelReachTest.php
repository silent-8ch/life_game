<?php

use Symfony\Component\Process\Process;

/**
 * A tunnel walks its camera away from the room, and the far plane has to follow.
 *
 * Every bounce of a chain of reflections stands the camera one room-width
 * further out. In Paul's `2-mirrors-oposite` — eight metres across, mirrors on
 * two facing walls and nothing else — the camera is 96 m off at twelve levels
 * and 192 m at twenty-four, while the player's own `FAR_PLANE` is 100. Past
 * about twelve, everything the pass is meant to draw is **beyond its own far
 * plane** and cut away, and the far end of the tunnel dissolves.
 *
 * ## Why it took a two-mirror room to find
 *
 * He built one: *it only has two mirrors, each facing each other... i see the
 * same distortion as the facing portals.* That one sentence ruled out
 * everything else at once. A facing pair is a single chain going straight out —
 * no branching, no commuting pairs, no opening clipped by a third surface — and
 * a wrap-around portal is the same shape by a different mechanism. The four
 * mirror rooms this had been measured in all *branch*, so their deep chains
 * wander sideways rather than marching, and none of them travels far enough to
 * cross the line.
 *
 * It also only started showing once the draw budget went and the depth went
 * from about sixteen to the mid thirties, which took most of the tunnel past it.
 */

/**
 * @return array<string, mixed>
 */
function tunnelReach(string $body): array
{
    $script = <<<JS
        const THREE = await import('three');
        const { buildMirrorPane } = await import('@/lib/engine/build/mirrors.ts');
        const { FAR_PLANE } = await import('@/lib/engine/constants.ts');

        const scene = {
            group: new THREE.Group(),
            targets: [],
            mirrors: [],
            skyLids: [],
            drawnByRoom: new Map(),
        };
        const ctx = {
            scene,
            materials: { track: (what) => what },
            topology: { seenFrom: () => ['room'] },
        };

        /** Paul's room: 8 m across, mirrors facing each other and nothing else. */
        const wall = (z, facing) =>
            buildMirrorPane(
                ctx,
                { sector: { slug: 'room' } },
                new THREE.Vector3(0, 1.5, z),
                new THREE.Vector3(0, 0, facing),
                8,
                3,
            );

        const near = wall(-4, 1);
        const far = wall(4, -1);

        const camera = new THREE.PerspectiveCamera(75, 1.6, 0.05, FAR_PLANE);
        camera.rotation.order = 'YXZ';
        camera.position.set(-0.19, 1.6, -0.32);
        camera.updateMatrixWorld(true);
        camera.updateProjectionMatrix();

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

it('keeps the room inside the far plane however deep the tunnel goes',
    function (): void {
        $answer = tunnelReach(<<<'JS'
            const reached = [];
            let from = camera;

            for (let depth = 1; depth <= 26; depth++) {
                const pane = depth % 2 === 1 ? near : far;
                const beyond = pane.aim(from);

                // How far this camera stands from the room, and how far it can
                // see. The room is 8 m across, so its far side is `away + 8`.
                const away = Math.abs(beyond.position.z) - 4;

                reached.push({
                    depth,
                    away: Number(away.toFixed(1)),
                    canSee: Number(beyond.far.toFixed(1)),
                });

                from = beyond;
            }

            process.stdout.write(JSON.stringify({
                reached,
                shortest: Math.min(...reached.map((r) => r.canSee - (r.away + 8))),
            }));
            JS);

        // Every level can see past the far side of the room it is drawing, with
        // the whole of the player's own view to spare. Before this, a camera
        // 128 m out was still trying to see 100 m and drawing nothing.
        expect($answer['shortest'])->toBeGreaterThan(80.0);

        // And the marching is real rather than a quirk of one level: the camera
        // is genuinely hundreds of metres out by the end.
        $last = end($answer['reached']);

        expect($last['away'])->toBeGreaterThan(150.0);
    });

it('leaves the picture where it was, changing only how far it reaches',
    function (): void {
        $answer = tunnelReach(<<<'JS'
            const beyond = near.aim(camera);

            // The screen-space read rests on both cameras sharing one
            // projection across x and y. Only the third row of a perspective
            // projection depends on the near and far planes, so rebuilding it
            // with a longer reach must leave the first two rows alone.
            const mine = beyond.projectionMatrix.elements;
            const theirs = camera.projectionMatrix.elements;

            const rows = [0, 1].map((row) =>
                [0, 1, 2, 3].map((column) =>
                    Number(
                        Math.abs(
                            mine[column * 4 + row] - theirs[column * 4 + row],
                        ).toFixed(9),
                    ),
                ),
            );

            process.stdout.write(JSON.stringify({
                biggestDrift: Math.max(...rows.flat()),
                reachesFurther: beyond.far > camera.far,
            }));
            JS);

        // Not "close" — the same. If x or y ever moves, every pane in the game
        // reads its target at the wrong place, and the failure would look like
        // reflections sliding rather than like anything to do with distance.
        expect($answer['biggestDrift'])->toBe(0)
            ->and($answer['reachesFurther'])->toBeTrue();
    });
