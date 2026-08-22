<?php

use App\Models\Level;
use App\Models\LevelSector;
use App\Models\LevelSectorEdge;
use Database\Seeders\LifeSeeder;
use Database\Seeders\PortalDemoSeeder;

/**
 * The portal demo, and the rules a portal has to keep: exactly two mouths, both
 * the same length, and rooms you can only get to through one.
 */
beforeEach(function (): void {
    $this->seed(LifeSeeder::class);
    $this->seed(PortalDemoSeeder::class);
    $this->level = Level::query()
        ->where('slug', 'portals')
        ->with(['sectors.edges.vertex'])
        ->sole();
});

/**
 * Every portal mouth in a level, grouped by the link it names.
 *
 * @return array<string, list<LevelSectorEdge>>
 */
function portalMouths(Level $level): array
{
    $mouths = [];

    foreach ($level->sectors as $sector) {
        foreach ($sector->edges as $edge) {
            if ($edge->portal_link !== null) {
                $mouths[$edge->portal_link][] = $edge;
            }
        }
    }

    return $mouths;
}

/** How long a wall is, in metres. */
function edgeLength(LevelSectorEdge $edge): float
{
    $points = $edge->sector->edges;
    $next = $points->firstWhere('sort_order', ($edge->sort_order + 1) % $points->count());

    return sqrt(
        ($next->vertex->x - $edge->vertex->x) ** 2
        + ($next->vertex->z - $edge->vertex->z) ** 2
    );
}

it('has the two portals it was built to show', function (): void {
    expect(array_keys(portalMouths($this->level)))
        ->toEqualCanonicalizing(['long-hall', 'turn', 'loop']);
});

it('gives every portal exactly two mouths', function (): void {
    foreach (portalMouths($this->level) as $link => $mouths) {
        expect($mouths)->toHaveCount(2, "The portal {$link} is not a pair.");
    }
});

it('makes both mouths of a portal the same length', function (): void {
    // Otherwise the player's offset along the wall can carry them out past the
    // end of the far one, into a room they are not in.
    foreach (portalMouths($this->level) as $link => $mouths) {
        [$first, $second] = $mouths;

        expect(edgeLength($first))->toBe(
            edgeLength($second),
            "The two mouths of {$link} are different lengths."
        );
    }
});

it('joins the two halls facing the same way, so the walk is seamless', function (): void {
    $mouths = portalMouths($this->level)['long-hall'];

    expect(collect($mouths)->map(fn (LevelSectorEdge $edge): string => $edge->sector->slug)->all())
        ->toEqualCanonicalizing(['near-hall', 'far-hall']);
});

it('turns a corner with the other portal', function (): void {
    $mouths = portalMouths($this->level)['turn'];

    expect(collect($mouths)->map(fn (LevelSectorEdge $edge): string => $edge->sector->slug)->all())
        ->toEqualCanonicalizing(['alcove', 'turn-hall']);
});

it('leaves the far rooms unreachable except through a portal', function (): void {
    // Only the portal joins the two halves of the level; if a doorway did too,
    // the trick would be visible on the map.
    $doorways = [];

    foreach ($this->level->sectors as $sector) {
        foreach (sectorEdges($sector) as $edge => $open) {
            $doorways[$edge][] = $sector->slug;
        }
    }

    $reachedByDoor = [];

    foreach ($doorways as $sharers) {
        if (count($sharers) === 2) {
            [$first, $second] = $sharers;
            $reachedByDoor[$first][] = $second;
            $reachedByDoor[$second][] = $first;
        }
    }

    $walk = function (array $links): array {
        $seen = [];
        $queue = ['hub'];

        while ($queue !== []) {
            $at = array_shift($queue);

            if (isset($seen[$at])) {
                continue;
            }

            $seen[$at] = true;

            foreach ($links[$at] ?? [] as $next) {
                $queue[] = $next;
            }
        }

        return array_keys($seen);
    };

    expect($walk($reachedByDoor))
        ->toEqualCanonicalizing(['hub', 'near-hall', 'alcove', 'chamber']);

    // With the portals counted, everything opens up.
    expect($walk(walkableLinks($this->level)))
        ->toEqualCanonicalizing($this->level->sectors->pluck('slug')->all());
});

it('loops a room back into itself, so the player sees their own back', function (): void {
    $mouths = portalMouths($this->level)['loop'];

    // Both mouths belong to the one room: through either, the room is what you
    // are looking at, with yourself standing in it.
    expect(collect($mouths)->map(fn (LevelSectorEdge $edge): string => $edge->sector->slug)->all())
        ->toBe(['chamber', 'chamber']);
});

it('puts a mirror on each of the gallery walls that face each other', function (): void {
    $gallery = $this->level->sectors->firstWhere('slug', 'gallery');

    $mirrors = $gallery->edges->filter(
        fn (LevelSectorEdge $edge): bool => $edge->is_mirror
    );

    expect($mirrors)->toHaveCount(2);

    // Facing each other means the same x extent apart along z.
    $xs = $mirrors->map(fn (LevelSectorEdge $edge): float => $edge->vertex->x)->sort()->values();

    expect($xs->first())->not->toBe($xs->last());
});

it('never marks a portal mouth as a mirror as well', function (): void {
    // A mouth is an opening, so there is no surface there to reflect with.
    $this->level->sectors->each(function (LevelSector $sector): void {
        $sector->edges->each(function (LevelSectorEdge $edge) use ($sector): void {
            expect($edge->portal_link !== null && $edge->is_mirror)->toBeFalse(
                "{$sector->slug} has a wall that is both a portal and a mirror."
            );
        });
    });
});
