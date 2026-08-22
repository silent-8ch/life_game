<?php

use App\Models\Level;
use App\Models\User;
use Database\Seeders\PlayersSeeder;

/**
 * Who owns a level, who may change it, and what a list shows by default.
 *
 * The rule under all of it: seeing is not editing. Nothing anybody drew is
 * hidden from anybody — the filters keep a list short. And an orphan, a level
 * drawn before there were accounts, belongs to nobody and stays editable,
 * because unclaimed is not the same as protected.
 */
beforeEach(function (): void {
    $this->seed(PlayersSeeder::class);
    $this->paul = User::query()->where('email', 'paul@life.test')->sole();
    $this->wade = User::query()->where('email', 'wade@life.test')->sole();
});

it('seeds the three players and nobody else', function (): void {
    expect(User::query()->pluck('name')->all())
        ->toEqualCanonicalizing(['Paul', 'Wade', 'Will']);
});

it('seeds them without a password anybody has to type', function (): void {
    expect(Hash::check(User::NO_PASSWORD, $this->paul->password))->toBeTrue();
});

it('leaves a level unowned unless somebody is named', function (): void {
    expect(Level::factory()->create()->owner_id)->toBeNull();
});

it('lets you edit your own level', function (): void {
    $level = Level::factory()->create(['owner_id' => $this->paul->id]);

    expect($this->paul->can('update', $level))->toBeTrue();
});

it('refuses to let you edit somebody else\'s level', function (): void {
    $level = Level::factory()->create(['owner_id' => $this->wade->id]);

    expect($this->paul->can('update', $level))->toBeFalse();
});

it('lets anybody edit an orphan, because unclaimed is not protected', function (): void {
    $level = Level::factory()->create(['owner_id' => null]);

    expect($this->paul->can('update', $level))->toBeTrue()
        ->and($this->wade->can('update', $level))->toBeTrue();
});

it('turns away a save to a level somebody else drew', function (): void {
    $level = Level::factory()->create(['owner_id' => $this->wade->id]);

    $this->actingAs($this->paul)
        ->get(route('levels.editor', $level))
        ->assertForbidden();
});

it('shows only your own levels by default', function (): void {
    $mine = Level::factory()->create(['owner_id' => $this->paul->id]);
    Level::factory()->create(['owner_id' => $this->wade->id]);
    Level::factory()->create(['owner_id' => null]);

    $this->actingAs($this->paul)
        ->get(route('levels.index'))
        ->assertInertia(fn ($page) => $page
            ->component('levels/index')
            ->where('everyone', false)
            ->has('levels', 1)
            ->where('levels.0.id', $mine->id));
});

it('shows everyone\'s levels, orphans included, when asked', function (): void {
    Level::factory()->create(['owner_id' => $this->paul->id]);
    Level::factory()->create(['owner_id' => $this->wade->id]);
    Level::factory()->create(['owner_id' => null]);

    $this->actingAs($this->paul)
        ->get(route('levels.index', ['everyone' => 1]))
        ->assertInertia(fn ($page) => $page->has('levels', 3));
});

it('marks another person\'s level as one you cannot edit', function (): void {
    Level::factory()->create(['owner_id' => $this->wade->id]);

    $this->actingAs($this->paul)
        ->get(route('levels.index', ['everyone' => 1]))
        ->assertInertia(fn ($page) => $page
            ->where('levels.0.editable', false)
            ->where('levels.0.mine', false)
            ->where('levels.0.owner', 'Wade'));
});

it('wants somebody signed in before it shows a list at all', function (): void {
    $this->get(route('levels.index'))->assertRedirect();
});
