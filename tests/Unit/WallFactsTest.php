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
        const { wallFacts } = await import('@/lib/editor/walls.ts');

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
