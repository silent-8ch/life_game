<?php

use Symfony\Component\Process\Process;

/**
 * A hinge wall has to still be the same wall after the room is edited.
 *
 * A slope is stored as an index into the room's points, and almost everything
 * the editor does rewrites that list: splitting a wall, welding a corner a
 * neighbour landed on, carving a bite out of the room. The index survives every
 * one of those numerically and quietly means a different wall afterwards, so a
 * floor that rose towards the window starts rising towards the door instead.
 *
 * That is the same class of fault as a split wall keeping a portal link that no
 * longer pairs with anything, and it is worse to debug, because the room still
 * looks like a room — it just slopes the wrong way.
 */

/**
 * @return array<string, mixed>
 */
function hingedLevel(string $sectors, string $body): array
{
    $script = <<<JS
        const { carveRooms, weldCorners } = await import('@/lib/editor/carve.ts');
        const { keepHinges } = await import('@/lib/editor/map.ts');

        const corner = (x, z, extra = {}) => ({
            x, z, wallTexture: null, blocks: false,
            isMirror: false, isSky: false, portalLink: null, ...extra,
        });

        const room = (slug, points, extra = {}) => ({
            slug,
            name: slug,
            floorHeight: 0,
            ceilingHeight: 3,
            floorSlope: 0,
            floorSlopeEdge: null,
            ceilingSlope: 0,
            ceilingSlopeEdge: null,
            floorTexture: null,
            ceilingTexture: null,
            wallTexture: null,
            isSky: false,
            isWater: false,
            points,
            ...extra,
        });

        const box = (slug, minX, minZ, maxX, maxZ, extra = {}) =>
            room(slug, [
                corner(minX, minZ),
                corner(maxX, minZ),
                corner(maxX, maxZ),
                corner(minX, maxZ),
            ], extra);

        const level = {
            slug: 'test', name: 'test', description: '',
            spawn: { x: 1, z: 1, angle: 0 }, ceilingHeight: 3,
            spriteStyle: 'realistic', playerSprite: 'paul',
            wallColor: '#ffffff', floorColor: '#888888', accentColor: '#ffcc00',
            sky: null, playerStats: null, things: [],
            sectors: {$sectors},
        };

        /** The two corners a room's hinge wall runs between. */
        const hingeWall = (sector) => {
            const at = sector.floorSlopeEdge;

            if (at === null) return null;

            const from = sector.points[at];
            const to = sector.points[(at + 1) % sector.points.length];

            return [[from.x, from.z], [to.x, to.z]];
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

it('keeps the hinge on the same wall when a weld inserts a corner before it', function (): void {
    $answer = hingedLevel(
        // A hall hinged on its north wall (edge 2), and a room touching the
        // hall's *south* wall partway along. Welding inserts two corners into
        // the hall's list ahead of the hinge, shifting every later index.
        "[box('hall', 0, 0, 10, 10, { floorSlope: 0.5, floorSlopeEdge: 2 }), box('annexe', 4, -4, 6, 0)]",
        <<<'JS'
        const before = level.sectors[0];
        const after = weldCorners(level).sectors[0];

        process.stdout.write(JSON.stringify({
            corners: { before: before.points.length, after: after.points.length },
            wall: { before: hingeWall(before), after: hingeWall(after) },
            index: { before: before.floorSlopeEdge, after: after.floorSlopeEdge },
            slope: after.floorSlope,
        }));
        JS
    );

    // The weld really did change the list — otherwise this test proves nothing.
    expect($answer['corners']['after'])->toBeGreaterThan($answer['corners']['before']);

    // The index moved, and the wall it names did not. That is the whole point.
    expect($answer['index']['after'])->not->toBe($answer['index']['before']);
    expect($answer['wall']['after'])->toBe($answer['wall']['before']);
    expect($answer['slope'])->toEqual(0.5);
});

it('drops the slope when a carve takes the hinge wall away', function (): void {
    $answer = hingedLevel(
        // The blade swallows the hall's whole north wall, so the wall the floor
        // was hinged on no longer exists. Better to lie flat than to hinge on
        // whichever wall happened to inherit the index.
        "[box('hall', 0, 0, 10, 10, { floorSlope: 0.5, floorSlopeEdge: 2 }), box('blade', -2, 6, 12, 12)]",
        <<<'JS'
        const carved = carveRooms(level, 1);
        const hall = carved.sectors.find((s) => s.slug === 'hall');

        process.stdout.write(JSON.stringify({
            slope: hall.floorSlope,
            hinge: hall.floorSlopeEdge,
        }));
        JS
    );

    expect($answer['slope'])->toEqual(0.0)
        ->and($answer['hinge'])->toBeNull();
});

it('leaves a flat room flat through a carve', function (): void {
    $answer = hingedLevel(
        "[box('hall', 0, 0, 10, 10), box('blade', 4, 4, 6, 6)]",
        <<<'JS'
        const carved = carveRooms(level, 1);

        process.stdout.write(JSON.stringify({
            slopes: carved.sectors.map((s) => s.floorSlope),
            hinges: carved.sectors.map((s) => s.floorSlopeEdge),
        }));
        JS
    );

    expect(array_unique($answer['slopes']))->toEqual([0.0]);
    expect(array_unique($answer['hinges']))->toEqual([null]);
});

it('survives a room that has no slope fields at all', function (): void {
    // Levels authored before slopes existed arrive without these keys, and so
    // do fixtures written against the old shape. Neither is a crash.
    $answer = hingedLevel(
        "[box('hall', 0, 0, 10, 10)]",
        <<<'JS'
        const bare = { ...level.sectors[0] };
        delete bare.floorSlope;
        delete bare.floorSlopeEdge;
        delete bare.ceilingSlope;
        delete bare.ceilingSlopeEdge;

        process.stdout.write(JSON.stringify({
            kept: keepHinges(bare, bare.points),
        }));
        JS
    );

    expect($answer['kept']['floorSlope'])->toEqual(0.0)
        ->and($answer['kept']['floorSlopeEdge'])->toBeNull()
        ->and($answer['kept']['ceilingSlope'])->toEqual(0.0)
        ->and($answer['kept']['ceilingSlopeEdge'])->toBeNull();
});
