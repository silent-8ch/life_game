<?php

use Symfony\Component\Process\Process;

/**
 * What the editor knows about the wall you have picked.
 *
 * This lived inside the Inspector, worked out above the early returns, which
 * made it look like state every mode shared. It never was — all of it is read
 * only by the wall panel. Out here it is a function of the level and the
 * selection, so it can be pinned without a browser.
 *
 * That matters more than it sounds. `inspector.tsx` has no test runner behind
 * it, so anything left inside it can be broken by a refactor with nothing in
 * the project noticing. This is the part most likely to break silently in a
 * move — four of the five are subtle enough that a wrong answer still renders a
 * plausible-looking panel — so it is pinned before the file is cut up.
 */

/**
 * @return array<string, mixed>
 */
function wallAnswer(string $body): array
{
    $script = <<<JS
        const { wallFacts, wallLabels, slopeReach } = await import(
            '@/lib/editor/walls.ts'
        );

        const corner = (x, z, extra = {}) => ({
            x, z, wallTexture: null, blocks: false,
            isMirror: false, isSky: false, portalLink: null, ...extra,
        });

        const room = (slug, points) => ({
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
        });

        const level = {
            slug: 'test', name: 'test', description: '',
            spawn: { x: 1, z: 1, angle: 0 }, ceilingHeight: 3,
            spriteStyle: 'realistic', playerSprite: 'paul',
            wallColor: '#fff', floorColor: '#888', accentColor: '#fc0',
            sky: null, playerStats: null, things: [],
            sectors: [
                // Two rooms sharing the wall along z = 4.
                room('south', [
                    corner(0, 0), corner(8, 0), corner(8, 4), corner(0, 4),
                ]),
                room('north', [
                    corner(0, 4), corner(8, 4), corner(8, 8), corner(0, 8),
                ]),
                // Off on its own, carrying the far end of a portal.
                room('away', [
                    corner(40, 0, { portalLink: 'hop' }),
                    corner(48, 0), corner(48, 8), corner(40, 8),
                ]),
            ],
        };

        /** Names rather than objects, so the answer survives JSON. */
        const named = (facts) => ({
            twin: facts.twin,
            across: facts.across === null ? null : facts.across.slug,
            partner: facts.partner === null ? null : facts.partner.slug,
            portalEnds: facts.portalEnds,
            openDoorway: facts.openDoorway,
            mouth: facts.mouth === null ? null : {
                here: Number(facts.mouth.here.toFixed(4)),
                there: Number(facts.mouth.there.toFixed(4)),
            },
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

it('knows nothing when nothing is picked, and nothing when a whole room is', function (): void {
    $answer = wallAnswer(<<<'JS'
        process.stdout.write(JSON.stringify({
            nothing: named(wallFacts(level, null)),
            wholeRoom: named(wallFacts(level, { sector: 0, edge: null })),
        }));
        JS);

    foreach (['nothing', 'wholeRoom'] as $case) {
        expect($answer[$case]['twin'])->toBeNull()
            ->and($answer[$case]['across'])->toBeNull()
            ->and($answer[$case]['partner'])->toBeNull()
            ->and($answer[$case]['portalEnds'])->toEqual(0)
            ->and($answer[$case]['openDoorway'])->toBeFalse();
    }
});

it('finds the same wall as the room on the other side names it', function (): void {
    // south's wall 2 runs (8,4) to (0,4); north's wall 0 runs (0,4) to (8,4).
    // The same line, wound opposite ways, which is the case that catches a
    // naive comparison.
    $answer = wallAnswer(<<<'JS'
        process.stdout.write(JSON.stringify({
            shared: named(wallFacts(level, { sector: 0, edge: 2 })),
            outside: named(wallFacts(level, { sector: 0, edge: 0 })),
        }));
        JS);

    expect($answer['shared']['twin'])->toEqual(['sector' => 1, 'edge' => 0])
        ->and($answer['shared']['across'])->toBe('north');

    // A wall with nothing on the other side has no twin and no room across it.
    expect($answer['outside']['twin'])->toBeNull()
        ->and($answer['outside']['across'])->toBeNull();
});

it('calls a shared wall open only when neither side blocks it', function (): void {
    // Passability belongs to the boundary, not to one room's idea of it. Either
    // side saying no is enough, and reading only the near side is the mistake
    // this pins.
    $answer = wallAnswer(<<<'JS'
        const shut = JSON.parse(JSON.stringify(level));
        shut.sectors[1].points[0].blocks = true;

        const near = JSON.parse(JSON.stringify(level));
        near.sectors[0].points[2].blocks = true;

        process.stdout.write(JSON.stringify({
            open: named(wallFacts(level, { sector: 0, edge: 2 })),
            farSideShut: named(wallFacts(shut, { sector: 0, edge: 2 })),
            nearSideShut: named(wallFacts(near, { sector: 0, edge: 2 })),
        }));
        JS);

    expect($answer['open']['openDoorway'])->toBeTrue()
        ->and($answer['farSideShut']['openDoorway'])->toBeFalse()
        ->and($answer['nearSideShut']['openDoorway'])->toBeFalse();
});

it('counts the ends of a portal, so a half-made one can be reported', function (): void {
    $answer = wallAnswer(<<<'JS'
        const paired = JSON.parse(JSON.stringify(level));
        paired.sectors[0].points[0].portalLink = 'hop';

        const tripled = JSON.parse(JSON.stringify(paired));
        tripled.sectors[1].points[2].portalLink = 'hop';

        process.stdout.write(JSON.stringify({
            lonely: named(wallFacts(level, { sector: 2, edge: 0 })),
            paired: named(wallFacts(paired, { sector: 0, edge: 0 })),
            tripled: named(wallFacts(tripled, { sector: 0, edge: 0 })),
        }));
        JS);

    // One end on its own is a way to nowhere; three is ambiguous. Both are
    // worth telling the author about, so the count is reported rather than a
    // yes or no.
    expect($answer['lonely']['portalEnds'])->toEqual(1)
        ->and($answer['paired']['portalEnds'])->toEqual(2)
        ->and($answer['tripled']['portalEnds'])->toEqual(3);
});

it('finds the room at the other end of a portal, and never the wall you are on', function (): void {
    $answer = wallAnswer(<<<'JS'
        const paired = JSON.parse(JSON.stringify(level));
        paired.sectors[0].points[0].portalLink = 'hop';

        process.stdout.write(JSON.stringify({
            fromSouth: named(wallFacts(paired, { sector: 0, edge: 0 })),
            fromAway: named(wallFacts(paired, { sector: 2, edge: 0 })),
        }));
        JS);

    // Looked up from either end, the partner is the *other* room — the wall you
    // are standing on is excluded by position, not by name, which is what makes
    // a portal joining one room to itself possible.
    expect($answer['fromSouth']['partner'])->toBe('away')
        ->and($answer['fromAway']['partner'])->toBe('south');
});

it('has no partner for a wall carrying no link', function (): void {
    $answer = wallAnswer(<<<'JS'
        const blank = JSON.parse(JSON.stringify(level));
        blank.sectors[0].points[0].portalLink = '';

        process.stdout.write(JSON.stringify({
            none: named(wallFacts(level, { sector: 0, edge: 0 })),
            empty: named(wallFacts(blank, { sector: 0, edge: 0 })),
        }));
        JS);

    // Null and empty string both mean "no portal". The empty string is what an
    // editor field gives you when somebody clears it.
    expect($answer['none']['partner'])->toBeNull()
        ->and($answer['none']['portalEnds'])->toEqual(0)
        ->and($answer['empty']['partner'])->toBeNull();
});

it('answers rather than throws when the selection points at nothing', function (): void {
    // A carve can delete the room under the selection before the panel next
    // renders. Returning empty facts is right; throwing takes the editor down.
    $answer = wallAnswer(<<<'JS'
        process.stdout.write(JSON.stringify({
            goneRoom: named(wallFacts(level, { sector: 99, edge: 0 })),
            goneWall: named(wallFacts(level, { sector: 0, edge: 99 })),
        }));
        JS);

    expect($answer['goneRoom']['across'])->toBeNull()
        ->and($answer['goneRoom']['portalEnds'])->toEqual(0)
        ->and($answer['goneWall']['portalEnds'])->toEqual(0)
        ->and($answer['goneWall']['openDoorway'])->toBeFalse();
});

it('names each wall by the side of the room it is on', function (): void {
    // "Wall 3" tells nobody anything, and picking the wall a floor hinges on is
    // the whole of authoring a slope. North is -z, the way the camera looks at
    // a yaw of zero.
    $answer = wallAnswer(<<<'JS'
        // south runs (0,0) (8,0) (8,4) (0,4) — anticlockwise in x/z.
        const anticlockwise = wallLabels(level.sectors[0]);

        // The same square wound the other way. The walls are in a different
        // order but each is still on the same side of the room, which is what
        // a label has to survive.
        const flipped = {
            ...level.sectors[0],
            points: [...level.sectors[0].points].reverse(),
        };

        process.stdout.write(JSON.stringify({
            anticlockwise,
            clockwise: wallLabels(flipped),
        }));
        JS);

    // The room spans z 0..4, so its wall at z = 0 is on the *north* side of it
    // — north being -z. Worth spelling out, because the fixture is called
    // "south" for where it sits relative to the other room, and reading that
    // across to the wall names is the obvious mistake. This assertion had it
    // backwards on the first pass.
    expect($answer['anticlockwise'])->toBe([
        '1 — north', '2 — east', '3 — south', '4 — west',
    ]);

    // Wound the other way the walls come in a different order, but every one of
    // the four compass points is still named exactly once. Reading the winding
    // wrong would give all four the opposite name and still look tidy.
    expect($answer['clockwise'])->toHaveCount(4);

    $sides = array_map(
        fn (string $label): string => explode(' — ', $label)[1],
        $answer['clockwise']
    );

    sort($sides);

    expect($sides)->toBe(['east', 'north', 'south', 'west']);
});

it('measures both ends of a portal, so the panel can stop crying wolf', function (): void {
    $answer = wallAnswer(<<<'JS'
        // south's west wall runs z 4 to 0, four metres. away's south wall runs
        // x 40 to 48, eight. A pair, and a mismatched one.
        const uneven = JSON.parse(JSON.stringify(level));
        uneven.sectors[0].points[3].portalLink = 'hop';

        // south's south wall is x 0 to 8, which matches away exactly.
        const even = JSON.parse(JSON.stringify(level));
        even.sectors[0].points[0].portalLink = 'hop';

        // One end only, so there is nothing to compare it with.
        const lonely = JSON.parse(JSON.stringify(level));

        // Three ends, where "the other one" is not a question with an answer.
        const tripled = JSON.parse(JSON.stringify(even));
        tripled.sectors[1].points[2].portalLink = 'hop';

        process.stdout.write(JSON.stringify({
            uneven: named(wallFacts(uneven, { sector: 0, edge: 3 })),
            even: named(wallFacts(even, { sector: 0, edge: 0 })),
            fromTheFarEnd: named(wallFacts(even, { sector: 2, edge: 0 })),
            lonely: named(wallFacts(lonely, { sector: 2, edge: 0 })),
            tripled: named(wallFacts(tripled, { sector: 0, edge: 0 })),
            noLink: named(wallFacts(level, { sector: 0, edge: 1 })),
        }));
        JS);

    // The panel warned every correctly paired portal that its two walls should
    // be the same length, whether or not they already were, because it never
    // looked. Paul saw it on equal mouths in two different levels. Both
    // numbers are here now, so the panel can compare them and say which is
    // which — and the same reading from the far end has to agree, or an author
    // gets a different answer depending on which wall they clicked.
    expect($answer['uneven']['mouth'])->toEqual(['here' => 4, 'there' => 8])
        ->and($answer['even']['mouth'])->toEqual(['here' => 8, 'there' => 8])
        ->and($answer['fromTheFarEnd']['mouth'])->toEqual(['here' => 8, 'there' => 8]);

    // Null rather than a pair of zeroes wherever there is no comparison to
    // make: one end, three ends, or no link at all. A zero would read as a
    // measurement and put the warning on things that have no second wall.
    expect($answer['lonely']['mouth'])->toBeNull()
        ->and($answer['tripled']['mouth'])->toBeNull()
        ->and($answer['noLink']['mouth'])->toBeNull();
});

it('says where a slope actually gets to, which the rise never did', function (): void {
    $answer = wallAnswer(<<<'JS'
        // Paul's own room, to the corner: more-testing/room-2, a plain six by
        // four rectangle hinged on its short west wall at half a metre per
        // metre, floor and ceiling both.
        const his = {
            slug: 'room-2', name: 'room-2',
            floorHeight: 0, ceilingHeight: 3,
            floorSlope: 0.5, floorSlopeEdge: 3,
            ceilingSlope: 0.5, ceilingSlopeEdge: 3,
            floorTexture: null, ceilingTexture: null, wallTexture: null,
            isSky: false, isWater: false,
            points: [corner(4, -4), corner(10, -4), corner(10, 0), corner(4, 0)],
        };

        // An L, where a corner sits further from the hinge wall than the room
        // looks deep. Same rise, same hinge wall, twice the height.
        const bent = {
            ...his,
            points: [
                corner(4, -4), corner(10, -4), corner(10, -2),
                corner(16, -2), corner(16, 0), corner(4, 0),
            ],
        };

        process.stdout.write(JSON.stringify({
            floor: slopeReach(his, his.floorHeight, his.floorSlope, his.floorSlopeEdge),
            ceiling: slopeReach(his, his.ceilingHeight, his.ceilingSlope, his.ceilingSlopeEdge),
            bent: slopeReach(bent, bent.floorHeight, bent.floorSlope, bent.floorSlopeEdge),
            flat: slopeReach(his, 0, 0, null),
            carvedAway: slopeReach(his, 0, 0.5, 9),
        }));
        JS);

    // Six metres at half a metre per metre is three metres, and it was never
    // anything else. Paul: the end of the slope is higher somehow in the math.
    // It is not somehow — but a number you can only find out by walking up it
    // is a number nobody can author with, so the panel says it now.
    expect($answer['floor'])->toEqual(['lowest' => 0, 'highest' => 3]);

    // And this is why it read as *somehow*. The ceiling climbs with the floor
    // on the same hinge, so headroom is three metres at both ends and the room
    // looks identical however far you have climbed. Showing both surfaces is
    // what makes that visible without walking it.
    expect($answer['ceiling'])->toEqual(['lowest' => 3, 'highest' => 6]);

    // Measured at the corners rather than from the room's depth, and an L is
    // why. `into` is the perpendicular distance from the hinge wall, so a
    // corner can sit twice as far from it as the room looks deep and the floor
    // there goes with it.
    expect($answer['bent'])->toEqual(['lowest' => 0, 'highest' => 6]);

    // Nothing to describe rather than zero to describe: a flat surface, and a
    // hinge pointing at a wall that has since been carved away.
    expect($answer['flat'])->toBeNull()
        ->and($answer['carvedAway'])->toBeNull();
});
