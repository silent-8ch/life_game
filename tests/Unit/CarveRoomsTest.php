<?php

use Symfony\Component\Process\Process;

/**
 * What happens to the rooms already on the floor plan when a new one is drawn
 * over them.
 *
 * Rooms are not allowed to overlap, so closing a shape subtracts it from every
 * room it lands on. A sector is one closed loop and cannot have a hole, so a
 * room drawn wholly inside another leaves a ring the model cannot hold: the
 * remainder is cut into horizontal slabs and each slab becomes a room. A
 * stretch of wall that survives the cut keeps its texture and its flags, and
 * every corner one room drops partway along another's wall is welded into that
 * wall — without which the two rooms touch but do not name the same pair of
 * corners, and the engine never sees a doorway there.
 */

/**
 * Carves a level in the editor and answers a question about the result.
 *
 * @param  string  $sectors  A JavaScript array of rooms.
 * @return array<string, mixed>
 */
function carvedLevel(string $sectors, string $body): array
{
    $script = <<<JS
        const clipping = (await import('polygon-clipping')).default;

        const { carveRooms, weldCorners } = await import('@/lib/editor/carve.ts');
        const { twinEdge, windingOf } = await import('@/lib/editor/map.ts');

        const corner = (x, z, extra = {}) => ({
            x,
            z,
            wallTexture: null,
            blocks: false,
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

        /** A rectangular room, by the two corners a drag would give it. */
        const box = (slug, minX, minZ, maxX, maxZ, extra = {}) =>
            room(
                slug,
                [
                    corner(minX, minZ),
                    corner(maxX, minZ),
                    corner(maxX, maxZ),
                    corner(minX, maxZ),
                ],
                extra,
            );

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
            sectors: {$sectors},
        };

        const round = (value) => Number(value.toFixed(4));

        const near = (point, [x, z]) =>
            Math.abs(point.x - x) < 1e-6 && Math.abs(point.z - z) < 1e-6;

        /** A room's corners, in the order it holds them. */
        const shapeOf = (sector) => sector.points.map(({ x, z }) => [round(x), round(z)]);

        const areaOf = (sector) => round(Math.abs(windingOf(sector.points)) / 2);

        const boundsOf = (sector) => [
            round(Math.min(...sector.points.map((point) => point.x))),
            round(Math.min(...sector.points.map((point) => point.z))),
            round(Math.max(...sector.points.map((point) => point.x))),
            round(Math.max(...sector.points.map((point) => point.z))),
        ];

        /** Every room reduced to what it covers and what it is called. */
        const roomsIn = (carved) =>
            carved.sectors.map((sector) => ({
                slug: sector.slug,
                name: sector.name,
                bounds: boundsOf(sector),
                area: areaOf(sector),
                // The engine reads the winding to know which side of a wall
                // faces into the room, so a carved piece has to come back
                // wound the way a drawn one is.
                windsRight: windingOf(sector.points) > 0,
            }));

        /** The wall between two corners, whichever way round the room holds it. */
        const wallOn = (sector, from, to) => {
            const found = sector.points.find((point, index) => {
                const next = sector.points[(index + 1) % sector.points.length];

                return (
                    (near(point, from) && near(next, to)) ||
                    (near(point, to) && near(next, from))
                );
            });

            return found === undefined
                ? null
                : {
                      wallTexture: found.wallTexture,
                      blocks: found.blocks,
                      isMirror: found.isMirror,
                      isSky: found.isSky,
                      portalLink: found.portalLink,
                  };
        };

        /**
         * Whether every wall of one room is named, corner for corner, by the
         * room on the other side of it — which is what the engine looks for
         * before it will treat a boundary as a way through.
         */
        const wallsSharedBy = (carved, index) =>
            carved.sectors[index].points.map(
                (point, edge) => twinEdge(carved, index, edge) !== null,
            );

        const ringOf = (sector) => {
            const ring = sector.points.map(({ x, z }) => [x, z]);
            ring.push(ring[0]);

            return [ring];
        };

        /** Pairs of rooms covering the same patch of floor, which is not allowed. */
        const overlapping = (carved) => {
            const found = [];

            for (let i = 0; i < carved.sectors.length; i++) {
                for (let j = i + 1; j < carved.sectors.length; j++) {
                    const shared = clipping
                        .intersection([ringOf(carved.sectors[i])], [ringOf(carved.sectors[j])])
                        .reduce(
                            (total, polygon) =>
                                total +
                                Math.abs(
                                    windingOf(
                                        polygon[0]
                                            .slice(0, -1)
                                            .map(([x, z]) => ({ x, z })),
                                    ),
                                ) /
                                    2,
                            0,
                        );

                    if (shared > 1e-4) {
                        found.push([
                            carved.sectors[i].slug,
                            carved.sectors[j].slug,
                            round(shared),
                        ]);
                    }
                }
            }

            return found;
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

it('cuts a room drawn inside another into four rooms around it', function (): void {
    $answer = carvedLevel(
        "[box('hall', 0, 0, 10, 10), box('inner', 3, 3, 7, 7)]",
        <<<'JS'
        const carved = carveRooms(level, 1);

        process.stdout.write(JSON.stringify({
            rooms: roomsIn(carved),
            overlaps: overlapping(carved),
        }));
        JS
    );

    // A ring is not a shape a sector can hold, so the remainder comes back as
    // four slabs: the band below the new room, the two beside it, the band
    // above. The room that was cut keeps its own slug; the other three are
    // named against the slugs already spoken for.
    expect($answer['rooms'])->toEqual([
        ['slug' => 'hall', 'name' => 'hall', 'bounds' => [0, 0, 10, 3], 'area' => 30, 'windsRight' => true],
        ['slug' => 'room', 'name' => 'hall 2', 'bounds' => [0, 3, 3, 7], 'area' => 12, 'windsRight' => true],
        ['slug' => 'room-2', 'name' => 'hall 3', 'bounds' => [7, 3, 10, 7], 'area' => 12, 'windsRight' => true],
        ['slug' => 'room-3', 'name' => 'hall 4', 'bounds' => [0, 7, 10, 10], 'area' => 30, 'windsRight' => true],
        // The room that did the cutting is left alone, and ends up last.
        ['slug' => 'inner', 'name' => 'inner', 'bounds' => [3, 3, 7, 7], 'area' => 16, 'windsRight' => true],
    ]);

    // 30 + 12 + 12 + 30 + 16 is the hundred square metres there were to start
    // with: the cut moved floor between rooms rather than losing any.
    expect(array_sum(array_column($answer['rooms'], 'area')))->toEqual(100)
        ->and($answer['overlaps'])->toBe([]);
});

it('shares the corners of a room drawn inside another with the rooms around it', function (): void {
    $answer = carvedLevel(
        "[box('hall', 0, 0, 10, 10), box('inner', 3, 3, 7, 7)]",
        <<<'JS'
        const carved = carveRooms(level, 1);

        process.stdout.write(JSON.stringify({
            below: shapeOf(carved.sectors[0]),
            inner: shapeOf(carved.sectors[4]),
            innerShared: wallsSharedBy(carved, 4),
        }));
        JS
    );

    // The slab below the new room runs the full ten metres, so the new room's
    // two corners land partway along its north wall. Welding puts them in —
    // without them the rooms touch but name different corners, and the engine
    // sees a solid wall rather than a doorway.
    expect($answer['below'])->toEqual([[0, 0], [10, 0], [10, 3], [7, 3], [3, 3], [0, 3]])
        ->and($answer['inner'])->toEqual([[3, 3], [7, 3], [7, 7], [3, 7]])
        // All four of the new room's walls are now named by the room on the
        // other side of them, corner for corner.
        ->and($answer['innerShared'])->toBe([true, true, true, true]);
});

it('inserts a corner where one room meets another partway along its wall', function (): void {
    $answer = carvedLevel(
        // Two rooms side by side, the right one half the height of the left, so
        // its corners land halfway along the left one's east wall.
        "[box('hall', 0, 0, 10, 10), box('annexe', 10, 0, 16, 5)]",
        <<<'JS'
        const welded = weldCorners(level);

        process.stdout.write(JSON.stringify({
            before: wallsSharedBy(level, 1),
            hall: shapeOf(welded.sectors[0]),
            after: wallsSharedBy(welded, 1),
        }));
        JS
    );

    // Before welding the two rooms touch along six metres of wall and share not
    // one edge; afterwards the corner at (10, 5) is in both rooms and the wall
    // between them is named the same way by each.
    expect($answer['before'])->toBe([false, false, false, false])
        ->and($answer['hall'])->toEqual([[0, 0], [10, 0], [10, 5], [10, 10], [0, 10]])
        ->and($answer['after'])->toBe([false, false, false, true]);
});

it('keeps the wall it welds a corner into on both halves of it', function (): void {
    $answer = carvedLevel(
        "[
            room('hall', [
                corner(0, 0),
                corner(10, 0, { wallTexture: 'brick', blocks: true, isMirror: true, portalLink: 'a' }),
                corner(10, 10),
                corner(0, 10),
            ]),
            box('annexe', 10, 0, 16, 5),
        ]",
        <<<'JS'
        const welded = weldCorners(level);

        process.stdout.write(JSON.stringify({
            lower: wallOn(welded.sectors[0], [10, 0], [10, 5]),
            upper: wallOn(welded.sectors[0], [10, 5], [10, 10]),
        }));
        JS
    );

    // Splitting a wall must not change what the wall is, or a weld would quietly
    // open up half of a solid wall. Both halves keep the brick and the mirror.
    // The portal link is the exception: it names a whole wall and one partner,
    // so it cannot survive being halved.
    $wall = [
        'wallTexture' => 'brick',
        'blocks' => true,
        'isMirror' => true,
        'isSky' => false,
        'portalLink' => null,
    ];

    expect($answer['lower'])->toEqual($wall)
        ->and($answer['upper'])->toEqual($wall);
});

it('keeps the texture and the flags of a wall a cut left standing', function (): void {
    $answer = carvedLevel(
        // A room whose south wall is brick, solid, mirrored, with a doorway cut
        // into the middle of it by a room drawn across it from outside.
        "[
            room('hall', [
                corner(0, 0, { wallTexture: 'brick', blocks: true, isMirror: true, isSky: true, portalLink: 'a' }),
                corner(10, 0, { wallTexture: 'plaster', blocks: true }),
                corner(10, 10),
                corner(0, 10),
            ]),
            box('porch', 3, -2, 7, 2),
        ]",
        <<<'JS'
        const carved = carveRooms(level, 1);

        process.stdout.write(JSON.stringify({
            shape: shapeOf(carved.sectors[0]),
            westOfTheGap: wallOn(carved.sectors[0], [0, 0], [3, 0]),
            eastOfTheGap: wallOn(carved.sectors[0], [7, 0], [10, 0]),
            madeByTheCut: wallOn(carved.sectors[0], [3, 0], [3, 2]),
            backOfTheGap: wallOn(carved.sectors[0], [3, 2], [7, 2]),
            untouched: wallOn(carved.sectors[0], [10, 0], [10, 10]),
        }));
        JS
    );

    // The two stretches of the old south wall that survived are still brick,
    // still solid, still mirrors — but no longer half of a portal, because the
    // wall on the other end of that link is not this wall any more.
    $survivor = [
        'wallTexture' => 'brick',
        'blocks' => true,
        'isMirror' => true,
        'isSky' => true,
        'portalLink' => null,
    ];

    // Edges the cut made start open and untextured, so the boundary the two
    // rooms now share is a doorway by default.
    $made = [
        'wallTexture' => null,
        'blocks' => false,
        'isMirror' => false,
        'isSky' => false,
        'portalLink' => null,
    ];

    expect($answer['shape'])
        ->toEqual([[0, 0], [3, 0], [3, 2], [7, 2], [7, 0], [10, 0], [10, 10], [0, 10]])
        ->and($answer['westOfTheGap'])->toEqual($survivor)
        ->and($answer['eastOfTheGap'])->toEqual($survivor)
        ->and($answer['madeByTheCut'])->toEqual($made)
        ->and($answer['backOfTheGap'])->toEqual($made)
        // A wall the cut never reached is left exactly as it was.
        ->and($answer['untouched'])->toEqual([
            'wallTexture' => 'plaster',
            'blocks' => true,
            'isMirror' => false,
            'isSky' => false,
            'portalLink' => null,
        ]);
});

it('splits a room a new one runs across into two rooms of their own', function (): void {
    $answer = carvedLevel(
        // A corridor drawn right through a room, out both sides.
        "[box('hall', 0, 0, 10, 10), box('corridor', -1, 4, 11, 6)]",
        <<<'JS'
        const carved = carveRooms(level, 1);

        process.stdout.write(JSON.stringify({
            rooms: roomsIn(carved),
            overlaps: overlapping(carved),
        }));
        JS
    );

    // Two pieces with nothing joining them cannot be one sector, so the room
    // becomes two — the first keeping the slug the level already knows it by,
    // the second given one no other room is using.
    expect($answer['rooms'])->toEqual([
        ['slug' => 'hall', 'name' => 'hall', 'bounds' => [0, 0, 10, 4], 'area' => 40, 'windsRight' => true],
        ['slug' => 'room', 'name' => 'hall 2', 'bounds' => [0, 6, 10, 10], 'area' => 40, 'windsRight' => true],
        ['slug' => 'corridor', 'name' => 'corridor', 'bounds' => [-1, 4, 11, 6], 'area' => 24, 'windsRight' => true],
    ])->and($answer['overlaps'])->toBe([]);
});

it('deletes a room the new one covers completely', function (): void {
    $answer = carvedLevel(
        "[box('cupboard', 3, 3, 7, 7), box('hall', 0, 0, 10, 10)]",
        <<<'JS'
        const carved = carveRooms(level, 1);

        process.stdout.write(JSON.stringify({ rooms: roomsIn(carved) }));
        JS
    );

    // Nothing is left of the old room, so it goes rather than lingering as an
    // empty sector inside the new one.
    expect($answer['rooms'])->toEqual([
        ['slug' => 'hall', 'name' => 'hall', 'bounds' => [0, 0, 10, 10], 'area' => 100, 'windsRight' => true],
    ]);
});

it('leaves a room the new one never touches exactly as it was', function (): void {
    $answer = carvedLevel(
        "[
            room('hall', [
                corner(0, 0, { wallTexture: 'brick', blocks: true, portalLink: 'a' }),
                corner(10, 0),
                corner(10, 10),
                corner(0, 10),
            ]),
            box('shed', 20, 20, 24, 24),
        ]",
        <<<'JS'
        const carved = carveRooms(level, 1);

        process.stdout.write(JSON.stringify({
            rooms: carved.sectors.map((sector) => sector.slug),
            hall: carved.sectors[0],
        }));
        JS
    );

    // A room the new one does not overlap is not cut, not renamed, and — since
    // no corner of the new room lands on any of its walls — not welded either,
    // so even its portal link survives.
    expect($answer['rooms'])->toBe(['hall', 'shed'])
        ->and($answer['hall']['points'][0])->toEqual([
            'x' => 0,
            'z' => 0,
            'wallTexture' => 'brick',
            'blocks' => true,
            'isMirror' => false,
            'isSky' => false,
            'portalLink' => 'a',
        ]);
});

it('cuts a room around a shape whose walls do not run with the grid', function (): void {
    $answer = carvedLevel(
        // A diamond: none of its walls is level, so the slabs are cut at the
        // heights of its corners and each one comes out a different shape.
        "[
            box('hall', 0, 0, 10, 10),
            room('gem', [corner(5, 3), corner(7, 5), corner(5, 7), corner(3, 5)]),
        ]",
        <<<'JS'
        const carved = carveRooms(level, 1);

        process.stdout.write(JSON.stringify({
            shapes: carved.sectors.map((sector) => shapeOf(sector)),
            area: round(carved.sectors.reduce(
                (total, sector) => total + Math.abs(windingOf(sector.points)) / 2,
                0,
            )),
            windsRight: carved.sectors.every((sector) => windingOf(sector.points) > 0),
            overlaps: overlapping(carved),
            gemShared: wallsSharedBy(carved, 6),
        }));
        JS
    );

    // Six slabs, cut at z = 3, 5 and 7 where the diamond's corners sit, each a
    // simple loop with the sloping walls of the diamond for one side.
    expect($answer['shapes'])->toEqual([
        [[0, 0], [10, 0], [10, 3], [5, 3], [0, 3]],
        [[0, 3], [5, 3], [3, 5], [0, 5]],
        [[5, 3], [10, 3], [10, 5], [7, 5]],
        [[0, 5], [3, 5], [5, 7], [0, 7]],
        [[5, 7], [7, 5], [10, 5], [10, 7]],
        [[0, 7], [5, 7], [10, 7], [10, 10], [0, 10]],
        [[5, 3], [7, 5], [5, 7], [3, 5]],
    ]);

    // No floor lost, none of it covered twice, every piece wound the way the
    // engine reads, and every wall of the diamond shared with the slab beside
    // it rather than left as a T-junction.
    expect($answer['area'])->toEqual(100)
        ->and($answer['windsRight'])->toBeTrue()
        ->and($answer['overlaps'])->toBe([])
        ->and($answer['gemShared'])->toBe([true, true, true, true]);
});

it('keeps a wall that survives a cut across a corner in the middle of it', function (): void {
    $answer = carvedLevel(
        // A south wall authored as two runs, the way a level splits a wall to
        // put a doorway or a different texture in part of it.
        "[
            room('hall', [
                corner(0, 0, { wallTexture: 'brick', blocks: true }),
                corner(5, 0, { wallTexture: 'plaster', blocks: true }),
                corner(10, 0),
                corner(10, 10),
                corner(0, 10),
            ]),
            box('wing', -1, -1, 2, 11),
        ]",
        <<<'JS'
        const carved = carveRooms(level, 1);

        process.stdout.write(JSON.stringify({
            shape: shapeOf(carved.sectors[0]),
            brick: wallOn(carved.sectors[0], [2, 0], [5, 0]),
            plaster: wallOn(carved.sectors[0], [5, 0], [10, 0]),
        }));
        JS
    );

    // The cut takes the western three metres of the room. Everything east of
    // x = 2 is untouched floor, so both runs of the south wall should come
    // through with their texture and their solid flag.
    expect($answer['shape'])->toEqual([[2, 0], [5, 0], [10, 0], [10, 10], [2, 10]])
        ->and($answer['brick']['wallTexture'])->toBe('brick')
        ->and($answer['brick']['blocks'])->toBeTrue()
        ->and($answer['plaster']['wallTexture'])->toBe('plaster')
        ->and($answer['plaster']['blocks'])->toBeTrue();
});

it('throws away a shard a carve leaves behind', function (): void {
    // A blade shaped to swallow the whole room bar a quarter-metre square in
    // one corner. That square is a real result of the cut, six hundred times
    // bigger than the arithmetic dust MIN_AREA catches, and it is not a room
    // anybody wanted — this is the shape that turned up on the corner of a
    // portal mouth with a fifteen-metre ceiling, blocking the view through it.
    $answer = carvedLevel(
        "[box('hall', 0, 0, 10, 10), room('blade', [corner(0.25, -1), corner(12, -1), corner(12, 12), corner(-1, 12), corner(-1, 0.25), corner(0.25, 0.25)])]",
        <<<'JS'
        const carved = carveRooms(level, 1);

        process.stdout.write(JSON.stringify({
            slugs: carved.sectors.map((s) => s.slug),
        }));
        JS
    );

    expect($answer['slugs'])->toBe(['blade']);
});

it('keeps a long thin room, which a width test alone would eat', function (): void {
    // Two metres by half a metre: by the width measure this is thinner than
    // most of the shards a carve throws up, and it is unmistakably a room —
    // it is the shape of every landing in the house.
    $answer = carvedLevel(
        "[box('landing', 0, 0, 2, 0.5), box('blade', 6, 6, 8, 8)]",
        <<<'JS'
        const carved = carveRooms(level, 1);

        process.stdout.write(JSON.stringify({
            slugs: carved.sectors.map((s) => s.slug),
            landing: areaOf(carved.sectors.find((s) => s.slug === 'landing')),
        }));
        JS
    );

    expect($answer['slugs'])->toContain('landing')
        ->and($answer['landing'])->toEqual(1.0);
});
