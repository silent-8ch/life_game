<?php

use App\Models\Game;
use App\Models\GameState;
use App\Models\Scene;
use Database\Seeders\TheStudySeeder;

beforeEach(function (): void {
    $this->seed(TheStudySeeder::class);
    $this->game = Game::query()->where('slug', 'the-study')->sole();
});

/**
 * Perform a verb on a hotspot and return the resulting message.
 */
function interact(string $hotspot, string $verb, ?string $item = null): string
{
    $game = test()->game;

    test()->post(route('games.interactions.store', $game), [
        'hotspot' => $hotspot,
        'verb' => $verb,
        'item' => $item,
    ])->assertRedirect(route('games.show', $game));

    return (string) GameState::for($game->fresh())->last_message;
}

it('stores the response of the matched interaction', function (): void {
    expect(interact('portrait', 'look'))->toContain('A stern man in a black coat');
});

it('falls back to a default response when nothing matches', function (): void {
    expect(interact('portrait', 'take'))->toBe('You cannot take that.');
});

it('reveals a hidden hotspot and lets the player take the item behind it', function (): void {
    $this->get(route('games.show', $this->game))->assertInertia(fn ($page) => $page->has('hotspots', 4));

    expect(interact('rug', 'use'))->toContain('Something small catches the lamplight');

    $this->get(route('games.show', $this->game))->assertInertia(fn ($page) => $page->has('hotspots', 5));

    expect(interact('brass-key', 'take'))->toBe('You pocket the brass key.');

    $state = GameState::for($this->game->fresh());

    expect($state->hasItem('brass-key'))->toBeTrue()
        ->and($state->flagValue('rug_moved'))->toBe('yes');

    $this->get(route('games.show', $this->game))->assertInertia(fn ($page) => $page
        ->has('hotspots', 4)
        ->has('inventory', 1)
    );
});

it('does not repeat an interaction whose flag has already been set', function (): void {
    interact('rug', 'use');

    expect(interact('rug', 'use'))->toContain('already crumpled');
});

it('unlocks the drawer with the brass key, trading it for the journal', function (): void {
    interact('rug', 'use');
    interact('brass-key', 'take');

    expect(interact('desk', 'use'))->toContain('The drawer is locked');
    expect(interact('desk', 'use', 'brass-key'))->toContain('leather journal');

    $state = GameState::for($this->game->fresh());

    expect($state->hasItem('journal'))->toBeTrue()
        ->and($state->hasItem('brass-key'))->toBeFalse()
        ->and($state->flagValue('drawer_open'))->toBe('yes');
});

it('keeps the player in the study until they hold the journal', function (): void {
    expect(interact('door', 'use'))->toContain('empty-handed');
    expect(GameState::for($this->game->fresh())->currentScene->slug)->toBe('study');

    interact('rug', 'use');
    interact('brass-key', 'take');
    interact('desk', 'use', 'brass-key');

    expect(interact('door', 'use'))->toContain('hallway');
    expect(GameState::for($this->game->fresh())->currentScene->slug)->toBe('hallway');
});

it('rejects a hotspot that is not in the current scene', function (): void {
    $this->post(route('games.interactions.store', $this->game), [
        'hotspot' => 'front-door',
        'verb' => 'look',
    ])->assertSessionHasErrors('hotspot');
});

it('rejects a hotspot that is hidden', function (): void {
    $this->post(route('games.interactions.store', $this->game), [
        'hotspot' => 'brass-key',
        'verb' => 'take',
    ])->assertSessionHasErrors('hotspot');
});

it('rejects an unknown verb', function (): void {
    $this->post(route('games.interactions.store', $this->game), [
        'hotspot' => 'desk',
        'verb' => 'smell',
    ])->assertSessionHasErrors('verb');
});

it('rejects an item the player is not carrying', function (): void {
    $this->post(route('games.interactions.store', $this->game), [
        'hotspot' => 'desk',
        'verb' => 'use',
        'item' => 'journal',
    ])->assertSessionHasErrors('item');
});

it('rejects an item on a verb that does not accept one', function (): void {
    interact('rug', 'use');
    interact('brass-key', 'take');

    $this->post(route('games.interactions.store', $this->game), [
        'hotspot' => 'desk',
        'verb' => 'look',
        'item' => 'brass-key',
    ])->assertSessionHasErrors('item');
});

it('rejects an item that does not exist', function (): void {
    $this->post(route('games.interactions.store', $this->game), [
        'hotspot' => 'desk',
        'verb' => 'use',
        'item' => 'crowbar',
    ])->assertSessionHasErrors('item');
});

it('rejects a hotspot slug that only exists in another game', function (): void {
    $other = Game::factory()->create(['slug' => 'other']);
    $scene = Scene::factory()->for($other)->create();
    $other->update(['starting_scene_id' => $scene->id]);

    $this->post(route('games.interactions.store', $other), [
        'hotspot' => 'desk',
        'verb' => 'look',
    ])->assertSessionHasErrors('hotspot');
});

it('does not serve an unpublished game', function (): void {
    $this->game->update(['is_published' => false]);

    $this->post(route('games.interactions.store', $this->game), [
        'hotspot' => 'desk',
        'verb' => 'look',
    ])->assertNotFound();
});

it('leaves the save file untouched when validation fails', function (): void {
    $this->get(route('games.show', $this->game));

    $this->post(route('games.interactions.store', $this->game), [
        'hotspot' => 'front-door',
        'verb' => 'use',
    ])->assertSessionHasErrors('hotspot');

    expect(GameState::for($this->game->fresh())->last_message)->toBeNull();
});
