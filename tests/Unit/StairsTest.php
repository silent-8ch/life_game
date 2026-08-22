<?php

use Symfony\Component\Process\Process;

/**
 * Turning one room into a flight of steps.
 *
 * The arithmetic is the whole feature. Authoring a staircase by hand means
 * carving N strips and setting two heights on each, and the mistakes that
 * produces — a step half a centimetre out — are invisible in plan and obvious
 * the moment somebody tries to walk up. So what is worth pinning is that the
 * steps come out even, that they fill the room, and that they climb the way
 * the author asked rather than the way the world axes happen to run.
 */

/**
 * @return array<string, mixed>
 */
function stairAnswer(string $sectors, string $body): array
{
    $script = <<<JS
        const { carveStairs, whyNotStairs, tooSteepFor } =
            await import('@/lib/editor/stairs.ts');
        const { windingOf } = await import('@/lib/editor/map.ts');

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
            floorTexture: 'oak-floor',
            ceilingTexture: null,
            wallTexture: 'red-brick',
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
            wallColor: '#fff', floorColor: '#888', accentColor: '#fc0',
            sky: null, playerStats: null, things: [],
            sectors: {$sectors},
        };

        const round = (value) => Number(value.toFixed(4));
        const areaOf = (sector) => round(Math.abs(windingOf(sector.points)) / 2);

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

it('cuts a room into as many steps as were asked for, and no more', function (): void {
    $answer = stairAnswer(
        "[box('hall', 0, 0, 4, 8)]",
        <<<'JS'
        const flight = carveStairs(level, 0, { steps: 4, rise: 2, fromEdge: 0 });

        process.stdout.write(JSON.stringify({
            slugs: flight.sectors.map((s) => s.slug),
            floors: flight.sectors.map((s) => round(s.floorHeight)),
            areas: flight.sectors.map((s) => areaOf(s)),
        }));
        JS
    );

    expect($answer['slugs'])->toHaveCount(4);

    // Evenly spaced, starting at the room's own floor. The last step is at
    // three quarters of the rise, not at all of it: the fourth step is what you
    // stand on to have climbed the whole flight, so the top of it is the rise.
    expect($answer['floors'])->toEqual([0.0, 0.5, 1.0, 1.5]);

    // And they fill the room between them, in equal parts.
    expect(array_sum($answer['areas']))->toEqual(32.0);
    expect(array_unique($answer['areas']))->toEqual([8.0]);
});

it('climbs into the room from the wall it was told to start at', function (): void {
    // Wall 0 of this room runs (0,0) to (4,0), so the room lies to its +z side.
    // Starting there, the first step has to be the one against z = 0.
    $answer = stairAnswer(
        "[box('hall', 0, 0, 4, 8)]",
        <<<'JS'
        const flight = carveStairs(level, 0, { steps: 2, rise: 1, fromEdge: 0 });
        const zOf = (sector) =>
            round(sector.points.reduce((total, p) => total + p.z, 0) / sector.points.length);

        process.stdout.write(JSON.stringify({
            middles: flight.sectors.map(zOf),
            floors: flight.sectors.map((s) => round(s.floorHeight)),
        }));
        JS
    );

    // The lower step is the nearer one to the starting wall.
    expect($answer['middles'][0])->toBeLessThan($answer['middles'][1]);
    expect($answer['floors'])->toEqual([0.0, 0.5]);
});

it('climbs the other way when told to start from the other wall', function (): void {
    // The same room, hinged on the wall opposite. The flight has to reverse,
    // not merely relabel — this is the assertion that catches a generator that
    // ignores the wall it was given and always runs along +z.
    $answer = stairAnswer(
        "[box('hall', 0, 0, 4, 8)]",
        <<<'JS'
        const flight = carveStairs(level, 0, { steps: 2, rise: 1, fromEdge: 2 });
        const zOf = (sector) =>
            round(sector.points.reduce((total, p) => total + p.z, 0) / sector.points.length);

        process.stdout.write(JSON.stringify({
            middles: flight.sectors.map(zOf),
        }));
        JS
    );

    expect($answer['middles'][0])->toBeGreaterThan($answer['middles'][1]);
});

it('carries the headroom up with the floor', function (): void {
    // A staircase under a flat ceiling runs out of room to stand at the top.
    // That is a thing somebody might want and is never what they meant by
    // "make me a staircase".
    $answer = stairAnswer(
        "[box('hall', 0, 0, 4, 8)]",
        <<<'JS'
        const flight = carveStairs(level, 0, { steps: 4, rise: 2, fromEdge: 0 });

        process.stdout.write(JSON.stringify({
            gaps: flight.sectors.map((s) => round(s.ceilingHeight - s.floorHeight)),
            ceilings: flight.sectors.map((s) => round(s.ceilingHeight)),
        }));
        JS
    );

    expect(array_unique($answer['gaps']))->toEqual([3.0]);
    expect($answer['ceilings'])->toEqual([3.0, 3.5, 4.0, 4.5]);
});

it('keeps the room s textures and drops its portal links', function (): void {
    // Every step is the same room cut up, so it looks the same. But a portal
    // pairs exactly two mouths by name, and copying a link onto every step
    // would leave a dozen ends to one portal and none of them working.
    $answer = stairAnswer(
        "[box('hall', 0, 0, 4, 8, {}), box('far', 40, 0, 44, 8)]",
        <<<'JS'
        const linked = JSON.parse(JSON.stringify(level));
        linked.sectors[0].points[1].portalLink = 'hop';

        const flight = carveStairs(linked, 0, { steps: 3, rise: 1.5, fromEdge: 0 });
        const steps = flight.sectors.filter((s) => s.slug !== 'far');

        process.stdout.write(JSON.stringify({
            floorTextures: [...new Set(steps.map((s) => s.floorTexture))],
            wallTextures: [...new Set(steps.map((s) => s.wallTexture))],
            links: steps.flatMap((s) => s.points.map((p) => p.portalLink)),
        }));
        JS
    );

    expect($answer['floorTextures'])->toBe(['oak-floor'])
        ->and($answer['wallTextures'])->toBe(['red-brick'])
        ->and(array_unique($answer['links']))->toBe([null]);
});

it('flattens whatever the room was sloping', function (): void {
    // A step is flat. A sloped step is a ramp with a lip, and the two describe
    // the same thing twice.
    $answer = stairAnswer(
        "[box('hall', 0, 0, 4, 8, { floorSlope: 0.5, floorSlopeEdge: 0 })]",
        <<<'JS'
        const flight = carveStairs(level, 0, { steps: 3, rise: 1.5, fromEdge: 0 });

        process.stdout.write(JSON.stringify({
            slopes: [...new Set(flight.sectors.map((s) => s.floorSlope))],
            hinges: [...new Set(flight.sectors.map((s) => s.floorSlopeEdge))],
        }));
        JS
    );

    expect($answer['slopes'])->toEqual([0.0])
        ->and($answer['hinges'])->toEqual([null]);
});

it('goes down as readily as up', function (): void {
    $answer = stairAnswer(
        "[box('hall', 0, 0, 4, 8)]",
        <<<'JS'
        const flight = carveStairs(level, 0, { steps: 4, rise: -2, fromEdge: 0 });

        process.stdout.write(JSON.stringify({
            floors: flight.sectors.map((s) => round(s.floorHeight)),
        }));
        JS
    );

    expect($answer['floors'])->toEqual([0.0, -0.5, -1.0, -1.5]);
});

it('works on a room that is not a rectangle', function (): void {
    // An L-shaped room. The bands still fill it, so the areas differ while
    // adding up to the whole — which is the case a generator that assumes a
    // rectangle gets wrong by producing steps of equal area that do not tile.
    $answer = stairAnswer(
        "[room('ell', [corner(0,0), corner(6,0), corner(6,3), corner(3,3), corner(3,6), corner(0,6)])]",
        <<<'JS'
        const flight = carveStairs(level, 0, { steps: 3, rise: 1.5, fromEdge: 0 });

        process.stdout.write(JSON.stringify({
            count: flight.sectors.length,
            total: round(flight.sectors.reduce((sum, s) => sum + areaOf(s), 0)),
        }));
        JS
    );

    // The L is 36 minus the 9 bitten out of it.
    expect($answer['count'])->toBe(3)
        ->and($answer['total'])->toEqual(27.0);
});

it('refuses a plan it cannot carve, and says why', function (): void {
    $answer = stairAnswer(
        "[box('hall', 0, 0, 4, 8)]",
        <<<'JS'
        const reasons = {
            tooFew: whyNotStairs(level.sectors[0], { steps: 1, rise: 1, fromEdge: 0 }),
            fractional: whyNotStairs(level.sectors[0], { steps: 2.5, rise: 1, fromEdge: 0 }),
            flat: whyNotStairs(level.sectors[0], { steps: 4, rise: 0, fromEdge: 0 }),
            noWall: whyNotStairs(level.sectors[0], { steps: 4, rise: 1, fromEdge: 9 }),
            fine: whyNotStairs(level.sectors[0], { steps: 4, rise: 1, fromEdge: 0 }),
        };

        // And a refused plan leaves the level exactly as it was, rather than
        // half-carving it. A stair carve replaces the room it is given.
        const untouched = carveStairs(level, 0, { steps: 1, rise: 1, fromEdge: 0 });

        process.stdout.write(JSON.stringify({
            reasons,
            sectors: untouched.sectors.length,
            slug: untouched.sectors[0].slug,
        }));
        JS
    );

    expect($answer['reasons']['tooFew'])->toContain('at least')
        ->and($answer['reasons']['fractional'])->toContain('whole number')
        ->and($answer['reasons']['flat'])->toContain('just a room')
        ->and($answer['reasons']['noWall'])->toContain('no such wall')
        ->and($answer['reasons']['fine'])->toBeNull();

    expect($answer['sectors'])->toBe(1)
        ->and($answer['slug'])->toBe('hall');
});

it('leaves the question of what is climbable to whoever is asking', function (): void {
    // Deliberately not a rule the generator holds. Whether a rise is climbable
    // is a traversal question, and traversal is becoming a runtime decision —
    // baking today's answer into the geometry would freeze it into every level
    // authored today. The caller brings the limit.
    $answer = stairAnswer(
        "[box('hall', 0, 0, 4, 8)]",
        <<<'JS'
        const steep = { steps: 2, rise: 4, fromEdge: 0 };

        process.stdout.write(JSON.stringify({
            carvedAnyway: carveStairs(level, 0, steep).sectors.length,
            atHalfAMetre: tooSteepFor(steep, 0.5),
            atThreeMetres: tooSteepFor(steep, 3),
        }));
        JS
    );

    expect($answer['carvedAnyway'])->toBe(2)
        ->and($answer['atHalfAMetre'])->toBeTrue()
        ->and($answer['atThreeMetres'])->toBeFalse();
});
