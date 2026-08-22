<?php

use Symfony\Component\Process\Process;

/**
 * A portal drawn on a wall that has a room behind it.
 *
 * The face the link was set on is the way in. The room behind it keeps its wall
 * and knows nothing about the portal — but it must not seal the mouth, and that
 * is the whole difficulty: a collider is a line on the floor plan and a line has
 * no sides, so the far face's own wall used to stop the player reaching the
 * mouth from the front. Level 8's staircase was unreachable from both directions
 * because of it.
 *
 * Opening both faces instead was worse: it took the wall away from the room
 * behind, which in level 8 was the ground-floor room under the stairs, leaving a
 * four-metre hole and teleporting anyone who walked into it. So the far face
 * keeps its wall and its collider only pushes back from its own side.
 */

/**
 * Runs a snippet with a small level built, and hands back what it printed.
 *
 * @return array<string, mixed>
 */
function portalAnswer(string $body, string $rooms = ''): array
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

        const { buildLevel } = await import('@/lib/engine/build-level.ts');
        const { createTextureLibrary } = await import('@/lib/engine/textures.ts');
        const { moveWithCollisions } = await import('@/lib/engine/collision.ts');
        const THREE = await import('three');
        const { createPortals, crossPortal, namesPortal, portalLinkOf } =
            await import('@/lib/engine/portals.ts');
        const { edgesOf } = await import('@/lib/engine/sectors.ts');

        const corner = (x, z, extra = {}) => ({
            x,
            z,
            blocks: false,
            wallTexture: null,
            isMirror: false,
            isSky: false,
            portalLink: null,
            ...extra,
        });

        const room = (slug, points, extra = {}) => ({
            slug,
            name: slug,
            floorHeight: 0,
            ceilingHeight: 3,
            floorTexture: null,
            ceilingTexture: null,
            wallTexture: null,
            isSky: false,
            isWater: false,
            points,
            ...extra,
        });

        const level = {
            slug: 'test',
            name: 'test',
            description: '',
            spawn: { x: 1, z: 1, angle: 0 },
            ceilingHeight: 3,
            spriteStyle: 'realistic',
            playerSprite: 'paul',
            wallColor: '#ffffff',
            floorColor: '#888888',
            accentColor: '#ffcc00',
            sky: null,
            things: [],
            sectors: [
                // The way in. Its east wall, which starts at (10, 0), is the
                // mouth, and it is a wall the room next door shares.
                room('near', [
                    corner(0, 0),
                    corner(10, 0, { blocks: true, portalLink: 'hop' }),
                    corner(10, 10),
                    corner(0, 10),
                ], { {$rooms} }),
                // The room behind that wall. It names nothing and should carry
                // on as though there were no portal at all.
                room('behind', [
                    corner(10, 0),
                    corner(20, 0),
                    corner(20, 10),
                    corner(10, 10, { blocks: true }),
                ]),
                // The far end, off on its own.
                room('far', [
                    corner(100, 0),
                    corner(110, 0, { portalLink: 'hop' }),
                    corner(110, 10),
                    corner(100, 10),
                ]),
            ],
        };

        const built = buildLevel(level, createTextureLibrary());
        const portals = createPortals(level.sectors);
        const RADIUS = 0.34;

        /** Walks from a spot towards another, and says how it ended. */
        const walk = (fromX, fromZ, toX, toZ) => {
            let at = { x: fromX, z: fromZ };

            for (let i = 0; i < 400; i++) {
                const dx = toX - at.x;
                const dz = toZ - at.z;
                const away = Math.hypot(dx, dz);

                if (away < 0.05) {
                    return { how: 'arrived', x: at.x, z: at.z };
                }

                const moved = moveWithCollisions(
                    at,
                    (dx / away) * 0.05,
                    (dz / away) * 0.05,
                    built.colliders,
                    RADIUS,
                );

                const through = crossPortal(portals, at.x, at.z, moved.x, moved.z, 0);

                if (through !== null) {
                    return {
                        how: 'crossed',
                        room: through.portal.exit.sector.slug,
                        x: Number(through.x.toFixed(2)),
                        z: Number(through.z.toFixed(2)),
                    };
                }

                if (Math.hypot(moved.x - at.x, moved.z - at.z) < 1e-4) {
                    return { how: 'stopped', x: Number(at.x.toFixed(2)), z: Number(at.z.toFixed(2)) };
                }

                at = moved;
            }

            return { how: 'never got there', x: at.x, z: at.z };
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

it('knows a wall is a portal from either of its faces, but only one is the way in', function (): void {
    $answer = portalAnswer(<<<'JS'
        const faces = edgesOf(level.sectors)
            .filter((edge) => portalLinkOf(edge) !== null)
            .map((edge) => `${edge.sector.slug}${namesPortal(edge) ? '*' : ''}`);

        process.stdout.write(JSON.stringify({ faces }));
        JS);

    // build-level has to recognise the far face to know not to seal it, so both
    // faces answer to portalLinkOf. Only the starred one is a mouth.
    expect($answer['faces'])->toEqualCanonicalizing(['near*', 'behind', 'far*']);
});

it('makes a portal of the two named faces and nothing else', function (): void {
    $answer = portalAnswer(<<<'JS'
        process.stdout.write(JSON.stringify({
            ways: createPortals(level.sectors)
                .map((portal) => `${portal.entry.sector.slug} -> ${portal.exit.sector.slug}`),
        }));
        JS);

    expect($answer['ways'])->toEqualCanonicalizing(['near -> far', 'far -> near']);
});

it('lets the player reach the mouth from the front', function (): void {
    $answer = portalAnswer(<<<'JS'
        process.stdout.write(JSON.stringify({ result: walk(8, 5, 12, 5) }));
        JS);

    // This is the bug the far face's wall used to cause: stopped a third of a
    // metre short of a mouth that was standing wide open.
    expect($answer['result']['how'])->toBe('crossed')
        ->and($answer['result']['room'])->toBe('far');
});

it('leaves the room behind the mouth walled in', function (): void {
    $answer = portalAnswer(<<<'JS'
        process.stdout.write(JSON.stringify({ result: walk(12, 5, 8, 5) }));
        JS);

    // Walking west out of the room behind the portal: a wall, and no crossing.
    // Opening this face is what put a hole in level 8's ground floor.
    expect($answer['result']['how'])->toBe('stopped')
        ->and($answer['result']['x'])->toBeGreaterThan(10.0);
});

it('still draws a wall for the room behind, and none for the mouth', function (): void {
    $answer = portalAnswer(<<<'JS'
        const faces = [];

        built.group.updateMatrixWorld(true);
        built.group.traverse((node) => {
            if (node.isMesh !== true || node.geometry?.type !== 'PlaneGeometry') {
                return;
            }

            const at = node.position.clone().setFromMatrixPosition(node.matrixWorld);

            // Anything standing on the portal wall, x = 10.
            if (Math.abs(at.x - 10) < 0.05 && at.y > 0.1) {
                faces.push({
                    kind: node.material.type,
                    // Which side of the wall it was nudged towards.
                    side: at.x > 10 ? 'behind' : 'near',
                });
            }
        });

        process.stdout.write(JSON.stringify({ faces }));
        JS);

    // One ordinary wall, nudged into the room behind, and one pane on the mouth.
    expect(collect($answer['faces'])->where('kind', 'MeshBasicMaterial')->pluck('side')->all())
        ->toBe(['behind'])
        ->and(collect($answer['faces'])->where('kind', 'ShaderMaterial'))
        ->toHaveCount(1);
});

it('takes the room behind the far mouth out of the pane it stands behind', function (): void {
    $answer = portalAnswer(<<<'JS'
        const [near, far] = built.portals;

        // Where each hidden piece sits, so the test can say which room it is in.
        const at = (nodes) => nodes.map((node) => {
            const middle = new THREE.Box3().setFromObject(node).getCenter(new THREE.Vector3());

            return [Number(middle.x.toFixed(2)), Number(middle.z.toFixed(2))];
        });

        process.stdout.write(JSON.stringify({
            homes: built.portals.map((pane) => pane.home),
            // The pane in `near` looks through the mouth on the far wall, so
            // what it has to hide is the wall standing behind THAT mouth.
            behindNear: at(near.behind),
            behindFar: at(far.behind),
        }));
        JS);

    // The pane's camera stands in the room behind its far mouth, so the whole of
    // that room is between the camera and the opening: its wall across the
    // mouth, the walls meeting it at the corners, its floor and its ceiling. The
    // tilted near plane is meant to cut all of it away, but anything touching
    // the mouth's plane is inside the slack CLIP_BIAS leaves. Level 8's
    // staircase showed the corridor's wall instead of the hall.
    expect($answer['homes'])->toEqualCanonicalizing(['near', 'far']);

    $nearFirst = $answer['homes'][0] === 'near';
    $behindTheFarMouth = $nearFirst ? $answer['behindNear'] : $answer['behindFar'];
    $behindTheNearMouth = $nearFirst ? $answer['behindFar'] : $answer['behindNear'];

    // Everything the room behind the near mouth drew, which is all of `behind`,
    // stands east of the wall at x = 10.
    expect($behindTheNearMouth)->not->toBeEmpty();

    foreach ($behindTheNearMouth as $piece) {
        expect($piece[0])->toBeGreaterThan(9.9);
    }

    // The far room's mouth backs on to nothing at all, so there is no room to
    // hide and the pane looking through it leaves everything in.
    expect($behindTheFarMouth)->toBe([]);
});

it('only pulls a pane over the view when the eye is in the opening', function (): void {
    $answer = portalAnswer(<<<'JS'
        const pane = built.portals.find((surface) => surface.home === 'near');
        const camera = new THREE.PerspectiveCamera(70, 1.8, 0.05, 200);

        /**
         * Stands the eye somewhere, facing the mouth, and says whether the pane
         * came away from where it belongs to square up to the screen.
         */
        const hugsAt = (x, z) => {
            camera.position.set(x, 1.5, z);
            camera.lookAt(new THREE.Vector3(10, 1.5, z));
            camera.updateMatrixWorld(true);

            pane.release();

            const resting = pane.mesh.position.clone();

            pane.hug(camera, 0.12);

            return pane.mesh.position.distanceTo(resting) > 1e-6;
        };

        process.stdout.write(JSON.stringify({
            // Right in the mouth, which is the whole width of the wall at x=10.
            inTheOpening: hugsAt(9.95, 5),
            // The same distance from the wall's plane, but off past its end.
            pastTheEnd: hugsAt(9.95, 25),
            // And well clear of it either way.
            wellBack: hugsAt(8, 5),
        }));
        JS);

    // The mouth is a rectangle in a wall, not the whole wall. Measuring only
    // the distance to its plane hauled the pane across the view anywhere along
    // that wall — and level 8 has a portal in the same wall as a wide doorway,
    // so walking through the doorway filled the screen with the portal's view
    // of somewhere else.
    expect($answer['inTheOpening'])->toBeTrue()
        ->and($answer['pastTheEnd'])->toBeFalse()
        ->and($answer['wellBack'])->toBeFalse();
});

it('ignores a link that only names one wall', function (): void {
    $answer = portalAnswer(<<<'JS'
        level.sectors[2].points[1].portalLink = null;

        process.stdout.write(JSON.stringify({ portals: createPortals(level.sectors).length }));
        JS);

    // Half a portal would put the player where there is nothing to arrive in, so
    // the wall stays an ordinary wall.
    expect($answer['portals'])->toBe(0);
});

it('hugs a mouth that is not at floor level', function (): void {
    $answer = portalAnswer(<<<'JS'
        const pane = built.portals.find((surface) => surface.home === 'near');
        const camera = new THREE.PerspectiveCamera(70, 1.8, 0.05, 200);

        const hugsAt = (x, z, eye) => {
            camera.position.set(x, eye, z);
            camera.lookAt(new THREE.Vector3(10, eye, z));
            camera.updateMatrixWorld(true);

            pane.release();

            const resting = pane.mesh.position.clone();

            pane.hug(camera, 0.12);

            return pane.mesh.position.distanceTo(resting) > 1e-6;
        };

        process.stdout.write(JSON.stringify({
            // Standing on the floor of the room, so the eye is 4.8 + 1.62.
            atEyeHeight: hugsAt(9.95, 5, 6.42),
            // Down at the level of the floor below, well under the opening.
            underneath: hugsAt(9.95, 5, 1.62),
            // And above its top edge.
            overTheTop: hugsAt(9.95, 5, 11),
        }));
        JS, 'floorHeight: 4.8, ceilingHeight: 8.6');

    // How far up the eye is has to be measured from the middle of the opening,
    // not from the floor of the level. Measured from zero, a mouth 4.8 to 8.6
    // reads the eye at 6.42 as 6.42 against a limit of 2.02 and never hugs at
    // all — so walking into a portal on an upper floor met the near plane
    // cutting an un-hugged pane, which is the flash people reported.
    //
    // A room at ground level hid it by arithmetic coincidence: 0 to 3 puts the
    // limit at 1.5 + 0.12 = 1.62, and EYE_HEIGHT is 1.62, so the old test
    // passed by exactly nothing. Every mouth in the portal demo is such a room,
    // which is why the demo is seamless and this was never found there.
    expect($answer['atEyeHeight'])->toBeTrue()
        ->and($answer['underneath'])->toBeFalse()
        ->and($answer['overTheTop'])->toBeFalse();
});
