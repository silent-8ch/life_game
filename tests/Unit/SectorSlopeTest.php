<?php

use App\Models\LevelSector;
use App\Models\LevelSectorEdge;
use App\Models\LevelVertex;
use Symfony\Component\Process\Process;

/**
 * How high a sloped floor is at a spot, and whether both languages agree.
 *
 * `floorHeight` no longer means "how high this floor is". It means how high it
 * is **along its hinge wall**, and it rises from there into the room. That is
 * Build's convention and it is the whole reason two rooms hinged on the wall
 * between them meet flush for nothing: `inwardNormal` points opposite ways for
 * the two sides, so each rises away from the other.
 *
 * The engine draws from the TypeScript and the server validates with the PHP.
 * If those two disagree the level that saves is not the level that is drawn, so
 * the last test here runs both over the same rooms and compares.
 */

/**
 * @return array<string, mixed>
 */
function sectorHeights(string $body): array
{
    $script = <<<JS
        const { floorAt, ceilingAt, heightsAlong } = await import(
            '@/lib/engine/sectors.ts'
        );

        const room = (points, extra = {}) => ({
            slug: 'only',
            name: 'only',
            floorHeight: 0,
            ceilingHeight: 3,
            floorTexture: null,
            ceilingTexture: null,
            wallTexture: null,
            isSky: false,
            isWater: false,
            floorSlope: 0,
            floorSlopeEdge: null,
            ceilingSlope: 0,
            ceilingSlopeEdge: null,
            points,
            ...extra,
        });

        const square = (x, z, across) => [
            { x, z },
            { x: x + across, z },
            { x: x + across, z: z + across },
            { x, z: z + across },
        ];

        const round = (value) => Number(value.toFixed(6));

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

it('leaves a flat room flat', function (): void {
    $answer = sectorHeights(<<<'JS'
        const flat = room(square(0, 0, 10));

        process.stdout.write(JSON.stringify({
            corner: round(floorAt(flat, 0, 0)),
            middle: round(floorAt(flat, 5, 5)),
            far: round(floorAt(flat, 10, 10)),
            ceiling: round(ceilingAt(flat, 5, 5)),
        }));
        JS);

    expect($answer)->toBe([
        'corner' => 0,
        'middle' => 0,
        'far' => 0,
        'ceiling' => 3,
    ]);
});

it('rises into the room and not out of it', function (): void {
    $answer = sectorHeights(<<<'JS'
        // Hinged on the south wall — corner 0 to corner 1, running west to east
        // along z = 0 — rising a quarter metre for every metre north.
        const ramp = room(square(0, 0, 10), {
            floorSlope: 0.25,
            floorSlopeEdge: 0,
        });

        process.stdout.write(JSON.stringify({
            onTheHinge: [round(floorAt(ramp, 0, 0)), round(floorAt(ramp, 10, 0))],
            oneIn: round(floorAt(ramp, 5, 1)),
            fourIn: round(floorAt(ramp, 5, 4)),
            farSide: round(floorAt(ramp, 5, 10)),
        }));
        JS);

    // Flat along the hinge wall itself, whatever the slope, and climbing with
    // distance from it. A negative would mean the room dropped away from its
    // own base height, which is how a hinge on the wrong side reads.
    expect($answer['onTheHinge'])->toBe([0, 0])
        ->and($answer['oneIn'])->toEqual(0.25)
        ->and($answer['fourIn'])->toEqual(1)
        ->and($answer['farSide'])->toEqual(2.5);
});

it('lets two rooms meet flush along the wall they share', function (): void {
    $answer = sectorHeights(<<<'JS'
        // Two ten-metre rooms sharing the wall at z = 10, each hinged on it at
        // the same base height and each rising away into itself.
        const south = room([
            { x: 0, z: 0 }, { x: 10, z: 0 }, { x: 10, z: 10 }, { x: 0, z: 10 },
        ], { floorHeight: 1, floorSlope: 0.2, floorSlopeEdge: 2 });

        const north = room([
            { x: 0, z: 10 }, { x: 10, z: 10 }, { x: 10, z: 20 }, { x: 0, z: 20 },
        ], { floorHeight: 1, floorSlope: 0.2, floorSlopeEdge: 0 });

        process.stdout.write(JSON.stringify({
            southOnTheWall: round(floorAt(south, 5, 10)),
            northOnTheWall: round(floorAt(north, 5, 10)),
            southAway: round(floorAt(south, 5, 0)),
            northAway: round(floorAt(north, 5, 20)),
        }));
        JS);

    // Flush on the shared wall to the millimetre, and both climbing away from
    // it. This is the whole design: no arithmetic in the editor, no matching of
    // corner heights, just the same hinge and the same base.
    expect($answer['southOnTheWall'])->toEqual(1)
        ->and($answer['northOnTheWall'])->toEqual(1)
        ->and($answer['southAway'])->toEqual(3)
        ->and($answer['northAway'])->toEqual(3);
});

it('falls back to the base height when the hinge is gone', function (): void {
    $answer = sectorHeights(<<<'JS'
        // An old row whose hinge wall was carved away, so the index is past the
        // end of the point list.
        const stale = room(square(0, 0, 10), {
            floorHeight: 2,
            floorSlope: 0.5,
            floorSlopeEdge: 9,
        });

        const noHinge = room(square(0, 0, 10), {
            floorHeight: 2,
            floorSlope: 0.5,
            floorSlopeEdge: null,
        });

        process.stdout.write(JSON.stringify({
            stale: round(floorAt(stale, 5, 5)),
            noHinge: round(floorAt(noHinge, 5, 5)),
        }));
        JS);

    // Flat is the honest answer, not a crash and not a guess at which wall was
    // meant.
    expect($answer['stale'])->toEqual(2)
        ->and($answer['noHinge'])->toEqual(2);
});

it('hands a wall the four numbers it needs', function (): void {
    $answer = sectorHeights(<<<'JS'
        const ramp = room(square(0, 0, 10), {
            floorSlope: 0.25,
            floorSlopeEdge: 0,
            ceilingSlope: 0.1,
            ceilingSlopeEdge: 0,
        });

        // The east wall, running from the hinge end to the far end.
        const along = heightsAlong(ramp, { x: 10, z: 0 }, { x: 10, z: 10 });

        process.stdout.write(JSON.stringify({
            along: Object.fromEntries(
                Object.entries(along).map(([key, value]) => [key, round(value)]),
            ),
        }));
        JS);

    // Both surfaces are planes, so along a straight wall they are linear and
    // the ends are the extremes. Nothing in the middle has to be sampled.
    expect($answer['along'])->toBe([
        'floorFrom' => 0,
        'floorTo' => 2.5,
        'ceilingFrom' => 3,
        'ceilingTo' => 4,
    ]);
});

it('agrees with the PHP that validates what it draws', function (): void {
    // The engine draws from the TypeScript and the server validates with the
    // PHP. If those two disagree, the level that saves is not the level that is
    // drawn — and it disagrees quietly, in the third decimal, which is the
    // worst way for it to happen. So both are run over the same room and the
    // answers compared. Nothing is saved: the model is built in memory the same
    // way tests/Feature/SectorSlopeDataTest.php does it.
    $sector = new LevelSector([
        'floor_height' => 1.5,
        'ceiling_height' => 4.0,
        'floor_slope' => 0.25,
        'floor_slope_edge' => 0,
        'ceiling_slope' => -0.1,
        'ceiling_slope_edge' => 2,
    ]);

    $corners = [[0.0, 0.0], [10.0, 0.0], [10.0, 10.0], [0.0, 10.0]];

    $sector->setRelation('edges', collect($corners)->map(
        fn (array $corner): LevelSectorEdge => tap(
            new LevelSectorEdge,
            fn (LevelSectorEdge $edge) => $edge->setRelation(
                'vertex',
                new LevelVertex(['x' => $corner[0], 'z' => $corner[1]]),
            )
        )
    ));

    $spots = [[0, 0], [5, 5], [10, 10], [2.5, 7.5], [10, 0], [0, 10]];

    $php = array_map(fn (array $spot): array => [
        round($sector->floorAt($spot[0], $spot[1]), 6),
        round($sector->ceilingAt($spot[0], $spot[1]), 6),
    ], $spots);

    $asked = json_encode($spots, JSON_THROW_ON_ERROR);

    $answer = sectorHeights(<<<JS
        const ramp = room(square(0, 0, 10), {
            floorHeight: 1.5,
            ceilingHeight: 4,
            floorSlope: 0.25,
            floorSlopeEdge: 0,
            ceilingSlope: -0.1,
            ceilingSlopeEdge: 2,
        });

        process.stdout.write(JSON.stringify({
            heights: {$asked}.map(([x, z]) => [
                round(floorAt(ramp, x, z)),
                round(ceilingAt(ramp, x, z)),
            ]),
        }));
        JS);

    expect($answer['heights'])->toEqual($php);
});
