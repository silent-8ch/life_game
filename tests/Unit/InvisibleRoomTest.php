<?php

use Symfony\Component\Process\Process;

/**
 * A room the camera sees straight through.
 *
 * Paul: *"make invisible rooms. If this is set, the camera acts as if it sees
 * through this room. The floor should still be visible. The player and
 * characters can walk into this area, they become invisible."*
 *
 * It is not a quieter `is_sky` and the difference is the point. A sky room has
 * no ceiling drawn and gets an invisible lid instead, precisely so a sight-line
 * cannot run out of the level. This is the opposite ruling: whatever lies
 * beyond just shows, and where nothing lies beyond you see the backdrop. It is
 * meant to be the hole a lid exists to prevent, so anybody who puts an occluder
 * back to be safe has taken the feature out.
 *
 * The half that is easy to miss is the *outside*. The walls between a normal
 * room and an invisible one are not drawn for the normal room either — a
 * painted box with an invisible inside is not what see-through means.
 */

/**
 * @return array<string, mixed>
 */
function invisibleRoom(string $body): array
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
        const { readTopology } = await import('@/lib/engine/build/topology.ts');
        const { createTextureLibrary } = await import('@/lib/engine/textures.ts');
        const { moveWithCollisions } = await import('@/lib/engine/collision.ts');
        const { PLAYER_RADIUS } = await import('@/lib/engine/constants.ts');

        const corner = (x, z, extra = {}) => ({
            x, z, blocks: false, wallTexture: null, isMirror: false,
            isSky: false, portalLink: null, ...extra,
        });

        const room = (slug, points, extra = {}) => ({
            slug, name: slug, floorHeight: 0, ceilingHeight: 3,
            floorTexture: 'oak-floor', ceilingTexture: 'oak-floor',
            wallTexture: 'oak-floor',
            isSky: false, isWater: false, isInvisible: false, points, ...extra,
        });

        const crate = (slug, x, z) => ({
            slug, name: slug, description: '', kind: 'prop',
            sprite: null, behaviour: null, stats: null, speed: 0,
            texture: null, render: 'box', planeCount: 2, uvMode: 'tile',
            textureAlt: null, altFlag: null, animationFrames: 1,
            animationFps: 1, x, z, elevation: 0,
            width: 0.6, depth: 0.6, height: 0.6, angle: 0,
            isSolid: true, isDoor: false, swing: 'swing', openAngle: 90,
            openSeconds: 0.4, isOpen: false, opensFlag: null, verbs: [],
        });

        // Two rooms side by side sharing a boundary at x = 10, and room for a
        // crate in each. `shut` puts the block on the near room's corner that
        // *starts* the shared edge, which is the only corner that makes that
        // boundary solid.
        const build = ({ far = {}, shut = false, things = [] } = {}) => {
            const level = {
                slug: 'test', name: 'Test', description: '',
                spawn: { x: 5, z: 5, angle: 0 }, ceilingHeight: 3,
                spriteStyle: 'realistic', playerSprite: 'paul',
                wallColor: '#ffffff', floorColor: '#888888',
                accentColor: '#ffcc00', sky: null, playerStats: null,
                things,
                sectors: [
                    room('near', [
                        corner(0, 0),
                        corner(10, 0, { blocks: shut }),
                        corner(10, 10),
                        corner(0, 10),
                    ]),
                    // A lower ceiling than the near room, so the boundary
                    // between them is a real wall rather than a band of no
                    // height. Two rooms of the same height share a wall the
                    // builder draws nothing for, which would make the test
                    // below measure nothing and pass anyway.
                    room('far', [
                        corner(10, 0), corner(20, 0), corner(20, 10), corner(10, 10),
                    ], { ceilingHeight: 2, ...far }),
                ],
            };

            const built = buildLevel(level, createTextureLibrary());
            built.group.updateMatrixWorld(true);

            return { built, topology: readTopology(level), level };
        };

        /**
         * Meshes that are actually going to be drawn, counted by where they
         * stand.
         *
         * Visibility counts up the whole chain: a prop in a see-through room is
         * still built, it is simply switched off, and switched off inside a
         * holder is the same as switched off.
         *
         * Counted by position rather than by room, because which room a surface
         * belongs to is not on the built level and is not worth widening the
         * type for. The far count starts past x = 10 so the shared wall cannot
         * be mistaken for the far room's own; the near count takes it in, which
         * is what makes the wall between them measurable at all.
         */
        const drawnBetween = (built, from, to) => {
            const middle = new THREE.Vector3();
            let shown = 0;

            built.group.updateMatrixWorld(true);

            built.group.traverse((node) => {
                if (node.isMesh !== true) {
                    return;
                }

                for (let at = node; at !== null; at = at.parent) {
                    if (at.visible === false) {
                        return;
                    }
                }

                new THREE.Box3().setFromObject(node).getCenter(middle);

                if (middle.x >= from && middle.x <= to) {
                    shown++;
                }
            });

            return shown;
        };

        /** Everything drawn in the far room, which is x 10 to 20. */
        const drawnInFar = (built) => drawnBetween(built, 10.5, 20.5);

        /** Everything drawn in the near room, the shared wall included. */
        const drawnInNear = (built) => drawnBetween(built, -0.5, 10.4);

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

it('draws the floor of a see-through room and nothing else of it', function (): void {
    $answer = invisibleRoom(<<<'JS'
        const solid = build();
        const seen = build({ far: { isInvisible: true } });

        process.stdout.write(JSON.stringify({
            solidFar: drawnInFar(solid.built),
            seenFar: drawnInFar(seen.built),
            lids: {
                solid: solid.built.skyLids.length,
                seen: seen.built.skyLids.length,
            },
        }));
        JS);

    // An ordinary room puts a floor, a ceiling and its own outer walls in the
    // scene. A see-through one puts a floor, which is the whole of what Paul
    // asked to keep.
    expect($answer['solidFar'])->toBeGreaterThan(3)
        ->and($answer['seenFar'])->toBe(1);

    // No lid, deliberately. A sky room has one so that a sight-line cannot run
    // out of the level, and this is the ruling that it should.
    expect($answer['lids']['seen'])->toBe(0)
        ->and($answer['lids']['solid'])->toBe(0);
});

it('draws no wall between the two rooms, from either side', function (): void {
    $answer = invisibleRoom(<<<'JS'
        process.stdout.write(JSON.stringify({
            solidNear: drawnInNear(build().built),
            seenNear: drawnInNear(build({ far: { isInvisible: true } }).built),
        }));
        JS);

    // The near room is an ordinary room and still draws less than it used to,
    // because the wall onto a see-through room is not its to draw either.
    //
    // This is the half that is easy to get wrong. Skip it and an invisible room
    // is a painted box from outside — you would walk up to a solid-looking wall
    // and pass through it into nothing.
    expect($answer['seenNear'])->toBeLessThan($answer['solidNear']);
});

it('leaves collision exactly as it was', function (): void {
    $answer = invisibleRoom(<<<'JS'
        const walk = (built) => {
            let at = { x: 5, z: 5 };

            for (let step = 0; step < 400; step++) {
                at = moveWithCollisions(at, 0.05, 0, built.colliders, PLAYER_RADIUS);
            }

            return Number(at.x.toFixed(2));
        };

        process.stdout.write(JSON.stringify({
            throughOpen: walk(build({ far: { isInvisible: true } }).built),
            stoppedSeen: walk(build({ far: { isInvisible: true }, shut: true }).built),
            stoppedNormal: walk(build({ shut: true }).built),
        }));
        JS);

    // Open boundary, see-through room: you walk straight in, as you always
    // could. That is the whole of what Paul meant by walking into it.
    expect($answer['throughOpen'])->toBeGreaterThan(15.0);

    // Shut boundary: stopped in exactly the same place whether the room beyond
    // is drawn or not. A wall you can see through is still a wall — collision
    // is not a rendering question, and this is the assertion that says so.
    expect($answer['stoppedSeen'])->toEqual($answer['stoppedNormal'])
        ->and($answer['stoppedSeen'])->toBeLessThan(10.0);
});

it('does not draw what is standing in one', function (): void {
    $answer = invisibleRoom(<<<'JS'
        const things = [crate('here', 5, 5), crate('there', 15, 5)];

        const solid = build({ things });
        const seen = build({ far: { isInvisible: true }, things });

        process.stdout.write(JSON.stringify({
            solidFar: drawnInFar(solid.built),
            seenFar: drawnInFar(seen.built),
            solidNear: drawnInNear(solid.built),
            seenNear: drawnInNear(seen.built),
            colliders: {
                solid: solid.built.colliders.length,
                seen: seen.built.colliders.length,
            },
        }));
        JS);

    // The far room is down to its floor with the crate in it gone: Paul's
    // ruling is that things go invisible with the characters, so only the floor
    // is left. The near room keeps its own crate — being next door to a
    // see-through room is not catching.
    expect($answer['seenFar'])->toBe(1)
        ->and($answer['solidFar'])->toBeGreaterThan($answer['seenFar'] + 1);

    // Both crates still stop you. Not drawn is not the same as not there.
    expect($answer['colliders']['seen'])->toBe($answer['colliders']['solid']);
});

it('lets a room see through it even where a wall would stop the eye', function (): void {
    $answer = invisibleRoom(<<<'JS'
        // The boundary is solid, so nobody walks through it. Whether anybody
        // can *see* through it is a different question, and this is the one
        // place the two answers come apart.
        process.stdout.write(JSON.stringify({
            normal: build({ shut: true }).topology.seenFrom('near').sort(),
            seeThrough: build({ far: { isInvisible: true }, shut: true })
                .topology.seenFrom('near').sort(),
        }));
        JS);

    // A wall onto an ordinary room stops the eye, and the visibility set stops
    // with it.
    expect($answer['normal'])->toBe(['near']);

    // A wall onto a see-through room stops nobody's eye, so it does not stop
    // the set either. That is what keeps a mirror on the far side of one from
    // sitting frozen — the same fault the one-hop set produced, arriving by a
    // different route.
    expect($answer['seeThrough'])->toBe(['far', 'near']);
});
