<?php

use Symfony\Component\Process\Process;

/**
 * Walking through a portal whose mouth is not at ground level.
 *
 * Paul, playtesting: *"i can not walk through that portal (height restriction?)
 * ... before portals I walked thorugh were on ground level"*. The observation is
 * exact — every mouth in the portal demo sits at floor 0, and the one in level 8
 * that will not let him through sits at 4.8, on a landing at the top of a
 * staircase, with a ground-level room behind the wall it is cut into.
 *
 * That arrangement is worth a test whatever the cause turns out to be, because
 * it is the one the demo cannot exercise: two rooms sharing a mouth's wall but
 * not a floor, where the room behind has its *ceiling* below the mouth's floor.
 * The engine has a comment about the shape and no test of it.
 */

/**
 * @return array<string, mixed>
 */
function aboveGround(string $body): array
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
        const { createPortals } = await import('@/lib/engine/portals.ts');
        const { walkPlayer } = await import('@/lib/engine/player.ts');
        const { sectorAt } = await import('@/lib/engine/sectors.ts');

        const corner = (x, z, extra = {}) => ({
            x, z, blocks: false, wallTexture: null, isMirror: false,
            isSky: false, portalLink: null, ...extra,
        });

        const room = (slug, points, floorHeight, ceilingHeight) => ({
            slug, name: slug, floorHeight, ceilingHeight,
            floorTexture: null, ceilingTexture: null, wallTexture: null,
            isSky: false, isWater: false, points,
        });

        /**
         * Level 8's stairs portal, reduced to the three rooms that matter.
         *
         * `landing` is the 2m x 0.5m sliver that owns the near mouth, up at
         * 4.8. `under` is the ground-level room directly behind that same wall,
         * whose ceiling at 3.8 is a metre BELOW the mouth's floor. `far` holds
         * the partner mouth at the same height as the landing.
         */
        const level = {
            slug: 'test', name: 'Test', description: '',
            spawn: { x: 3, z: -17.75, angle: 0 }, ceilingHeight: 3,
            spriteStyle: 'realistic', playerSprite: 'paul',
            wallColor: '#ffffff', floorColor: '#888888', accentColor: '#ffcc00',
            sky: null, things: [],
            sectors: [
                room('landing', [
                    corner(2, -18, { blocks: true, portalLink: 'stairs' }),
                    corner(4, -18, { blocks: true }),
                    corner(4, -17.5),
                    corner(2, -17.5),
                ], 4.8, 8.6),
                // Behind the mouth's wall, at ground level, ceiling below it.
                room('under', [
                    corner(2, -25.5),
                    corner(4, -25.5),
                    corner(4, -18),
                    corner(2, -18),
                ], 0, 3.8),
                room('far', [
                    corner(70, -18, { blocks: true, portalLink: 'stairs' }),
                    corner(72, -18, { blocks: true }),
                    corner(72, -17.5),
                    corner(70, -17.5),
                ], 4.8, 8.6),
            ],
        };

        const built = buildLevel(level, createTextureLibrary());
        const portals = createPortals(level.sectors);

        /** Walks forward from a spot and says where they ended up. */
        const walkFrom = (x, z, yaw, steps = 40) => {
            const player = {
                x, z, yaw, pitch: 0, eye: 6.4, walked: 0,
                eyeTarget: 6.4, floor: 4.8,
            };

            // `walkPlayer` crosses portals itself — it is handed `portals`
            // for exactly that. Calling `crossPortal` again afterwards reads a
            // move that has already been carried through and finds nothing,
            // which looks precisely like a portal that will not open.
            for (let i = 0; i < steps; i++) {
                walkPlayer(
                    player,
                    { forward: 1, strafe: 0, running: false },
                    {
                        sectors: level.sectors,
                        colliders: built.colliders,
                        portals,
                    },
                    1 / 60,
                );

                const room = sectorAt(level.sectors, player.x, player.z);

                if (room?.slug === 'far') {
                    return { crossed: true, x: player.x, z: player.z };
                }
            }

            return { crossed: false, x: player.x, z: player.z };
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

it('lays no collider across a mouth that another room shares the wall of', function (): void {
    // The thing that would seal it. A collider is a line on the floor plan with
    // no height at all, so one laid across the mouth by the room *underneath*
    // stops somebody 4.8m above it just as readily as somebody standing in it.
    $answer = aboveGround(<<<'JS'
        // Everything lying on the mouth's own line, x 2..4 at z = -18.
        const across = built.colliders.filter((c) =>
            c.kind === 'segment'
            && Math.abs(c.z1 + 18) < 0.001
            && Math.abs(c.z2 + 18) < 0.001
            && Math.min(c.x1, c.x2) < 4
            && Math.max(c.x1, c.x2) > 2
        );

        process.stdout.write(JSON.stringify({
            count: across.length,
            // A one-sided collider is the mitigation; a two-sided one is the
            // bug, because it stops the player from both rooms at once.
            twoSided: across.filter((c) => c.facing === undefined).length,
        }));
        JS);

    expect($answer['twoSided'])->toBe(
        0,
        'A collider with no open side lies across the portal mouth, so it is shut from inside the landing as well as from the room below.'
    );
});

it('lets the player walk through a mouth that is not at ground level', function (): void {
    // Paul's report, as nearly as a test can hold it: standing on the landing,
    // 9cm from the mouth, facing it, walking forward.
    $answer = aboveGround(<<<'JS'
        // Facing -z, which is the way out through the mouth.
        process.stdout.write(JSON.stringify({ went: walkFrom(3, -17.91, 0) }));
        JS);

    expect($answer['went']['crossed'])->toBeTrue(
        'Walked at the mouth from 9cm away and never crossed it.'
    );
});

it('still carries them to the far mouth rather than somewhere else', function (): void {
    // Crossing is one thing; arriving is another. The far mouth is at x 70..72.
    $answer = aboveGround(<<<'JS'
        process.stdout.write(JSON.stringify({ went: walkFrom(3, -17.91, 0) }));
        JS);

    expect($answer['went']['crossed'])->toBeTrue();
    expect($answer['went']['x'])->toBeGreaterThan(69.0);
    expect($answer['went']['x'])->toBeLessThan(73.0);
});
