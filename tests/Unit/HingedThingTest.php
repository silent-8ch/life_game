<?php

use Symfony\Component\Process\Process;

/**
 * Things that turn about an edge, and the two effects that turn them.
 *
 * There is no door here and that is the design. Paul, having watched a door get
 * built as a kind of its own and then pushed back behind live bugs for a day:
 * *a door is just a solid sprite that has a hinge with an action.*
 *
 * So the hinge belongs to the thing and the action is an ordinary interaction.
 * A door is a flat thing hinged at a side whose `Use` turns it ninety degrees
 * and stops it blocking; a drawbridge is the same with the hinge at the bottom;
 * a hatch is the same with it at the top. **The rotate effect never has to know
 * which of those it is holding**, which is the whole of why it is generic.
 *
 * What it replaced was six columns — `is_door`, `swing`, `open_angle`,
 * `open_seconds`, `is_open`, `opens_flag` — which between them could make
 * exactly one thing, and could not make any of the others.
 */

/**
 * @return array<string, mixed>
 */
function hingedThing(string $things, string $body): array
{
    $script = <<<JS
        const blank = () => ({
            width: 0, height: 0, style: {},
            addEventListener() {}, removeEventListener() {},
            getContext: () => null,
        });

        globalThis.document = { createElementNS: blank, createElement: blank };

        const THREE = await import('three');
        const { buildLevel } = await import('@/lib/engine/build-level.ts');
        const { createTextureLibrary } = await import('@/lib/engine/textures.ts');
        const { moveWithCollisions } = await import('@/lib/engine/collision.ts');
        const { PLAYER_RADIUS } = await import('@/lib/engine/constants.ts');

        const corner = (x, z, extra = {}) => ({
            x, z, blocks: false, wallTexture: null, isMirror: false,
            isSky: false, portalLink: null, ...extra,
        });

        /** A flat panel across the middle of the room, facing north. */
        const panel = (extra = {}) => ({
            slug: 'panel', name: 'Panel', description: '', kind: 'door',
            sprite: null, behaviour: null, stats: null, speed: 0,
            texture: null, render: 'flat', planeCount: 2, uvMode: 'fit',
            textureAlt: null, altFlag: null, animationFrames: 1,
            animationFps: 1,
            x: 5, z: 5, elevation: 0,
            width: 2, depth: 0.1, height: 2.1, angle: 0,
            hinge: 'left', isSolid: true, verbs: [], ...extra,
        });

        const level = {
            slug: 'test', name: 'Test', description: '',
            spawn: { x: 5, z: 8, angle: 0 }, ceilingHeight: 3,
            spriteStyle: 'realistic', playerSprite: 'paul',
            wallColor: '#ffffff', floorColor: '#888888',
            accentColor: '#ffcc00', sky: null, playerStats: null,
            things: {$things},
            sectors: [{
                slug: 'room', name: 'room', floorHeight: 0, ceilingHeight: 3,
                floorTexture: null, ceilingTexture: null, wallTexture: null,
                isSky: false, isWater: false, isInvisible: false,
                points: [
                    corner(0, 0), corner(10, 0), corner(10, 10), corner(0, 10),
                ],
            }],
        };

        const built = buildLevel(level, createTextureLibrary());
        built.group.updateMatrixWorld(true);

        const round = (value) => Number(value.toFixed(3));

        /** Where the two ends of a panel are standing, in the world. */
        const endsOf = (slug) => {
            let mesh = null;

            built.group.traverse((node) => {
                if (node.isMesh === true && node.userData.thingSlug === slug) {
                    mesh = node;
                }
            });

            built.group.updateMatrixWorld(true);

            const box = mesh.geometry.boundingBox
                ?? (mesh.geometry.computeBoundingBox(), mesh.geometry.boundingBox);

            const at = (x, y) => {
                const point = mesh.localToWorld(new THREE.Vector3(x, y, 0));

                return [round(point.x), round(point.y), round(point.z)];
            };

            return {
                low: at(box.min.x, box.min.y),
                high: at(box.max.x, box.max.y),
            };
        };

        /** Whether a walk straight at the panel gets through. */
        const walkThrough = () => {
            let at = { x: 5, z: 7 };

            for (let step = 0; step < 200; step++) {
                at = moveWithCollisions(at, 0, -0.05, built.colliders, PLAYER_RADIUS);
            }

            return round(at.z);
        };

        /** Long enough for any turn to finish. */
        const settle = () => {
            for (let frame = 0; frame < 300; frame++) {
                built.props.update(1 / 60);
            }
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

it('turns about the edge it is hinged on and not about its middle', function (): void {
    $answer = hingedThing('[panel()]', <<<'JS'
        const shut = endsOf('panel');

        built.props.moving.turn('panel', 90);
        settle();

        process.stdout.write(JSON.stringify({ shut, open: endsOf('panel') }));
        JS);

    // Shut, it lies along z = 5 from x = 4 to x = 6, where it was authored.
    // That has to stay true: a hinge that moved the thing while it was shut
    // would be a doorway with a gap down one side.
    expect($answer['shut']['low'])->toEqual([4, 0, 5])
        ->and($answer['shut']['high'])->toEqual([6, 2.1, 5]);

    // Open, the hinge end has not moved a millimetre and the far end has swung
    // a quarter turn about it. That is the whole difference between a door and
    // a revolving door, and the only way to see it is to watch both ends.
    expect($answer['open']['low'])->toEqual([4, 0, 5])
        ->and($answer['open']['high'])->toEqual([4, 2.1, 3]);
});

it('turns about a horizontal edge when that is where the hinge is', function (): void {
    $answer = hingedThing("[panel({ hinge: 'bottom' })]", <<<'JS'
        built.props.moving.turn('panel', 90);
        settle();

        process.stdout.write(JSON.stringify({ open: endsOf('panel') }));
        JS);

    // A drawbridge. The bottom edge stays on the ground and the top swings
    // down to lie flat — same effect, same call, same ninety degrees as the
    // door above. The only difference is which edge somebody chose in the
    // editor, and nothing in the engine knows that one of these is a door and
    // the other is a bridge.
    expect($answer['open']['low'])->toEqual([4, 0, 5])
        ->and($answer['open']['high'][1])->toEqual(0.0);

    // It falls towards the viewer at +90 and away at -90, which is the sign
    // being the author's control rather than a convention worth arguing about
    // — the same way it decides which side a door swings to.
    expect($answer['open']['high'][2])->toEqual(7.1);
});

it('lets go of the way before it has moved and takes it back at once', function (): void {
    $answer = hingedThing('[panel()]', <<<'JS'
        const stoppedAt = walkThrough();

        built.props.moving.block('panel', false);

        // Not one frame of turning. It has not visibly moved and the way is
        // already clear.
        const openedAt = { angle: endsOf('panel').high[0], through: walkThrough() };

        built.props.moving.turn('panel', 90);
        settle();

        built.props.moving.block('panel', true);

        // And the other way: told to block, still standing wide open, already
        // solid. This is the case the rule exists for — a collider that waited
        // for the swing could close on somebody standing in the doorway.
        const shuttingAt = { angle: endsOf('panel').high[0], through: walkThrough() };

        process.stdout.write(JSON.stringify({ stoppedAt, openedAt, shuttingAt }));
        JS);

    expect($answer['stoppedAt'])->toBeGreaterThan(5.0);

    expect($answer['openedAt']['angle'])->toEqual(6)
        ->and($answer['openedAt']['through'])->toBeLessThan(1.0);

    expect($answer['shuttingAt']['angle'])->toEqual(4)
        ->and($answer['shuttingAt']['through'])->toBeGreaterThan(5.0);
});

it('turns to an angle rather than by one', function (): void {
    $answer = hingedThing('[panel()]', <<<'JS'
        built.props.moving.turn('panel', 90);
        settle();

        const once = endsOf('panel').high;

        // Fired again, as a player leaning on Use would.
        built.props.moving.turn('panel', 90);
        settle();
        built.props.moving.turn('panel', 90);
        settle();

        process.stdout.write(JSON.stringify({ once, thrice: endsOf('panel').high }));
        JS);

    // A door you Use twice should be open, not open twice. Absolute rather than
    // an amount to add is what makes an effect safe to fire from a page that
    // may retry it and from a save that replays it.
    expect($answer['thrice'])->toEqual($answer['once']);
});

it('leaves a thing with no hinge exactly where it was drawn', function (): void {
    // The second has no `hinge` key at all, which is what a level saved before
    // the column existed sends. `undefined !== null` is true, so reading it by
    // type alone hinges every thing in every old level on nothing and hangs its
    // picture off a leaf that never turns — which is exactly what happened, and
    // what every fixture in `PropRenderTest` caught within the minute.
    $answer = hingedThing(
        "[panel({ hinge: null }), (() => { const old = panel({ slug: 'old', x: 2 }); delete old.hinge; return old; })()]",
        <<<'JS'
        const moved = [
            built.props.moving.turn('panel', 90),
            built.props.moving.turn('old', 90),
        ];

        settle();

        process.stdout.write(JSON.stringify({
            moved,
            stillThere: endsOf('panel').high,
        }));
        JS
    );

    // Nothing to turn, so nothing turned, and the panel is where it was drawn.
    expect($answer['moved'])->toBe([false, false])
        ->and($answer['stillThere'])->toEqual([6, 2.1, 5]);
});
