<?php

use Symfony\Component\Process\Process;

/**
 * What a portal pane does when the eye is nearly touching it.
 *
 * A mouth builds no wall. The pane standing in for one is the only thing in the
 * opening, so anything that stops the pane being drawn shows the sky — and the
 * near plane stops it being drawn at exactly the range where a player is about
 * to walk through. `hug()` is the answer to that, and this pins the shape of
 * the answer.
 *
 * ISSUE-101 is what it got wrong. The pane used to be squared up to the screen
 * and blown up to cover it, which is right when a mouth is straight ahead and
 * filling the view anyway, and wrong the moment somebody stands beside the
 * opening and looks along it — most of that view is the near room. Paul: *"i
 * pass through fine, i only see this bug if i am perpendicularish to the
 * portal"*. It reached forty per cent of the screen in both levels that have a
 * wall-length mouth, and the boundary landed on the same pixel column in both,
 * which is what gave it away: a fault at a fixed screen position, in two levels
 * with nothing geometric in common, is the camera's doing and not the level's.
 */

/**
 * @return array<string, mixed>
 */
function hugAnswer(string $body): array
{
    $script = <<<JS
        const blank = () => ({
            width: 0,
            height: 0,
            style: {},
            addEventListener() {},
            removeEventListener() {},
            getContext: () => null,
        });

        globalThis.document = { createElementNS: blank, createElement: blank };

        const THREE = await import('three');
        const { buildLevel } = await import('@/lib/engine/build-level.ts');
        const { createTextureLibrary } = await import('@/lib/engine/textures.ts');
        const {
            FIELD_OF_VIEW,
            NEAR_PLANE,
            PANE_CLEARANCE,
        } = await import('@/lib/engine/constants.ts');

        const corner = (x, z, extra = {}) => ({
            x, z, blocks: false, wallTexture: null, isMirror: false,
            isSky: false, portalLink: null, ...extra,
        });

        const room = (slug, points) => ({
            slug, name: slug, floorHeight: 0, ceilingHeight: 3,
            floorTexture: null, ceilingTexture: null, wallTexture: null,
            isSky: false, isWater: false, points,
        });

        // A wrap-around: the whole east wall of the room comes out of the whole
        // west wall of it. The shape both levels that showed the fault have,
        // and the shape that makes "beside the opening" and "in the opening"
        // the same place.
        const level = {
            slug: 'test', name: 'Test', description: '',
            spawn: { x: 4, z: 4, angle: 0 }, ceilingHeight: 3,
            spriteStyle: 'realistic', playerSprite: 'paul',
            wallColor: '#ffffff', floorColor: '#888888', accentColor: '#ffcc00',
            sky: null, things: [],
            sectors: [
                room('hall', [
                    corner(0, 0),
                    corner(8, 0, { portalLink: 'wrap' }),
                    corner(8, 8),
                    corner(0, 8, { portalLink: 'wrap' }),
                ]),
            ],
        };

        const built = buildLevel(level, createTextureLibrary());
        built.group.updateMatrixWorld(true);

        for (const pane of built.portals) {
            pane.settle();
        }

        /** The pane whose mouth stands in the x = 8 wall. */
        const eastward = built.portals.find(
            (pane) => pane.mesh.position.x > 4,
        );

        const camera = new THREE.PerspectiveCamera(
            FIELD_OF_VIEW,
            894 / 502,
            NEAR_PLANE,
            100,
        );
        camera.rotation.order = 'YXZ';

        /** Stands the camera somewhere, facing a yaw in degrees. */
        const stand = (x, z, yawDegrees) => {
            camera.position.set(x, 1.5, z);
            camera.rotation.y = THREE.MathUtils.degToRad(yawDegrees);
            camera.rotation.x = 0;
            camera.updateMatrixWorld(true);
        };

        /** Where a world point lands on screen, in half-widths from the middle. */
        const onScreen = (point) => {
            const at = point.clone().project(camera);

            return { x: Number(at.x.toFixed(3)), y: Number(at.y.toFixed(3)) };
        };

        /** The four corners of a pane, in the world, as it stands right now. */
        const cornersOf = (pane) => {
            const box = pane.mesh.geometry.boundingBox
                ?? (pane.mesh.geometry.computeBoundingBox(), pane.mesh.geometry.boundingBox);

            pane.mesh.updateMatrixWorld(true);

            return [
                new THREE.Vector3(box.min.x, box.min.y, 0),
                new THREE.Vector3(box.max.x, box.min.y, 0),
                new THREE.Vector3(box.max.x, box.max.y, 0),
                new THREE.Vector3(box.min.x, box.max.y, 0),
            ].map((point) => point.applyMatrix4(pane.mesh.matrixWorld));
        };

        /** How far the eye is from the pane's own plane. */
        const offThePlane = (pane) => {
            pane.mesh.updateMatrixWorld(true);

            const normal = new THREE.Vector3(0, 0, 1)
                .applyQuaternion(pane.mesh.getWorldQuaternion(new THREE.Quaternion()));

            return Math.abs(
                camera.position.clone().sub(pane.mesh.getWorldPosition(new THREE.Vector3())).dot(normal),
            );
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

it('holds the pane still on screen while moving it out of the near plane', function (): void {
    $answer = hugAnswer(<<<'JS'
        // Two and a half centimetres inside the mouth, looking along it rather
        // than at it — the reported spot, in the shape both reporting levels
        // share. The near plane is at five centimetres, so an unmoved pane is
        // cut in half by it.
        stand(7.975, 4, 0);

        const before = cornersOf(eastward).map(onScreen);
        const restedAt = offThePlane(eastward);

        eastward.hug(camera, PANE_CLEARANCE);

        const after = cornersOf(eastward).map(onScreen);
        const huggedAt = offThePlane(eastward);

        eastward.release();

        process.stdout.write(JSON.stringify({
            before,
            after,
            restedAt: Number(restedAt.toFixed(4)),
            huggedAt: Number(huggedAt.toFixed(4)),
            clearance: PANE_CLEARANCE,
            nearPlane: NEAR_PLANE,
            releasedTo: Number(offThePlane(eastward).toFixed(4)),
        }));
        JS);

    // The pane was inside the near plane and now is not — that is the whole
    // job. It is `PANE_CLEARANCE` off the eye rather than a hair off it,
    // because the eye keeps moving and the next frame must not put it back.
    expect($answer['restedAt'])->toBeLessThan($answer['nearPlane'])
        ->and($answer['huggedAt'])->toEqual($answer['clearance']);

    // And every corner is exactly where it was on screen. This is the
    // assertion ISSUE-101 would have failed: a pane squared up to the camera
    // has its corners at the corners of the screen whatever the mouth is
    // doing, so all four would read (±1, ±1).
    expect($answer['after'])->toBe($answer['before']);

    // Put back afterwards, or every other pane's camera sees a wall-sized
    // sheet hanging in the middle of the room.
    expect($answer['releasedTo'])->toBe($answer['restedAt']);
});

it('leaves a pane alone from anywhere the near plane cannot reach it', function (): void {
    $answer = hugAnswer(<<<'JS'
        const looks = [];

        // Half a metre back, then a long way back, at three angles each: none
        // of these is close enough for the near plane to be a problem, so none
        // of them may move the pane at all.
        for (const x of [7.5, 4]) {
            for (const yaw of [-90, 0, 90]) {
                stand(x, 4, yaw);

                const before = cornersOf(eastward).map(onScreen);

                eastward.hug(camera, PANE_CLEARANCE);

                looks.push({
                    x,
                    yaw,
                    moved: JSON.stringify(cornersOf(eastward).map(onScreen))
                        !== JSON.stringify(before),
                });

                eastward.release();
            }
        }

        process.stdout.write(JSON.stringify({ looks }));
        JS);

    expect(collect($answer['looks'])->pluck('moved')->all())
        ->toBe([false, false, false, false, false, false]);
});

it('hugs from beside the opening as readily as from in front of it', function (): void {
    $answer = hugAnswer(<<<'JS'
        const angles = [];

        // Every quarter turn from straight into the mouth to straight along it
        // and out the other side. All of them are two centimetres inside a
        // mouth and all of them have to be held off the near plane.
        //
        // Ninety and two-seventy are the ones that mattered: looking *along*
        // the plane is where the old test `look.dot(face) >= 0` reads exactly
        // zero, so the one angle that most needed the pane moved was the one
        // angle guaranteed not to move it.
        for (const yaw of [-90, -45, 0, 45, 90, 135, 180]) {
            stand(7.98, 4, yaw);

            const restedAt = offThePlane(eastward);

            eastward.hug(camera, PANE_CLEARANCE);

            angles.push({ yaw, held: Number(offThePlane(eastward).toFixed(4)) });

            eastward.release();
        }

        process.stdout.write(JSON.stringify({
            angles,
            clearance: PANE_CLEARANCE,
        }));
        JS);

    expect(collect($answer['angles'])->pluck('held')->unique()->values()->all())
        ->toBe([$answer['clearance']]);
});
