<?php

use App\Enums\ConditionType;
use App\Enums\EffectType;
use App\Enums\Verb;
use App\Models\Game;
use App\Models\GameState;
use App\Models\Interaction;
use App\Models\Item;
use App\Models\Level;
use App\Models\LevelThing;
use Database\Seeders\LifeSeeder;
use Inertia\Inertia;

/**
 * Doing things to the things standing in a first-person level.
 *
 * The verb, the conditions and the effects are the same machinery the scene
 * games have always used; what is new is that a thing in a room can own them,
 * and that the answer comes back without the level being rebuilt underneath the
 * player.
 */
beforeEach(function (): void {
    $this->seed(LifeSeeder::class);

    $this->game = Game::query()->where('slug', 'life')->sole();
    $this->level = $this->game->levels()->firstOrFail();

    $this->key = Item::query()->create([
        'game_id' => $this->game->id,
        'slug' => 'shed-key',
        'name' => 'shed key',
        'description' => 'A small key on a loop of string.',
    ]);

    $this->pot = LevelThing::factory()->create([
        'level_id' => $this->level->id,
        'slug' => 'flower-pot',
        'name' => 'Flower pot',
        'description' => 'A cracked pot with nothing growing in it.',
    ]);
});

/**
 * Applies a verb to a thing and hands back what the player was told.
 */
function tryOn(string $thing, string $verb, ?string $item = null): string
{
    $game = test()->game;

    test()->post(route('games.interactions.store', $game), [
        'level' => test()->level->slug,
        'thing' => $thing,
        'verb' => $verb,
        'item' => $item,
    ])->assertRedirect();

    return (string) GameState::for($game->fresh())->last_message;
}

it('fires the interaction hung on a thing', function (): void {
    Interaction::factory()->on($this->pot)->create([
        'verb' => Verb::Look,
        'response' => 'There is a key under it.',
    ]);

    expect(tryOn('flower-pot', 'look'))->toBe('There is a key under it.');
});

it('falls back when the thing does not answer to that verb', function (): void {
    expect(tryOn('flower-pot', 'take'))->toBe(Verb::Take->fallbackResponse());
});

it('hands over an item and remembers that it did', function (): void {
    $interaction = Interaction::factory()->on($this->pot)->create([
        'verb' => Verb::Take,
        'response' => 'You take the key.',
    ]);

    $interaction->effects()->create([
        'type' => EffectType::GiveItem,
        'subject' => 'shed-key',
        'sort_order' => 0,
    ]);

    expect(tryOn('flower-pot', 'take'))->toBe('You take the key.');

    $state = GameState::for($this->game->fresh())->load('items');

    expect($state->items->pluck('slug')->all())->toBe(['shed-key']);
});

it('holds an interaction back until its conditions are met', function (): void {
    $locked = Interaction::factory()->on($this->pot)->create([
        'verb' => Verb::Use,
        'response' => 'You lift the pot and take the key.',
        'priority' => 10,
    ]);

    $locked->conditions()->create([
        'type' => ConditionType::FlagIs,
        'subject' => 'shed-found',
        'value' => 'yes',
    ]);

    Interaction::factory()->on($this->pot)->create([
        'verb' => Verb::Use,
        'response' => 'You have no reason to move it.',
    ]);

    expect(tryOn('flower-pot', 'use'))->toBe('You have no reason to move it.');

    GameState::for($this->game->fresh())->flags()->create([
        'key' => 'shed-found',
        'value' => 'yes',
    ]);

    expect(tryOn('flower-pot', 'use'))->toBe('You lift the pot and take the key.');
});

it('only fires an interaction that needs an item when it is being carried', function (): void {
    $interaction = Interaction::factory()->on($this->pot)->create([
        'verb' => Verb::Use,
        'response' => 'The key fits the lock scratched into the rim.',
        'required_item_id' => $this->key->id,
    ]);

    expect($interaction->fresh()->required_item_id)->toBe($this->key->id);

    // Not carrying it: the server refuses before the verb is even resolved.
    test()->post(route('games.interactions.store', $this->game), [
        'level' => $this->level->slug,
        'thing' => 'flower-pot',
        'verb' => 'use',
        'item' => 'shed-key',
    ])->assertSessionHasErrors('item');

    GameState::for($this->game->fresh())->items()->attach($this->key->id);

    expect(tryOn('flower-pot', 'use', 'shed-key'))
        ->toBe('The key fits the lock scratched into the rim.');
});

it('will not touch a thing in another level', function (): void {
    $elsewhere = Level::factory()->create(['game_id' => $this->game->id]);

    $this->post(route('games.interactions.store', $this->game), [
        'level' => $elsewhere->slug,
        'thing' => 'flower-pot',
        'verb' => 'look',
    ])->assertSessionHasErrors('thing');
});

it('will not take a hotspot and a thing at once', function (): void {
    $this->post(route('games.interactions.store', $this->game), [
        'level' => $this->level->slug,
        'thing' => 'flower-pot',
        'hotspot' => 'portrait',
        'verb' => 'look',
    ])->assertSessionHasErrors('hotspot');
});

it('tells the browser which verbs a thing answers to, and nothing more', function (): void {
    Interaction::factory()->on($this->pot)->create(['verb' => Verb::Look]);
    Interaction::factory()->on($this->pot)->create([
        'verb' => Verb::Use,
        'required_item_id' => $this->key->id,
    ]);

    // Two of the same verb are one offer: the menu shows a line, not a rule.
    Interaction::factory()->on($this->pot)->create(['verb' => Verb::Look]);

    $this->get(route('games.show', [$this->game, 'level' => $this->level->slug]))
        ->assertInertia(fn ($page) => $page
            ->where('level.things', fn ($things) => collect($things)
                ->firstWhere('slug', 'flower-pot')['verbs'] === [
                    ['verb' => 'look', 'item' => null],
                    ['verb' => 'use', 'item' => 'shed-key'],
                ]
            )
        );
});

it('leaves the level alone when only the inventory is asked for', function (): void {
    // The whole point of answering a verb with a partial reload: the browser
    // keeps the level it has already built, so the player carries on standing
    // where they were rather than being put back at the spawn.
    $url = route('games.show', [$this->game, 'level' => $this->level->slug]);

    // The asset version is settled by the middleware while a request is being
    // handled, so one whole page has to be asked for before a partial one can
    // name the same version and avoid a 409.
    $this->get($url)->assertOk();

    $version = (string) Inertia::getVersion();

    $partial = $this->get($url, [
        'X-Inertia' => 'true',
        'X-Inertia-Version' => $version,
        'X-Inertia-Partial-Component' => 'game/explore',
        'X-Inertia-Partial-Data' => 'inventory,message',
    ])->assertOk();

    expect(array_keys($partial->json('props')))
        ->toEqualCanonicalizing(['errors', 'inventory', 'message']);
});

it('never tells a player what the conditions are', function (): void {
    $interaction = Interaction::factory()->on($this->pot)->create(['verb' => Verb::Use]);

    $interaction->conditions()->create([
        'type' => ConditionType::FlagIs,
        'subject' => 'the-secret',
        'value' => 'yes',
    ]);

    $page = $this->get(route('games.show', [$this->game, 'level' => $this->level->slug]));

    $page->assertDontSee('the-secret');
});
