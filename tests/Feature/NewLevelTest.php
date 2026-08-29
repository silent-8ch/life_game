<?php

use App\Enums\ActorBehaviour;
use App\Enums\ThingKind;
use App\Filament\Resources\Levels\Pages\CreateLevel;
use App\Models\Game;
use App\Models\Level;
use App\Models\User;
use App\Services\LevelStarter;
use Database\Seeders\LifeSeeder;
use Livewire\Livewire;

/**
 * Making a level should take one click: the form arrives filled in, and what
 * comes out is a room you can already walk around.
 */
beforeEach(function (): void {
    $this->seed(LifeSeeder::class);
    $this->game = Game::query()->where('slug', 'life')->sole();
    $this->me = User::factory()->create();
    $this->actingAs($this->me);
});

it('fills the whole create form in', function (): void {
    Livewire::test(CreateLevel::class)
        ->assertSchemaStateSet([
            'game_id' => $this->game->id,
            'name' => 'New Level',
            'slug' => 'new-level',
            'ceiling_height' => 3,
            'spawn_x' => 0,
            'spawn_z' => 0,
            'spawn_angle' => 0,
            'sky' => 'sky-day:0',
            'description' => 'A level waiting to be drawn.',
        ]);
});

it('puts your name against a level you draw', function (): void {
    Livewire::test(CreateLevel::class)
        ->assertSchemaStateSet(['owner_id' => $this->me->id])
        ->call('create')
        ->assertHasNoFormErrors();

    expect(Level::query()->where('slug', 'new-level')->sole()->owner_id)
        ->toBe($this->me->id);
});

it('still lets a level be left against nobody', function (): void {
    // An orphan is a real state and the form has to be able to make one:
    // everything drawn before there were accounts is one, and an orphan stays
    // editable by anybody rather than locked away from everybody.
    Livewire::test(CreateLevel::class)
        ->fillForm(['owner_id' => null])
        ->call('create')
        ->assertHasNoFormErrors();

    expect(Level::query()->where('slug', 'new-level')->sole()->owner_id)
        ->toBeNull();
});

it('names whoever is signed in, not whoever drew the last one', function (): void {
    Livewire::test(CreateLevel::class)->call('create')->assertHasNoFormErrors();

    $someoneElse = User::factory()->create();
    $this->actingAs($someoneElse);

    Livewire::test(CreateLevel::class)->call('create')->assertHasNoFormErrors();

    expect(Level::query()->where('slug', 'new-level-2')->sole()->owner_id)
        ->toBe($someoneElse->id);
});

it('picks a sky from one list of panoramas, not a file and then a cell', function (): void {
    // Which strip a panorama is packed into is a fact about the art rather
    // than a decision anybody makes, so it is not asked as a second question.
    Livewire::test(CreateLevel::class)
        ->fillForm(['sky' => 'sky-sunset:2'])
        ->call('create')
        ->assertHasNoFormErrors();

    $level = Level::query()->where('slug', 'new-level')->sole();

    expect($level->sky_image)->toBe('sky-sunset')
        ->and($level->sky_variant)->toBe(2)
        ->and($level->sky)->toBe('sky-sunset:2');
});

it('lets a level have no sky at all', function (): void {
    Livewire::test(CreateLevel::class)
        ->fillForm(['sky' => null])
        ->call('create')
        ->assertHasNoFormErrors();

    $level = Level::query()->where('slug', 'new-level')->sole();

    expect($level->sky_image)->toBeNull()
        ->and($level->sky)->toBeNull();
});

it('offers no horizon or layers to set', function (): void {
    // Paul's ruling: they did not look good. The columns and the art stayed so
    // the decision is reversible, but nothing asks for one and nothing draws
    // one, and a new level is not given one.
    Livewire::test(CreateLevel::class)
        ->assertFormFieldDoesNotExist('backdrop_theme')
        ->assertFormFieldDoesNotExist('backdrop_layers')
        ->assertFormFieldDoesNotExist('sky_variant')
        ->call('create')
        ->assertHasNoFormErrors();

    $level = Level::query()->where('slug', 'new-level')->sole();

    expect($level->backdrop_theme)->toBeNull()
        ->and($level->backdrop_layers)->toBeNull();
});

it('creates a level without anything being typed', function (): void {
    Livewire::test(CreateLevel::class)
        ->call('create')
        ->assertHasNoFormErrors();

    $level = Level::query()->where('slug', 'new-level')->sole();

    expect($level->game_id)->toBe($this->game->id)
        ->and($level->name)->toBe('New Level')
        ->and($level->description)->not->toBeEmpty();
});

it('numbers the next level rather than clashing with the last', function (): void {
    Livewire::test(CreateLevel::class)->call('create')->assertHasNoFormErrors();
    Livewire::test(CreateLevel::class)->assertSchemaStateSet([
        'name' => 'New Level 2',
        'slug' => 'new-level-2',
    ]);

    Livewire::test(CreateLevel::class)->call('create')->assertHasNoFormErrors();
    Livewire::test(CreateLevel::class)->assertSchemaStateSet([
        'name' => 'New Level 3',
        'slug' => 'new-level-3',
    ]);

    expect(Level::query()->where('game_id', $this->game->id)->pluck('slug'))
        ->toContain('new-level', 'new-level-2');
});

it('refuses a slug another level in the same game is using', function (): void {
    Livewire::test(CreateLevel::class)
        ->fillForm(['slug' => 'tech-demo'])
        ->call('create')
        ->assertHasFormErrors(['slug']);
});

it('types the slug out as the name is typed', function (): void {
    Livewire::test(CreateLevel::class)
        ->fillForm(['name' => 'The Attic'])
        ->assertSchemaStateSet(['slug' => 'the-attic']);
});

it('starts the level with a room around the player', function (): void {
    Livewire::test(CreateLevel::class)->call('create')->assertHasNoFormErrors();

    $level = Level::query()
        ->where('slug', 'new-level')
        ->with('sectors.edges.vertex')
        ->sole();

    expect($level->sectors)->toHaveCount(1);

    $room = $level->sectors->sole();

    expect($room->edges)->toHaveCount(4)
        ->and($room->floor_height)->toBe(0.0)
        ->and($room->ceiling_height)->toBe($level->ceiling_height)
        ->and($room->floor_texture)->not->toBeNull()
        ->and($room->wall_texture)->not->toBeNull()
        ->and($room->ceiling_texture)->not->toBeNull()
        ->and($room->is_sky)->toBeFalse();

    // The player has to start inside it, or the level opens in the void.
    expect(pointInSector($room, $level->spawn_x, $level->spawn_z))->toBeTrue();
});

it('walls the starter room in on every side', function (): void {
    Livewire::test(CreateLevel::class)->call('create')->assertHasNoFormErrors();

    $room = Level::query()
        ->where('slug', 'new-level')
        ->with('sectors.edges.vertex')
        ->sole()
        ->sectors
        ->sole();

    expect($room->edges->every(fn ($edge): bool => $edge->blocks))->toBeTrue()
        ->and($room->edges->contains('is_mirror', true))->toBeFalse();
});

it('winds the starter room the way the engine reads', function (): void {
    Livewire::test(CreateLevel::class)->call('create')->assertHasNoFormErrors();

    $room = Level::query()
        ->where('slug', 'new-level')
        ->with('sectors.edges.vertex')
        ->sole()
        ->sectors
        ->sole();

    $points = $room->edges->map(fn ($edge): array => [$edge->vertex->x, $edge->vertex->z])->all();

    $signedArea = collect($points)->reduce(function (float $total, array $point, int $index) use ($points): float {
        $next = $points[($index + 1) % count($points)];

        return $total + ($point[0] * $next[1] - $next[0] * $point[1]);
    }, 0.0);

    expect($signedArea)->toBeGreaterThan(0.0);
});

it('leaves a level that already has a shape alone', function (): void {
    $level = Level::query()->where('slug', 'tech-demo')->sole();
    $before = $level->sectors()->count();

    app(LevelStarter::class)->room($level);

    expect($level->sectors()->count())->toBe($before);
});

it('opens the new level in the map editor straight away', function (): void {
    Livewire::test(CreateLevel::class)->call('create')->assertHasNoFormErrors();

    $level = Level::query()->where('slug', 'new-level')->sole();

    $this->get(route('levels.editor', $level))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('editor/level')
            ->has('level.sectors', 1)
        );
});

it('puts the household in the room a new level starts with', function (): void {
    Livewire::test(CreateLevel::class)->call('create')->assertHasNoFormErrors();

    $level = Level::query()
        ->where('slug', 'new-level')
        ->with(['sectors.edges.vertex', 'things'])
        ->sole();

    $people = $level->things->filter(
        fn ($thing): bool => $thing->kind === ThingKind::Actor
    );

    // The residents, the player included: which is being played is a matter for
    // the level, and nothing stops you meeting yourself. The stylised toons are
    // castable but not auto-placed, so a new level does not fill up with them.
    expect($people->pluck('sprite')->all())
        ->toEqualCanonicalizing(['paul', 'krystal', 'luke', 'luna', 'wade', 'william']);

    $room = $level->sectors->sole();

    $people->each(function ($person) use ($room): void {
        expect($person->behaviour)->toBe(ActorBehaviour::Wander->value)
            ->and($person->speed)->toBeGreaterThan(0.0)
            ->and($person->is_solid)->toBeFalse()
            ->and($person->height)->toBeGreaterThan(1.0)
            // Standing in the room, not in a wall.
            ->and(pointInSector($room, $person->x, $person->z))->toBeTrue();
    });
});

it('stands the household at their own heights', function (): void {
    Livewire::test(CreateLevel::class)->call('create')->assertHasNoFormErrors();

    $heights = Level::query()
        ->where('slug', 'new-level')
        ->with('things')
        ->sole()
        ->things
        ->pluck('height', 'sprite')
        ->all();

    expect(collect($heights)->sortDesc()->keys()->all())
        ->toBe(['paul', 'wade', 'krystal', 'luna', 'luke', 'william'])
        ->and(max($heights))->toBe(1.85, 'Nobody stands taller than Paul.');
});
