<?php

use App\Models\Level;
use App\Models\LevelSector;
use App\Models\LevelSectorEdge;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| Test Case
|--------------------------------------------------------------------------
|
| The closure you provide to your test functions is always bound to a specific PHPUnit test
| case class. By default, that class is "PHPUnit\Framework\TestCase". Of course, you may
| need to change it using the "pest()" function to bind different classes or traits.
|
*/

pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->in('Feature');

/*
|--------------------------------------------------------------------------
| Expectations
|--------------------------------------------------------------------------
|
| When you're writing tests, you often need to check that values meet certain conditions. The
| "expect()" function gives you access to a set of "expectations" methods that you can use
| to assert different things. Of course, you may extend the Expectation API at any time.
|
*/

expect()->extend('toBeOne', function () {
    return $this->toBe(1);
});

/*
|--------------------------------------------------------------------------
| Functions
|--------------------------------------------------------------------------
|
| While Pest is very powerful out-of-the-box, you may have some testing code specific to your
| project that you don't want to repeat in every file. Here you can also expose helpers as
| global functions to help you to reduce the number of lines of code in your test files.
|
*/

function something()
{
    // ..
}

/**
 * The engine's limits, from resources/js/lib/engine/constants.ts. Levels are
 * checked against these so a room cannot be authored that nobody can walk into.
 */
const MAX_STEP = 0.55;
const MIN_HEADROOM = 1.2;

/** Comfortably wider than PLAYER_RADIUS, for judging whether a spot is clear. */
const CLEARANCE = 0.4;

/**
 * A sector's edges, keyed so that the same edge in another sector gets the same
 * key however that sector wound it, with whether this side leaves it open.
 *
 * @return array<string, bool>
 */
function sectorEdges(LevelSector $sector): array
{
    $points = $sector->edges->map(fn (LevelSectorEdge $edge): array => [
        $edge->vertex->x, $edge->vertex->z, $edge->blocks,
    ])->all();

    $edges = [];
    $count = count($points);

    for ($index = 0; $index < $count; $index++) {
        [$x, $z, $blocks] = $points[$index];
        [$nextX, $nextZ] = $points[($index + 1) % $count];

        $ends = [
            sprintf('%.3f,%.3f', $x, $z),
            sprintf('%.3f,%.3f', $nextX, $nextZ),
        ];
        sort($ends);

        $edges[implode('|', $ends)] = ! $blocks;
    }

    return $edges;
}

/**
 * Every pair of sectors joined by a portal. A portal carries the player bodily,
 * so the floors either side need not line up the way a doorway's do.
 *
 * @return array<string, list<string>>
 */
function portalLinks(Level $level): array
{
    $mouths = [];

    foreach ($level->sectors as $sector) {
        foreach ($sector->edges as $edge) {
            if ($edge->portal_link !== null) {
                $mouths[$edge->portal_link][] = $sector->slug;
            }
        }
    }

    $links = [];

    foreach ($mouths as $rooms) {
        if (count($rooms) !== 2) {
            continue;
        }

        [$first, $second] = $rooms;

        if ($first !== $second) {
            $links[$first][] = $second;
            $links[$second][] = $first;
        }
    }

    return $links;
}

/**
 * Every pair of sectors the player can actually get between: a doorway they can
 * step through, or a portal that carries them.
 *
 * @return array<string, list<string>>
 */
function walkableLinks(Level $level): array
{
    $owners = [];

    foreach ($level->sectors as $sector) {
        foreach (sectorEdges($sector) as $edge => $open) {
            $owners[$edge][] = [$sector, $open];
        }
    }

    $links = [];

    foreach ($owners as $sharers) {
        if (count($sharers) !== 2) {
            continue;
        }

        [[$first, $openFirst], [$second, $openSecond]] = $sharers;

        if (! $openFirst || ! $openSecond) {
            continue;
        }

        $climb = abs($first->floor_height - $second->floor_height);
        $headroom = min($first->ceiling_height, $second->ceiling_height)
            - max($first->floor_height, $second->floor_height);

        if ($climb > MAX_STEP || $headroom < MIN_HEADROOM) {
            continue;
        }

        $links[$first->slug][] = $second->slug;
        $links[$second->slug][] = $first->slug;
    }

    foreach (portalLinks($level) as $slug => $reached) {
        $links[$slug] = array_values(array_unique([...($links[$slug] ?? []), ...$reached]));
    }

    return $links;
}

/**
 * The sector a spot on the floor plan belongs to, or null.
 */
function sectorAtPoint(Level $level, float $x, float $z): ?LevelSector
{
    return $level->sectors->last(
        fn (LevelSector $sector): bool => pointInSector($sector, $x, $z)
    );
}

/**
 * Whether a spot on the floor plan falls inside a sector's polygon. The engine
 * asks the same question in resources/js/lib/engine/sectors.ts.
 */
function pointInSector(LevelSector $sector, float $x, float $z): bool
{
    $points = $sector->edges->map(fn (LevelSectorEdge $edge): array => [
        $edge->vertex->x, $edge->vertex->z,
    ])->all();

    $inside = false;
    $count = count($points);

    for ($index = 0; $index < $count; $index++) {
        [$fromX, $fromZ] = $points[$index];
        [$toX, $toZ] = $points[($index + 1) % $count];

        if (($fromZ > $z) === ($toZ > $z)) {
            continue;
        }

        $crossingX = $fromX + (($z - $fromZ) / ($toZ - $fromZ)) * ($toX - $fromX);

        if ($x < $crossingX) {
            $inside = ! $inside;
        }
    }

    return $inside;
}
