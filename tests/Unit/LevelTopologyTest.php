<?php

use Symfony\Component\Process\Process;

/**
 * What the floor plan says about itself, before anything is drawn.
 *
 * Which wall ends carry straight on, which rooms an open doorway lets see each
 * other, and which portal links have both of their ends are all read once and
 * handed to the builders. Each of them used to be a closure inside buildLevel,
 * reachable only by building a level and measuring the geometry that came out;
 * these are the same rules asked directly.
 */

/**
 * @param  string  $sectors  A JavaScript array of rooms.
 * @return array<string, mixed>
 */
function levelTopology(string $sectors, string $body): array
{
    $script = <<<JS
        const { readTopology } = await import('@/lib/engine/build/topology.ts');

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

        const topology = readTopology({ sectors: {$sectors} });

        /** Every edge of a room, as `carriedOn` sees its two ends. */
        const endsOf = (slug) => {
            const ends = [];

            for (const [key, edge] of topology.edgeAt) {
                if (!key.startsWith(slug + '#')) {
                    continue;
                }

                ends.push({
                    at: key,
                    back: topology.carriedOn.back(edge),
                    front: topology.carriedOn.front(edge),
                });
            }

            return ends;
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

it('says a wall turning a corner has nothing carrying it on', function (): void {
    $answer = levelTopology(
        "[room('only', [corner(0, 0), corner(10, 0), corner(10, 10), corner(0, 10)])]",
        <<<'JS'
        process.stdout.write(JSON.stringify({ ends: endsOf('only') }));
        JS
    );

    // Four sides, each of which turns at both of its ends. Every one of them is
    // therefore drawn past both, which is what closes the notch at a corner.
    expect($answer['ends'])->toBe([
        ['at' => 'only#0', 'back' => false, 'front' => false],
        ['at' => 'only#1', 'back' => false, 'front' => false],
        ['at' => 'only#2', 'back' => false, 'front' => false],
        ['at' => 'only#3', 'back' => false, 'front' => false],
    ]);
});

it('sees a wall carried on where a long side is split in two', function (): void {
    $answer = levelTopology(
        // The same square, with an extra corner halfway up its east side.
        "[room('only', [corner(0, 0), corner(10, 0), corner(10, 5), corner(10, 10), corner(0, 10)])]",
        <<<'JS'
        process.stdout.write(JSON.stringify({ ends: endsOf('only') }));
        JS
    );

    // The two halves of the east side meet each other, so the first is carried
    // on at its far end and the second at the end it starts from. The overhang
    // would have put two faces in one plane, which is what flickered.
    expect($answer['ends'])->toBe([
        ['at' => 'only#0', 'back' => false, 'front' => false],
        ['at' => 'only#1', 'back' => false, 'front' => true],
        ['at' => 'only#2', 'back' => true, 'front' => false],
        ['at' => 'only#3', 'back' => false, 'front' => false],
        ['at' => 'only#4', 'back' => false, 'front' => false],
    ]);
});

it('lets a room see through an open doorway but not through a wall', function (): void {
    $rooms = <<<'JS'
    [
        room('west', [corner(0, 0), corner(10, 0), corner(10, 10), corner(0, 10)]),
        room('east', [corner(10, 0), corner(20, 0), corner(20, 10), corner(10, 10)]),
    ]
    JS;

    $open = levelTopology($rooms, <<<'JS'
        process.stdout.write(JSON.stringify({
            west: topology.seenFrom('west'),
            east: topology.seenFrom('east'),
        }));
        JS);

    expect($open['west'])->toBe(['west', 'east'])
        ->and($open['east'])->toBe(['east', 'west']);

    // One side calling the boundary solid is enough: passability belongs to the
    // boundary, not to one room, so neither of them sees the other any more.
    // Only the west room says so here; the east room's own face is left open.
    $walled = levelTopology(
        <<<'JS'
        [
            room('west', [
                corner(0, 0),
                corner(10, 0, { blocks: true }),
                corner(10, 10),
                corner(0, 10),
            ]),
            room('east', [corner(10, 0), corner(20, 0), corner(20, 10), corner(10, 10)]),
        ]
        JS,
        <<<'JS'
        process.stdout.write(JSON.stringify({
            west: topology.seenFrom('west'),
            east: topology.seenFrom('east'),
        }));
        JS
    );

    expect($walled['east'])->toBe(['east'])
        ->and($walled['west'])->toBe(['west']);
});

it('sees all the way down a run of open doorways, not one along it', function (): void {
    // Four rooms in a line, every boundary open. Standing in the first, the
    // fourth is in plain sight down the whole run.
    $rooms = <<<'JS'
    [
        room('a', [corner(0, 0), corner(10, 0), corner(10, 10), corner(0, 10)]),
        room('b', [corner(10, 0), corner(20, 0), corner(20, 10), corner(10, 10)]),
        room('c', [corner(20, 0), corner(30, 0), corner(30, 10), corner(20, 10)]),
        room('d', [corner(30, 0), corner(40, 0), corner(40, 10), corner(30, 10)]),
    ]
    JS;

    $answer = levelTopology($rooms, <<<'JS'
        process.stdout.write(JSON.stringify({
            a: topology.seenFrom('a').sort(),
            d: topology.seenFrom('d').sort(),
        }));
        JS);

    // This used to stop at one doorway, and Paul found it from both ends on the
    // demo: the mirror at least a room away cant see through the portal behind
    // me, and the portal can not see whats in the mirror. His nearest mirror was
    // two doorways beyond the portal's far room, so neither set reached the
    // other and both reflections sat frozen. One gate, two symptoms.
    //
    // A count of doorways was never what being able to see something is. Rooms
    // at the ends of a straight run are in plain sight of each other however
    // many openings lie between; rooms sharing one doorway round a corner are
    // not in sight at all. Wrong in both directions, and the frustum test is
    // what has always been doing the real work.
    expect($answer['a'])->toBe(['a', 'b', 'c', 'd'])
        ->and($answer['d'])->toBe(['a', 'b', 'c', 'd']);
});

it('stops at a wall however far the run goes on behind it', function (): void {
    // The same four, with the middle boundary shut from one side. Reachability
    // is still a filter and this is what it filters: two halves that cannot see
    // each other at all, however long each half is.
    $answer = levelTopology(
        <<<'JS'
        [
            room('a', [corner(0, 0), corner(10, 0), corner(10, 10), corner(0, 10)]),
            room('b', [
                corner(10, 0),
                corner(20, 0, { blocks: true }),
                corner(20, 10),
                corner(10, 10),
            ]),
            room('c', [corner(20, 0), corner(30, 0), corner(30, 10), corner(20, 10)]),
            room('d', [corner(30, 0), corner(40, 0), corner(40, 10), corner(30, 10)]),
        ]
        JS,
        <<<'JS'
        process.stdout.write(JSON.stringify({
            a: topology.seenFrom('a').sort(),
            d: topology.seenFrom('d').sort(),
        }));
        JS
    );

    expect($answer['a'])->toBe(['a', 'b'])
        ->and($answer['d'])->toBe(['c', 'd']);
});

it('counts a portal link by its walls, not by its faces', function (): void {
    $answer = levelTopology(
        <<<'JS'
        [
            room('west', [
                corner(0, 0),
                corner(10, 0),
                corner(10, 10, { portalLink: 'shared' }),
                corner(0, 10),
            ]),
            room('east', [
                corner(10, 0),
                corner(20, 0),
                corner(20, 10),
                corner(10, 10),
            ]),
            room('far', [
                corner(0, 20, { portalLink: 'lone' }),
                corner(10, 20),
                corner(10, 30),
                corner(0, 30),
            ]),
        ]
        JS,
        <<<'JS'
        process.stdout.write(JSON.stringify({
            shared: topology.portalEnds('shared'),
            lone: topology.portalEnds('lone'),
            missing: topology.portalEnds('nowhere'),
        }));
        JS
    );

    // 'shared' is set on the wall between the two rooms, which has a face each
    // way. Both faces name the same wall, so it is one end of a portal and not
    // two — half a portal, which stays an ordinary wall.
    expect($answer['shared'])->toBe(1)
        ->and($answer['lone'])->toBe(1)
        ->and($answer['missing'])->toBe(0);
});
