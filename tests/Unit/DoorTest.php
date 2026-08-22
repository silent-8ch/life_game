<?php

use Symfony\Component\Process\Process;

/**
 * Doors that open.
 *
 * A doorway in this engine has always been a gap between wall runs with a thing
 * standing in the hole and nothing that swings. `ThingKind::Door` existed and
 * was inert; `is_door`, `swing`, `open_angle`, `open_seconds`, `is_open` and
 * `opens_flag` all landed with the data model and nothing read them.
 *
 * Two rules here are the whole of it, and both are from `plan-doors.md`.
 *
 * A door turns about its **edge**, not its middle, or it reads as a revolving
 * door. And its collider follows the **state** rather than the animation: it
 * stops being solid the moment a door starts opening and goes solid the moment
 * it starts closing, because tying it to the angle means the player can be
 * caught inside a door that is shutting, which is a far worse fault than a
 * doorway that is walkable a few frames early.
 */

/**
 * @return array<string, mixed>
 */
function doorAnswer(string $body): array
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
        const { moveWithCollisions } = await import('@/lib/engine/collision.ts');
        const { PLAYER_RADIUS } = await import('@/lib/engine/constants.ts');

        const corner = (x, z, extra = {}) => ({
            x, z, blocks: false, wallTexture: null, isMirror: false,
            isSky: false, portalLink: null, ...extra,
        });

        /** A door standing across the middle of the room, facing north. */
        const door = (extra = {}) => ({
            slug: 'door', name: 'Door', description: '', kind: 'door',
            sprite: null, behaviour: null, stats: null, speed: 0,
            texture: null, render: 'box', planeCount: 2, uvMode: 'fit',
            textureAlt: null, altFlag: null, animationFrames: 1,
            animationFps: 1,
            x: 5, z: 5, elevation: 0,
            width: 2, depth: 0.1, height: 2.1, angle: 0,
            isSolid: true, isDoor: true, swing: 'swing',
            openAngle: 90, openSeconds: 0.4, isOpen: false, opensFlag: null,
            verbs: [], ...extra,
        });

        const build = (thing, flags = []) => {
            const level = {
                slug: 'test', name: 'Test', description: '',
                spawn: { x: 5, z: 8, angle: 0 }, ceilingHeight: 3,
                spriteStyle: 'realistic', playerSprite: 'paul',
                wallColor: '#ffffff', floorColor: '#888888',
                accentColor: '#ffcc00', sky: null, playerStats: null,
                things: [thing],
                sectors: [{
                    slug: 'room', name: 'room', floorHeight: 0,
                    ceilingHeight: 3, floorTexture: null, ceilingTexture: null,
                    wallTexture: null, isSky: false, isWater: false,
                    points: [
                        corner(0, 0), corner(10, 0), corner(10, 10), corner(0, 10),
                    ],
                }],
            };

            const built = buildLevel(level, createTextureLibrary());
            built.group.updateMatrixWorld(true);

            return built;
        };

        const round = (value) => Number(value.toFixed(4));

        /** Where the two vertical edges of the door's panel are standing. */
        const edgesOfDoor = (built) => {
            let panel = null;

            built.group.traverse((node) => {
                if (node.isMesh === true && node.userData.thingSlug === 'door') {
                    panel = node;
                }
            });

            built.group.updateMatrixWorld(true);

            const at = (localX) =>
                panel.localToWorld(new THREE.Vector3(localX, 0, 0));

            const box = panel.geometry.boundingBox
                ?? (panel.geometry.computeBoundingBox(), panel.geometry.boundingBox);

            const left = at(box.min.x);
            const right = at(box.max.x);

            return {
                left: [round(left.x), round(left.z)],
                right: [round(right.x), round(right.z)],
            };
        };

        /** Whether a walk straight at the doorway gets through. */
        const walkThrough = (built) => {
            let at = { x: 5, z: 7 };

            for (let step = 0; step < 200; step++) {
                at = moveWithCollisions(at, 0, -0.05, built.colliders, PLAYER_RADIUS);
            }

            return round(at.z);
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

it('swings about its hinge edge rather than its middle', function (): void {
    $answer = doorAnswer(<<<'JS'
        const built = build(door());

        const shut = edgesOfDoor(built);

        built.props.doors.set('door', true);

        // Long enough to finish, whatever openSeconds is.
        for (let frame = 0; frame < 200; frame++) {
            built.props.update(1 / 60);
        }

        process.stdout.write(JSON.stringify({ shut, open: edgesOfDoor(built) }));
        JS);

    // Shut, the door lies along z = 5 from x = 4 to x = 6, which is where it
    // was authored. That has to stay true: a hinge that moved the door while
    // it was closed would be a doorway with a gap down one side.
    expect($answer['shut']['left'])->toEqual([4, 5])
        ->and($answer['shut']['right'])->toEqual([6, 5]);

    // Open, the hinge end has not moved a millimetre and the far end has swung
    // a quarter turn about it. That is the whole difference between a door and
    // a revolving door, and the only way to see it is to watch both ends.
    expect($answer['open']['left'])->toEqual([4, 5])
        ->and($answer['open']['right'])->toEqual([4, 3]);
});

it('is solid shut and not there open', function (): void {
    $answer = doorAnswer(<<<'JS'
        const shut = build(door());
        const stoppedAt = walkThrough(shut);

        shut.props.doors.set('door', true);

        const throughAt = walkThrough(shut);

        // Built already open, which is what `isOpen` means and all it means.
        const started = build(door({ isOpen: true }));

        process.stdout.write(JSON.stringify({
            stoppedAt,
            throughAt,
            startedOpenAt: walkThrough(started),
            startedOpen: started.props.doors.isOpen('door'),
            opened: shut.props.doors.opened(),
        }));
        JS);

    // Walked into shut, the player is held a radius short of the panel.
    expect($answer['stoppedAt'])->toBeGreaterThan(5.0);

    // Opened, the same walk goes straight through to the far wall.
    expect($answer['throughAt'])->toBeLessThan(1.0)
        ->and($answer['startedOpenAt'])->toBeLessThan(1.0)
        ->and($answer['startedOpen'])->toBeTrue()
        ->and($answer['opened'])->toBe(['door']);
});

it('lets go of the doorway before it has moved and takes it back at once', function (): void {
    $answer = doorAnswer(<<<'JS'
        const built = build(door());

        built.props.doors.set('door', true);

        // Not one frame of animation. The door has not visibly moved at all,
        // and the doorway is already walkable.
        const openedAt = { angle: round(edgesOfDoor(built).right[0]), through: walkThrough(built) };

        for (let frame = 0; frame < 200; frame++) {
            built.props.update(1 / 60);
        }

        built.props.doors.set('door', false);

        // And the other way: told to shut, still standing wide open, already
        // solid. This is the case the rule exists for — a collider that waited
        // for the animation would let somebody walk into the doorway while the
        // door was swinging and close it around them.
        const shuttingAt = { angle: round(edgesOfDoor(built).right[0]), through: walkThrough(built) };

        process.stdout.write(JSON.stringify({ openedAt, shuttingAt }));
        JS);

    // Opened: the panel is exactly where it was, and the way is clear.
    expect($answer['openedAt']['angle'])->toEqual(6)
        ->and($answer['openedAt']['through'])->toBeLessThan(1.0);

    // Shutting: the panel is still swung right round, and the way is blocked.
    expect($answer['shuttingAt']['angle'])->toEqual(4)
        ->and($answer['shuttingAt']['through'])->toBeGreaterThan(5.0);
});

it('slides instead of swinging when that is how it gets out of the way', function (): void {
    $answer = doorAnswer(<<<'JS'
        const built = build(door({ swing: 'slide' }));

        const shut = edgesOfDoor(built);

        built.props.doors.set('door', true);

        for (let frame = 0; frame < 200; frame++) {
            built.props.update(1 / 60);
        }

        process.stdout.write(JSON.stringify({ shut, open: edgesOfDoor(built) }));
        JS);

    // A slider stays in its own plane and moves along it. `openAngle` for one
    // is the fraction of its own width it travels times ninety, so the default
    // 90 is a door that slides its whole width clear of the opening.
    expect($answer['shut']['left'])->toEqual([4, 5])
        ->and($answer['open']['left'])->toEqual([6, 5])
        ->and($answer['open']['right'])->toEqual([8, 5]);
});
