<?php

use App\Models\Game;
use App\Models\GameState;
use Database\Seeders\TheStudySeeder;
use Inertia\Testing\AssertableInertia;

it('lists the published games', function (): void {
    $this->seed(TheStudySeeder::class);

    $this->get(route('games.index'))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('games/index')
            ->has('games', 1)
            ->where('games.0.slug', 'the-study')
            ->where('games.0.inProgress', false)
        );
});

it('renders an empty state when nothing has been authored', function (): void {
    $this->get(route('games.index'))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page->has('games', 0));
});

it('hides unpublished games', function (): void {
    Game::factory()->unpublished()->create();

    $this->get(route('games.index'))
        ->assertInertia(fn (AssertableInertia $page) => $page->has('games', 0));
});

it('orders games by their sort order', function (): void {
    Game::factory()->create(['slug' => 'second', 'sort_order' => 2]);
    Game::factory()->create(['slug' => 'first', 'sort_order' => 1]);

    $this->get(route('games.index'))
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('games.0.slug', 'first')
            ->where('games.1.slug', 'second')
        );
});

it('marks a game the player has already started', function (): void {
    $this->seed(TheStudySeeder::class);
    $game = Game::query()->where('slug', 'the-study')->sole();
    GameState::for($game);

    $this->get(route('games.index'))
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('games.0.inProgress', true)
            ->where('games.0.currentLocationName', 'The Study')
        );
});
