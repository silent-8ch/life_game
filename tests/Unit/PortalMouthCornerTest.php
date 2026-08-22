<?php

use Symfony\Component\Process\Process;

/**
 * A room that only shares a corner with a portal's mouth still has to be hidden
 * while the pane is drawn.
 *
 * Every wall is nudged WALL_INSET into its own room so that two rooms meeting
 * at a boundary do not fight over which face is in front. For a wall standing
 * at the very end of a mouth and running away from it, "into its own room" is
 * into the opening — so a centimetre of that wall sits across the mouth.
 *
 * The pane's camera stands at the far mouth looking back into the room it
 * shows, and that centimetre is between it and the opening. It survives the
 * tilted near plane, because it is touching the plane rather than behind it,
 * and draws as a hard stripe of a wall belonging to a room nowhere near the one
 * being looked into. That is what was being reported as a flicker at portal
 * borders, and it was found by painting every wall its own colour in debug and
 * reading the sliver's colour back off the picture: it named a room that shares
 * one corner with the far mouth and nothing else.
 *
 * The pane used to hide only the single room straight through the mouth, which
 * this room is not.
 *
 * @return array<string, mixed>
 */
function cornerAnswer(string $body): array
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

        const corner = (x, z, extra = {}) => ({
            x, z, blocks: false, wallTexture: null, isMirror: false,
            isSky: false, portalLink: null, ...extra,
        });

        const room = (slug, points, floorHeight = 0, ceilingHeight = 3) => ({
            slug, name: slug, floorHeight, ceilingHeight,
            floorTexture: null, ceilingTexture: null, wallTexture: null,
            isSky: false, isWater: false, points,
        });

        const level = {
            slug: 'test', name: 'Test', description: '',
            spawn: { x: 1, z: 1, angle: 0 }, ceilingHeight: 3,
            spriteStyle: 'realistic', playerSprite: 'paul',
            wallColor: '#ffffff', floorColor: '#888888', accentColor: '#ffcc00',
            sky: null, things: [],
            sectors: [
                // Where the player stands. Its north wall is one mouth.
                room('here', [
                    corner(0, 0, { blocks: true, portalLink: 'hop' }),
                    corner(4, 0),
                    corner(4, 4),
                    corner(0, 4),
                ]),
                // The room the pane shows. Its south wall is the other mouth.
                room('there', [
                    corner(100, 0),
                    corner(104, 0, { blocks: true, portalLink: 'hop' }),
                    corner(104, 4),
                    corner(100, 4),
                ]),
                // Shares only the corner (104, 0) with the far mouth, and runs
                // away from it. Nothing makes it the room beyond that mouth.
                room('corner-neighbour', [
                    corner(104, 0),
                    corner(108, 0),
                    corner(108, -4),
                    corner(104, -4),
                ]),
            ],
        };

        const built = buildLevel(level, createTextureLibrary());
        built.group.updateMatrixWorld(true);

        /** Which rooms each pane hides while it draws. */
        const hiddenByPanes = () => built.portals.map((pane) => {
            const rooms = new Set();

            for (const thing of pane.behind ?? []) {
                thing.traverse((node) => {
                    const wall = node.userData.wall;
                    const flat = node.userData.flat;

                    if (wall !== undefined) rooms.add(wall.sector);
                    if (flat !== undefined) rooms.add(flat.sector);
                });
            }

            return [...rooms].sort();
        });

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

it('hides a room that shares only a corner with the mouth it draws through', function (): void {
    $answer = cornerAnswer(<<<'JS'
        process.stdout.write(JSON.stringify({ hidden: hiddenByPanes() }));
        JS);

    // Two mouths, so two panes.
    expect($answer['hidden'])->toHaveCount(2);

    $all = array_merge(...$answer['hidden']);

    // The pane that looks into `there` has to put `corner-neighbour` away,
    // because its wall running north from (104, 0) is nudged a centimetre
    // across the opening and would otherwise draw down the edge of the pane.
    expect($all)->toContain('corner-neighbour');
});

it('puts away nothing for a mouth that nothing else touches', function (): void {
    $answer = cornerAnswer(<<<'JS'
        process.stdout.write(JSON.stringify({ hidden: hiddenByPanes() }));
        JS);

    // Widening the net must not turn into hiding the level. `here`'s mouth has
    // no room beyond it and no room sharing either of its corners, so the pane
    // drawn through it has nothing to put away — exactly one of the two lists
    // is empty.
    $empty = array_filter($answer['hidden'], fn (array $rooms): bool => $rooms === []);

    expect($empty)->toHaveCount(1);
});
