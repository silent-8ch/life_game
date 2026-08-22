<?php

use App\Filament\Resources\Users\Pages\CreateUser;
use App\Models\Level;
use App\Models\User;
use Database\Seeders\PlayersSeeder;
use Livewire\Livewire;

/**
 * Adding a person, and saying who drew a level.
 *
 * The load-bearing assertion is that somebody added through the panel can
 * actually sign in. An account created without the shared non-secret is an
 * account nobody can use, and that failure has already happened once here.
 */
beforeEach(function (): void {
    // Nothing in this file is about assets. Resolving the Vite manifest was
    // never part of what these check — it came along for the ride because an
    // Inertia page renders a blade view that asks for the bundle, and it made
    // every one of them green or red depending on whether anybody had run
    // `npm run build` lately. `public/build` is gitignored, so on a clean
    // checkout or a cold runner that answer was no.
    $this->withoutVite();

    $this->seed(PlayersSeeder::class);
    $this->actingAs(User::query()->where('email', 'paul@life.test')->sole());
});

it('adds a person who can sign in straight away', function (): void {
    Livewire::test(CreateUser::class)
        ->fillForm(['name' => 'Rosie', 'email' => 'rosie@life.test'])
        ->call('create')
        ->assertHasNoFormErrors();

    $rosie = User::query()->where('email', 'rosie@life.test')->sole();

    expect($rosie->name)->toBe('Rosie')
        ->and(Hash::check(User::NO_PASSWORD, $rosie->password))->toBeTrue();
});

it('counts the levels somebody has drawn', function (): void {
    $wade = User::query()->where('email', 'wade@life.test')->sole();
    Level::factory()->count(2)->create(['owner_id' => $wade->id]);
    Level::factory()->create(['owner_id' => null]);

    expect($wade->levels()->count())->toBe(2);
});

it('hands a level to somebody else', function (): void {
    $wade = User::query()->where('email', 'wade@life.test')->sole();
    $level = Level::factory()->create(['owner_id' => null]);

    $level->update(['owner_id' => $wade->id]);

    expect($level->fresh()->owner->name)->toBe('Wade')
        ->and($wade->can('update', $level->fresh()))->toBeTrue();
});

it('orphans a person\'s levels rather than deleting them with the person', function (): void {
    $wade = User::query()->where('email', 'wade@life.test')->sole();
    $level = Level::factory()->create(['owner_id' => $wade->id]);

    $wade->delete();

    expect(Level::query()->whereKey($level->id)->exists())->toBeTrue()
        ->and($level->fresh()->owner_id)->toBeNull();
});
