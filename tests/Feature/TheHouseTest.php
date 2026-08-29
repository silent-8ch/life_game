<?php

use App\Enums\ThingKind;
use App\Models\Level;
use App\Models\LevelSector;
use App\Models\LevelThing;
use Database\Seeders\LifeSeeder;
use Database\Seeders\TheHouseSeeder;

/**
 * The house as it was asked for: two storeys, five bedrooms, two and a half
 * bathrooms, and a yard you can walk out into.
 */
beforeEach(function (): void {
    $this->seed(LifeSeeder::class);
    $this->seed(TheHouseSeeder::class);
    $this->house = Level::query()
        ->where('slug', 'house')
        ->with(['sectors.edges.vertex', 'things'])
        ->sole();
});

it('is the level the game opens in', function (): void {
    expect($this->house->game->openingLevel()->slug)->toBe('house');
});

it('has five bedrooms, one of them shared', function (): void {
    $bedrooms = $this->house->sectors->filter(
        fn (LevelSector $sector): bool => str_contains($sector->name, 'Bedroom')
            || str_contains($sector->name, "'s Room")
    );

    expect($bedrooms)->toHaveCount(5);

    $master = $this->house->sectors->firstWhere('slug', 'master-bedroom');

    expect($master)->not->toBeNull();

    // Paul and Krystal share it, so there are two beds in that one room.
    $bedsInMaster = $this->house->things->filter(
        fn (LevelThing $thing): bool => str_contains($thing->name, 'Bed')
            && pointInSector($master, $thing->x, $thing->z)
    );

    expect($bedsInMaster->pluck('name')->all())
        ->toEqualCanonicalizing(["Paul's Bed", "Krystal's Bed"]);

    // And everybody else has a room of their own with a bed in it.
    foreach (['lukes-room', 'lunas-room', 'wades-room', 'williams-room'] as $slug) {
        $room = $this->house->sectors->firstWhere('slug', $slug);

        expect($room)->not->toBeNull("There is no {$slug}.");

        $beds = $this->house->things->filter(
            fn (LevelThing $thing): bool => str_contains($thing->name, 'Bed')
                && pointInSector($room, $thing->x, $thing->z)
        );

        expect($beds)->toHaveCount(1, "{$slug} has no bed, or too many.");
    }
});

it('has two bathrooms and a half', function (): void {
    $full = $this->house->sectors->filter(
        fn (LevelSector $sector): bool => str_contains($sector->name, 'Bathroom')
    );

    expect($full->pluck('slug')->all())
        ->toEqualCanonicalizing(['ensuite', 'family-bathroom']);

    // A full bathroom has a bath; the half has a lavatory and a basin and no bath.
    $full->each(function (LevelSector $bathroom): void {
        $baths = $this->house->things->filter(
            fn (LevelThing $thing): bool => $thing->name === 'Bath'
                && pointInSector($bathroom, $thing->x, $thing->z)
        );

        expect($baths)->toHaveCount(1, "{$bathroom->slug} has no bath.");
    });

    $cloakroom = $this->house->sectors->firstWhere('slug', 'cloakroom');

    $inCloakroom = $this->house->things->filter(
        fn (LevelThing $thing): bool => pointInSector($cloakroom, $thing->x, $thing->z)
    )->pluck('name');

    expect($cloakroom)->not->toBeNull()
        ->and($inCloakroom)->toContain('Lavatory')
        ->and($inCloakroom)->toContain('Basin')
        ->and($inCloakroom)->not->toContain('Bath');
});

it('puts the upper floor a storey above the ground floor', function (): void {
    $ground = $this->house->sectors->firstWhere('slug', 'hall');
    $upstairs = $this->house->sectors->firstWhere('slug', 'landing');

    expect($ground->floor_height)->toBe(0.0)
        ->and($upstairs->floor_height)->toBeGreaterThanOrEqual(2.5)
        ->and($upstairs->floor_height)->toBeGreaterThan($ground->ceiling_height);
});

it('joins the storeys with steps a person can climb', function (): void {
    $steps = $this->house->sectors
        ->filter(fn (LevelSector $sector): bool => str_starts_with($sector->slug, 'stair-'))
        ->sortBy('floor_height')
        ->values();

    expect($steps)->toHaveCount(8);

    $landing = $this->house->sectors->firstWhere('slug', 'landing');
    $climbed = 0.0;

    foreach ($steps as $step) {
        expect($step->floor_height - $climbed)->toBeLessThanOrEqual(MAX_STEP)
            ->and($step->headroom())->toBeGreaterThanOrEqual(MIN_HEADROOM);

        $climbed = $step->floor_height;
    }

    // The top step arrives level with upstairs.
    expect($climbed)->toBe($landing->floor_height);
});

it('lets the player out into the yard', function (): void {
    $yard = $this->house->sectors->firstWhere('slug', 'yard');

    expect($yard)->not->toBeNull()
        ->and($yard->is_sky)->toBeTrue('The yard should be open to the sky.')
        ->and($yard->floor_texture)->toBe('spring-grass');

    // The back door is the only way between the hall and the yard.
    expect(walkableLinks($this->house)['yard'] ?? [])->toBe(['hall']);
});

it('shows the neighbourhood over the fence', function (): void {
    $yard = $this->house->sectors->firstWhere('slug', 'yard');

    expect($this->house->sky_image)->toBe('sky-day-2')
        ->and($this->house->backdrop_theme)->toBe('suburbs')
        ->and($this->house->backdrop_layers)->toBe([1, 2, 3])
        // A fence you cannot see over would hide all of it.
        ->and($yard->ceiling_height - $yard->floor_height)->toBeLessThan(3.0);
});

it('has a mirror in the family bathroom', function (): void {
    $mirrored = $this->house->sectors->filter(
        fn (LevelSector $sector): bool => $sector->edges->contains('is_mirror', true)
    );

    expect($mirrored->pluck('slug')->all())->toBe(['family-bathroom']);
});

it('has everyone but paul living in it', function (): void {
    $people = $this->house->things
        ->filter(fn (LevelThing $thing): bool => $thing->kind === ThingKind::Actor)
        ->pluck('sprite');

    expect($people->all())
        ->toEqualCanonicalizing(['krystal', 'luke', 'luna', 'wade', 'william'])
        ->and($people)->not->toContain('paul');
});
