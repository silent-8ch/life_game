<?php

use Symfony\Component\Process\Process;

/**
 * Which rooms lead to which, and the way through.
 *
 * The people could not find doorways. Not a tuning problem: wandering picked a
 * point anywhere in the level's bounding box, checked only that *some* room was
 * under it, and walked at it in a straight line with collision to slide along
 * whatever it met. A doorway is a metre-wide gap, and a straight line from one
 * room to a point in another almost never goes through one — so a person
 * scraped along the nearest wall until a timer gave up and picked somewhere
 * else, quite possibly somewhere they could never reach.
 *
 * The graph this replaces it with was already being computed. `boundaries.ts`
 * asks of every boundary whether the climb and the headroom let somebody
 * through, and used the answer only to place a collider.
 */

/**
 * @param  string  $sectors  A JavaScript array of rooms.
 * @return array<string, mixed>
 */
function navAnswer(string $sectors, string $body): array
{
    $script = <<<JS
        const { buildNavGraph, somewhereIn } = await import(
            '@/lib/engine/navigation.ts'
        );
        const { sectorAt } = await import('@/lib/engine/sectors.ts');

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
            floorSlope: 0,
            floorSlopeEdge: null,
            ceilingSlope: 0,
            ceilingSlopeEdge: null,
            points,
            ...extra,
        });

        const level = { slug: 'test', name: 'test', sectors: {$sectors}, things: [] };
        const graph = buildNavGraph(level);

        /** Anybody on foot: half a metre of step, a metre of headroom. */
        const onFoot = (way) => way.climb <= 0.55 && way.headroom >= 1.2;
        const anything = () => true;

        const round = (value) => Number(value.toFixed(3));

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

/**
 * Two rooms side by side with a doorway between them: the shared wall is one
 * metre of the five, and open.
 */
const TWO_ROOMS = <<<'JS'
[
    room('west', [
        corner(0, 0), corner(5, 0, { blocks: true }), corner(5, 2),
        corner(5, 3, { blocks: true }), corner(5, 5, { blocks: true }),
        corner(0, 5, { blocks: true }),
    ]),
    room('east', [
        corner(5, 0), corner(10, 0), corner(10, 5), corner(5, 5),
        corner(5, 3), corner(5, 2),
    ]),
]
JS;

it('finds the way from one room to the next', function (): void {
    $answer = navAnswer(TWO_ROOMS, <<<'JS'
        const route = graph.routeBetween('west', 'east', onFoot);

        process.stdout.write(JSON.stringify({
            steps: route === null ? null : route.length,
            through: route === null ? null : route.map((way) => ({
                to: way.to,
                at: [round(way.at.x), round(way.at.z)],
            })),
            reachable: graph.reachableFrom('west', onFoot).sort(),
        }));
        JS);

    // One doorway, and the waypoint is the middle of the opening — which is the
    // whole point. Walking at the far room's centre from the west room goes
    // through a wall; walking at the middle of the gap goes through the gap.
    expect($answer['steps'])->toBe(1)
        ->and($answer['through'][0]['to'])->toBe('east')
        ->and($answer['through'][0]['at'])->toEqual([5, 2.5])
        ->and($answer['reachable'])->toBe(['east', 'west']);
});

it('will not route through a wall', function (): void {
    $answer = navAnswer(
        // The same pair with the shared wall closed from one side. Passability
        // belongs to the boundary, so one side saying no is enough.
        str_replace('corner(5, 2),', 'corner(5, 2, { blocks: true }),', TWO_ROOMS),
        <<<'JS'
        process.stdout.write(JSON.stringify({
            route: graph.routeBetween('west', 'east', onFoot),
            reachable: graph.reachableFrom('west', onFoot),
        }));
        JS
    );

    expect($answer['route'])->toBeNull()
        ->and($answer['reachable'])->toBe(['west']);
});

it('leaves what a walker can manage to the walker', function (): void {
    $answer = navAnswer(
        // A metre step up into the east room: too much to walk, fine to climb.
        str_replace('corner(10, 5), corner(5, 5),', 'corner(10, 5), corner(5, 5),', TWO_ROOMS),
        <<<'JS'
        const raised = JSON.parse(JSON.stringify(level));
        raised.sectors[1].floorHeight = 1;

        const climbing = buildNavGraph(raised);
        const way = climbing.waysOut('west')[0];

        process.stdout.write(JSON.stringify({
            climb: round(way.climb),
            onFoot: climbing.routeBetween('west', 'east', onFoot),
            anything: climbing.routeBetween('west', 'east', anything)?.length ?? null,
        }));
        JS
    );

    // The graph carries what the opening costs and refuses to decide whether
    // that is too much. MAX_STEP is about to become a runtime decision, and a
    // graph with it baked in would be wrong the day that lands.
    expect($answer['climb'])->toEqual(1)
        ->and($answer['onFoot'])->toBeNull()
        ->and($answer['anything'])->toBe(1);
});

it('treats a portal as a way through', function (): void {
    $answer = navAnswer(
        <<<'JS'
        [
            room('here', [
                corner(0, 0), corner(4, 0),
                corner(4, 4, { blocks: true, portalLink: 'hop' }),
                corner(0, 4, { blocks: true }),
            ]),
            room('far', [
                corner(50, 0), corner(54, 0),
                corner(54, 4, { blocks: true }),
                corner(50, 4, { blocks: true, portalLink: 'hop' }),
            ]),
        ]
        JS,
        <<<'JS'
        const route = graph.routeBetween('here', 'far', onFoot);

        process.stdout.write(JSON.stringify({
            steps: route?.length ?? null,
            portal: route?.[0]?.portal ?? null,
            climb: route === null ? null : round(route[0].climb),
        }));
        JS
    );

    // Fifty metres apart on the plan and next door to each other in fact. A
    // portal carries you bodily, so there is no step to climb and no lintel to
    // duck however far the two floors are apart.
    expect($answer['steps'])->toBe(1)
        ->and($answer['portal'])->toBeTrue()
        ->and($answer['climb'])->toEqual(0);
});

it('picks a spot inside the room it was asked about', function (): void {
    $answer = navAnswer(TWO_ROOMS, <<<'JS'
        const east = level.sectors[1];
        const spots = [];

        for (let attempt = 0; attempt < 40; attempt++) {
            const spot = somewhereIn(east, level.sectors);

            if (spot !== null) {
                spots.push(sectorAt(level.sectors, spot.x, spot.z)?.slug ?? null);
            }
        }

        process.stdout.write(JSON.stringify({
            found: spots.length,
            rooms: [...new Set(spots)],
        }));
        JS);

    // A room is a polygon and its bounding box is not, so a spot has to be
    // checked against the room rather than against its box — the old aim
    // checked only that *a* room was under the point, which is how people ended
    // up walking at somewhere they could not reach.
    expect($answer['found'])->toBeGreaterThan(30)
        ->and($answer['rooms'])->toBe(['east']);
});
