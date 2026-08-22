<?php

use App\Enums\ThingKind;
use App\Models\Level;
use App\Models\LevelSector;
use App\Models\LevelSectorEdge;
use App\Models\LevelThing;
use App\Services\LevelAssets;
use Database\Seeders\LifeSeeder;

/**
 * Geometry the engine takes on trust: closed sectors, doorways that line up,
 * and somewhere to stand that is not inside the furniture.
 */
beforeEach(function (): void {
    $this->seed(LifeSeeder::class);
    $this->level = Level::query()->where('slug', 'tech-demo')->sole();
});

/**
 * @return array<string, LevelSector>
 */
function sectorsBySlug(Level $level): array
{
    return $level->sectors->keyBy('slug')->all();
}

/**
 * The corner pairs of a sector, as sorted keys, so a shared edge is spotted
 * whichever way round the two sectors wound it.
 *
 * @return list<string>
 */
function edgeKeys(LevelSector $sector): array
{
    $points = $sector->edges->map(fn (LevelSectorEdge $edge): array => [
        $edge->vertex->x, $edge->vertex->z,
    ])->all();

    return collect($points)->map(function (array $point, int $index) use ($points): string {
        $next = $points[($index + 1) % count($points)];
        $ends = [
            sprintf('%.3f,%.3f', $point[0], $point[1]),
            sprintf('%.3f,%.3f', $next[0], $next[1]),
        ];
        sort($ends);

        return implode('|', $ends);
    })->all();
}

it('builds the level out of sectors', function (): void {
    $sectors = sectorsBySlug($this->level);

    expect(array_keys($sectors))->toEqualCanonicalizing(['hall', 'terrace', 'pond'])
        ->and($sectors['hall']->edges)->toHaveCount(6)
        ->and($sectors['terrace']->edges)->toHaveCount(6)
        ->and($sectors['pond']->edges)->toHaveCount(4);
});

it('shares a boundary with the terrace and leaves one doorway in it', function (): void {
    $sectors = sectorsBySlug($this->level);

    $shared = array_intersect(
        edgeKeys($sectors['hall']),
        edgeKeys($sectors['terrace']),
    );

    expect($shared)->toHaveCount(3, 'The room and the terrace meet along the whole east wall.');

    $open = $sectors['hall']->edges->filter(
        fn (LevelSectorEdge $edge): bool => ! $edge->blocks
            && in_array(edgeKeys($sectors['hall'])[$edge->sort_order], $shared, strict: true)
    );

    expect($open)->toHaveCount(1, 'Only the doorway should be walkable.');

    // Both sides have to agree, or the wall is a wall from one room only.
    $openFromTerrace = $sectors['terrace']->edges->filter(
        fn (LevelSectorEdge $edge): bool => ! $edge->blocks
            && in_array(edgeKeys($sectors['terrace'])[$edge->sort_order], $shared, strict: true)
    );

    expect($openFromTerrace)->toHaveCount(1);
});

it('leaves the doorway low enough to walk through', function (): void {
    $sectors = sectorsBySlug($this->level);

    $step = abs($sectors['terrace']->floor_height - $sectors['hall']->floor_height);

    expect($step)->toBeLessThanOrEqual(MAX_STEP);
});

it('lets the player wade from the terrace into the pond', function (): void {
    $sectors = sectorsBySlug($this->level);

    $shared = array_intersect(
        edgeKeys($sectors['terrace']),
        edgeKeys($sectors['pond']),
    );

    $step = abs($sectors['pond']->floor_height - $sectors['terrace']->floor_height);

    expect($shared)->toHaveCount(1)
        ->and($step)->toBeLessThanOrEqual(MAX_STEP)
        ->and($sectors['pond']->is_water)->toBeTrue();
});

it('opens the outdoor sectors to the sky', function (): void {
    $sectors = sectorsBySlug($this->level);

    expect($sectors['terrace']->is_sky)->toBeTrue()
        ->and($sectors['pond']->is_sky)->toBeTrue()
        ->and($sectors['hall']->is_sky)->toBeFalse()
        ->and($this->level->sky_image)->toBe('sky-day')
        ->and($this->level->backdrop_theme)->toBe('hills')
        ->and($this->level->backdrop_layers)->toBe([1, 2, 3]);
});

it('mirrors one wall of the room and nothing else', function (): void {
    $mirrors = $this->level->sectors
        ->flatMap(fn (LevelSector $sector): iterable => $sector->edges)
        ->filter(fn (LevelSectorEdge $edge): bool => $edge->is_mirror);

    expect($mirrors)->toHaveCount(1)
        ->and($mirrors->first()->sector->slug)->toBe('hall');
});

it('gives every surface a texture', function (): void {
    $this->level->sectors->each(function (LevelSector $sector): void {
        expect($sector->floor_texture)->not->toBeNull("{$sector->slug} has no floor texture.")
            ->and($sector->wall_texture)->not->toBeNull("{$sector->slug} has no wall texture.");

        if (! $sector->is_sky) {
            expect($sector->ceiling_texture)->not->toBeNull("{$sector->slug} has no ceiling texture.");
        }
    });
});

it('names textures that exist in the texture folder', function (): void {
    $names = $this->level->sectors->flatMap(fn (LevelSector $sector): array => [
        $sector->floor_texture,
        $sector->ceiling_texture,
        $sector->wall_texture,
    ])->merge(
        $this->level->sectors->flatMap(
            fn (LevelSector $sector): iterable => $sector->edges->pluck('wall_texture')
        )
    )->merge(
        $this->level->things->pluck('texture')
    )->filter()->unique();

    expect($names)->not->toBeEmpty();

    $names->each(function (string $name): void {
        expect(public_path("sprites/textures/{$name}.png"))->toBeFile("Missing texture {$name}.");
    });
});

it('fills the level with people who wander', function (): void {
    $actors = $this->level->things->filter(
        fn (LevelThing $thing): bool => $thing->kind === ThingKind::Actor
    );

    expect($actors)->toHaveCount(5)
        ->and($actors->pluck('sprite')->all())
        ->toEqualCanonicalizing(['krystal', 'luke', 'luna', 'wade', 'william']);

    $actors->each(function (LevelThing $actor): void {
        expect($actor->behaviour)->toBe('wander')
            ->and($actor->speed)->toBeGreaterThan(0.0)
            ->and($actor->is_solid)->toBeFalse();
    });
});

it('stands everyone at their own height, tallest first', function (): void {
    // Paul is the player, so his height lives in the engine rather than here.
    $heights = $this->level->things
        ->filter(fn (LevelThing $thing): bool => $thing->kind === ThingKind::Actor)
        ->pluck('height', 'sprite')
        ->all();

    $tallestFirst = collect($heights)->sortDesc()->keys()->all();

    expect($tallestFirst)->toBe(['wade', 'krystal', 'luna', 'luke', 'william'])
        ->and(max($heights))->toBeLessThan(1.85, 'Nobody stands taller than Paul.');
});

it('draws every person, the player included, from sheets that exist', function (): void {
    $assets = app(LevelAssets::class);

    $people = $this->level->things
        ->filter(fn (LevelThing $thing): bool => $thing->kind === ThingKind::Actor)
        ->pluck('sprite')
        ->push('paul')
        ->unique();

    expect($people)->toHaveCount(6)
        ->and($assets->sprites())->toContain('young_paul');

    $people->each(function (string $sprite) use ($assets): void {
        expect($sprite)->toBeIn($assets->sprites());

        foreach (['cardinal', 'diagonal'] as $sheet) {
            expect(public_path($assets->sheetPath($sprite, $sheet)))
                ->toBeFile("Missing {$sheet} sheet for {$sprite}.");
        }
    });
});

it('spawns the player inside a sector and clear of the furniture', function (): void {
    $inside = $this->level->sectors->contains(
        fn (LevelSector $sector): bool => pointInSector(
            $sector,
            $this->level->spawn_x,
            $this->level->spawn_z,
        )
    );

    expect($inside)->toBeTrue('The player spawns outside every sector.');

    $this->level->things
        ->filter(fn (LevelThing $thing): bool => $thing->is_solid)
        ->each(function (LevelThing $thing): void {
            $gapX = abs($this->level->spawn_x - $thing->x) - $thing->width / 2;
            $gapZ = abs($this->level->spawn_z - $thing->z) - $thing->depth / 2;

            expect(max($gapX, $gapZ))->toBeGreaterThan(
                CLEARANCE,
                "The player spawns inside {$thing->slug}."
            );
        });
});

it('keeps every thing inside a sector', function (): void {
    $this->level->things->each(function (LevelThing $thing): void {
        $inside = $this->level->sectors->contains(
            fn (LevelSector $sector): bool => pointInSector($sector, $thing->x, $thing->z)
        );

        expect($inside)->toBeTrue("{$thing->slug} stands outside every sector.");
    });
});

it('does not let two solid things stand in the same place', function (): void {
    $solid = $this->level->things
        ->filter(fn (LevelThing $thing): bool => $thing->is_solid)
        ->values();

    foreach ($solid as $index => $thing) {
        foreach ($solid->slice($index + 1) as $other) {
            $overlapX = abs($thing->x - $other->x) < ($thing->width + $other->width) / 2;
            $overlapZ = abs($thing->z - $other->z) < ($thing->depth + $other->depth) / 2;

            expect($overlapX && $overlapZ)->toBeFalse(
                "{$thing->slug} overlaps {$other->slug}."
            );
        }
    }
});
