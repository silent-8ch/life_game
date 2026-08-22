<?php

use App\Models\Game;
use App\Models\GameState;
use App\Models\Item;
use App\Models\Scene;
use Database\Seeders\TheStudySeeder;
use Inertia\Testing\AssertableInertia;

beforeEach(function (): void {
    $this->seed(TheStudySeeder::class);
    $this->game = Game::query()->where('slug', 'the-study')->sole();
});

it('renders the starting scene', function (): void {
    $this->get(route('games.show', $this->game))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('game/play')
            ->where('game.slug', 'the-study')
            ->where('scene.slug', 'study')
            ->where('message', null)
            ->has('verbs', 4)
            ->has('inventory', 0)
        );
});

it('hides hotspots that are not visible yet', function (): void {
    $this->get(route('games.show', $this->game))
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->has('hotspots', 4)
            ->where('hotspots.0.slug', 'rug')
        );
});

it('creates the save file on the first visit', function (): void {
    expect(GameState::query()->count())->toBe(0);

    $this->get(route('games.show', $this->game))->assertOk();

    expect(GameState::query()->count())->toBe(1)
        ->and(GameState::for($this->game->fresh())->currentScene->slug)->toBe('study');
});

it('starts the game at its authored starting scene', function (): void {
    $hallway = Scene::query()->where('slug', 'hallway')->sole();
    $this->game->update(['starting_scene_id' => $hallway->id]);

    $this->get(route('games.show', $this->game))
        ->assertInertia(fn (AssertableInertia $page) => $page->where('scene.slug', 'hallway'));
});

it('shows the last message the game produced', function (): void {
    GameState::for($this->game)->update(['last_message' => 'The lamp gutters.']);

    $this->get(route('games.show', $this->game))
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('message', 'The lamp gutters.')
        );
});

it('does not serve an unpublished game', function (): void {
    $this->game->update(['is_published' => false]);

    $this->get(route('games.show', $this->game))->assertNotFound();
});

it('404s for a game that does not exist', function (): void {
    $this->get('/games/no-such-game')->assertNotFound();
});

it('keeps each game on its own save file', function (): void {
    $other = Game::factory()->create(['slug' => 'other']);
    $otherScene = Scene::factory()->for($other)->create(['slug' => 'attic']);
    $other->update(['starting_scene_id' => $otherScene->id]);

    GameState::for($this->game)->update(['last_message' => 'Study message.']);

    $this->get(route('games.show', $other))
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('scene.slug', 'attic')
            ->where('message', null)
        );

    expect(GameState::query()->count())->toBe(2);
});

it('restarts the game', function (): void {
    $state = GameState::for($this->game);
    $state->items()->attach(Item::query()->where('slug', 'journal')->sole());
    $state->flags()->create(['key' => 'drawer_open', 'value' => 'yes']);
    $state->update([
        'current_scene_id' => Scene::query()->where('slug', 'hallway')->sole()->id,
        'last_message' => 'Something happened.',
    ]);

    $this->delete(route('games.save.destroy', $this->game))
        ->assertRedirect(route('games.show', $this->game));

    $state->refresh();

    expect($state->currentScene->slug)->toBe('study')
        ->and($state->last_message)->toBeNull()
        ->and($state->items()->count())->toBe(0)
        ->and($state->flags()->count())->toBe(0);
});
