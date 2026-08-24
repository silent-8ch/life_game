<?php

use Symfony\Component\Process\Process;

/**
 * The rule the whole renderer keeps, asserted in the room that broke it.
 *
 * **No pane ever shows a picture taken from a camera other than the one looking
 * at it.**
 *
 * That sounds like a tautology and is not, because of how a pane reads its
 * target. It samples by the fragment's own **screen position** — not through a
 * texture matrix — which is exact when the picture was drawn from the viewpoint
 * now looking at it and is garbage otherwise. Garbage that looks like a room,
 * which is the trap: a view of the same level from somewhere else, pasted onto
 * a wall at the wrong angle. Down a corridor of portals two adjacent viewpoints
 * are nearly the same picture and it passes. Between two mirrors at right
 * angles it does not, and Paul's word for it both times was *super stretched*.
 *
 * Three separate things used to break it, all of them in `hall-of-mirrors` —
 * an 8m square room with four mirrored walls, which is the level he drew to
 * find them:
 *
 * 1. Every pane in the level was shown one level in, whether or not this pass
 *    had drawn it there. A pane it had not drawn still held a picture at that
 *    depth from some other chain.
 * 2. At the last bounce a mirror was handed the level *above* it, deliberately,
 *    so the image would fold into itself. That picture is one reflection
 *    further out.
 * 3. `readable()` fell back to the nearest depth that had ever been drawn,
 *    which is a different camera again.
 *
 * Measured over one frame from the middle of that room, before: 6 nestings
 * wrong, 161 showings of a depth nothing had ever drawn, and 219 pastings of
 * the level above at the last bounce. After: 228 right, 0 wrong, 0 blank, at
 * all sixteen levels in all four directions.
 */

/**
 * Runs one frame in a square room of mirrors and audits every showing.
 *
 * The panes are stubs, but the cameras and the geometry are real: `aim` builds
 * the true reflection matrix a mirror uses, including the left-for-right turn
 * that keeps its basis right-handed, and each pane keeps one output camera per
 * incoming camera exactly as `portal-surface.ts` does. Without that last part a
 * chain revisiting a pane clobbers its own outer level's viewpoint, and the
 * audit measures the stub instead of the engine.
 *
 * @param  list<array{0: float, 1: float, 2: float}>  $walls  centre and normal per wall
 * @return array<string, mixed>
 */
function mirrorRoomFrame(int $sides): array
{
    $script = <<<JS
        const THREE = await import('three');
        const { prepareReflections } = await import('@/lib/engine/reflections.ts');

        const SIDES = {$sides};
        const RADIUS = 4;
        const HEIGHT = 3;

        const TURNED = new THREE.Matrix4().makeScale(-1, 1, 1);

        const log = [];
        const shown = new Map();

        /**
         * Cameras are compared by **identity**, not by their numbers.
         *
         * Recomputing `reflection * from * TURNED` and comparing matrices was
         * tried and is a trap: `aim` decomposes and re-inverts, so sixteen
         * levels down a chain the same camera differs from a recomputation of
         * itself in the third decimal. That reads as ten wrong reflections in
         * an octagon and there is nothing wrong with them. Which camera object
         * a pass aimed is exact, and is the question actually being asked.
         */
        let next = 0;
        const names = new WeakMap();
        const tag = (camera) => {
            let name = names.get(camera);

            if (name === undefined) {
                name = 'camera-' + next++;
                names.set(camera, name);
            }

            return name;
        };

        /** Every camera any pane ever aimed, and what it was aimed from. */
        const aimed = [];

        // A regular polygon of mirrors. Four sides is Paul's hall-of-mirrors;
        // eight is the octagon he asked for next, and the reason the answer had
        // to be geometry rather than a special case for facing pairs.
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
            const beyondFor = (from) => {
                const held = beyond.get(from);

                if (held !== undefined) {
                    return held;
                }

                const camera = new THREE.PerspectiveCamera(75, 1.6, 0.1, 200);
                camera.matrixAutoUpdate = false;
                camera.matrixWorldAutoUpdate = false;
                beyond.set(from, camera);

                return camera;
            };

            const pane = {
                name: mesh.name,
                home: 'room',
                onto: ['room'],
                mirrored: true,
                partner: mesh,
                behind: [],
                blocking: [],
                facing: normal.clone(),
                bounds: new THREE.Sphere()
                    .copy(geometry.boundingSphere)
                    .applyMatrix4(mesh.matrixWorld),
                mesh,
                settle: () => {},
                release: () => {},
                hug: () => {},
                aim: (from) => {
                    const out = beyondFor(from);

                    out.matrixWorld
                        .multiplyMatrices(reflection, from.matrixWorld)
                        .multiply(TURNED);
                    out.matrixWorld.decompose(out.position, out.quaternion, out.scale);
                    out.matrixWorldInverse.copy(out.matrixWorld).invert();
                    out.projectionMatrix.copy(from.projectionMatrix);
                    out.projectionMatrixInverse.copy(from.projectionMatrixInverse);
                    out.far = from.far;

                    aimed.push({
                        pane: mesh.name,
                        from: tag(from),
                        out: tag(out),
                    });

                    return out;
                },
                render: (r, s, from, depth) => {
                    log.push({
                        name: mesh.name,
                        depth,
                        from: tag(from),
                        // Only the panes actually in the picture. A hidden
                        // pane's last shown level is bookkeeping, not
                        // something on screen.
                        showing: panes
                            .filter((o) => o.mesh.visible && shown.has(o.name))
                            .map((o) => o.name + '@' + shown.get(o.name)),
                    });
                },
                show: (depth) => { shown.set(mesh.name, depth); },
                viewerAt: () => ({ x: 0, z: 0, yaw: 0 }),
                readable: () => null,
                reflect: reflection,
            };

            return pane;
        });

        const camera = new THREE.PerspectiveCamera(75, 1.6, 0.1, 200);
        camera.position.set(0.5, 1.6, 0.5);
        camera.updateMatrixWorld(true);
        camera.updateProjectionMatrix();

        const nobody = { faceViewer: () => {}, object: { visible: false } };

        prepareReflections(
            panes,
            [],
            nobody,
            { faceViewer: () => {} },
            { faceViewer: () => {} },
            camera,
            null,
        )({}, {});

        // Every showing, against the viewpoint it is being seen from.
        const written = new Map();
        let right = 0;
        let wrong = 0;
        let blank = 0;

        for (const drew of log) {
            // The camera this pass aimed to draw what is inside it.
            const mine = aimed
                .filter((a) => a.pane === drew.name && a.from === drew.from)
                .pop();

            for (const token of drew.showing) {
                if (token.startsWith(drew.name + '@')) {
                    continue;
                }

                const source = written.get(token);

                if (source === undefined) {
                    blank++;
                } else if (mine !== undefined && source === mine.out) {
                    right++;
                } else {
                    wrong++;
                }
            }

            written.set(drew.name + '@' + drew.depth, drew.from);
        }

        const depths = new Map();

        for (const drew of log) {
            depths.set(drew.name, Math.max(depths.get(drew.name) ?? 0, drew.depth));
        }

        process.stdout.write(JSON.stringify({
            draws: log.length,
            right,
            wrong,
            blank,
            deepest: [...depths.values()],
        }));
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

it('never shows a reflection taken from another viewpoint', function (): void {
    $answer = mirrorRoomFrame(4);

    // The whole rule, in the room that broke it. `wrong` is a picture drawn
    // from one camera pasted onto a wall being looked at from another, which is
    // what *super stretched* was; `blank` is a pane shown at a depth nothing
    // ever drew, which is a black wall.
    expect($answer['wrong'])->toBe(0)
        ->and($answer['blank'])->toBe(0)
        ->and($answer['right'])->toBeGreaterThan(100);
});

it('sends every wall of a square room the same distance', function (): void {
    $answer = mirrorRoomFrame(4);

    // A perfectly symmetric room has to degrade symmetrically. It used to be
    // decided by position in an array: one pane took the depth-first budget and
    // the other three met an exhausted purse, so one wall looked right and
    // three did not. Paul found that with four captures ninety degrees apart
    // from one spot, and no geometry can produce it.
    expect(array_unique($answer['deepest']))->toHaveCount(1);
    expect($answer['deepest'][0])->toBeGreaterThanOrEqual(8);
});

it('holds an octagon of mirrors to the same rule', function (): void {
    $answer = mirrorRoomFrame(8);

    // Paul, when the square room was still broken: *what about an octagon room
    // with mirrors? Our solution needs to be robust.* An octagon has no facing
    // pairs at all — every wall is at 45 degrees to its neighbours — so nothing
    // that special-cases a corridor can help it. Measuring the opening does not
    // care: it is the same arithmetic on a different set of normals.
    expect($answer['wrong'])->toBe(0)
        ->and($answer['blank'])->toBe(0)
        ->and($answer['right'])->toBeGreaterThan(0);
});
