<?php

use App\Enums\ConditionType;
use App\Enums\Verb;
use App\Models\Game;
use App\Models\GameState;
use App\Models\Hotspot;
use App\Models\Interaction;
use App\Models\Item;
use App\Models\Scene;
use App\Services\InteractionResolver;

beforeEach(function (): void {
    $this->game = Game::factory()->create();
    $this->scene = Scene::factory()->for($this->game)->create();
    $this->hotspot = Hotspot::factory()->for($this->scene)->create();
    $this->state = GameState::factory()->create([
        'game_id' => $this->game->id,
        'current_scene_id' => $this->scene->id,
    ]);
    $this->resolver = app(InteractionResolver::class);
});

it('returns null when the hotspot has no interaction for the verb', function (): void {
    Interaction::factory()->for($this->hotspot)->verb(Verb::Look)->create();

    expect($this->resolver->resolve($this->state, $this->hotspot, Verb::Take))->toBeNull();
});

it('prefers the highest priority interaction', function (): void {
    Interaction::factory()->for($this->hotspot)->create(['response' => 'low', 'priority' => 0]);
    Interaction::factory()->for($this->hotspot)->create(['response' => 'high', 'priority' => 5]);

    $resolved = $this->resolver->resolve($this->state, $this->hotspot, Verb::Look);

    expect($resolved->response)->toBe('high');
});

it('falls through to the next interaction when conditions fail', function (): void {
    $blocked = Interaction::factory()->for($this->hotspot)->create(['response' => 'blocked', 'priority' => 5]);
    $blocked->conditions()->create([
        'type' => ConditionType::HasItem,
        'subject' => 'lantern',
    ]);

    Interaction::factory()->for($this->hotspot)->create(['response' => 'open', 'priority' => 0]);

    expect($this->resolver->resolve($this->state, $this->hotspot, Verb::Look)->response)->toBe('open');
});

it('requires every condition on an interaction to pass', function (): void {
    $interaction = Interaction::factory()->for($this->hotspot)->create();
    $interaction->conditions()->createMany([
        ['type' => ConditionType::HasItem, 'subject' => 'lantern'],
        ['type' => ConditionType::FlagIs, 'subject' => 'lit', 'value' => 'yes'],
    ]);

    $lantern = Item::factory()->for($this->game)->create(['slug' => 'lantern']);
    $this->state->items()->attach($lantern);

    expect($this->resolver->resolve($this->state->fresh(), $this->hotspot, Verb::Look))->toBeNull();

    $this->state->flags()->create(['key' => 'lit', 'value' => 'yes']);

    expect($this->resolver->resolve($this->state->fresh(), $this->hotspot, Verb::Look))->not->toBeNull();
});

it('evaluates each condition type', function (ConditionType $type, string $subject, ?string $value, bool $expected): void {
    $interaction = Interaction::factory()->for($this->hotspot)->create();
    $interaction->conditions()->create([
        'type' => $type,
        'subject' => $subject,
        'value' => $value,
    ]);

    $this->state->items()->attach(Item::factory()->for($this->game)->create(['slug' => 'lantern']));
    $this->state->flags()->create(['key' => 'lit', 'value' => 'yes']);

    $resolved = $this->resolver->resolve($this->state->fresh(), $this->hotspot, Verb::Look);

    expect($resolved !== null)->toBe($expected);
})->with([
    'has a carried item' => [ConditionType::HasItem, 'lantern', null, true],
    'has an item that is not carried' => [ConditionType::HasItem, 'rope', null, false],
    'missing an item that is not carried' => [ConditionType::MissingItem, 'rope', null, true],
    'missing an item that is carried' => [ConditionType::MissingItem, 'lantern', null, false],
    'flag matches' => [ConditionType::FlagIs, 'lit', 'yes', true],
    'flag does not match' => [ConditionType::FlagIs, 'lit', 'no', false],
    'unset flag does not match' => [ConditionType::FlagIs, 'locked', 'yes', false],
    'flag differs' => [ConditionType::FlagIsNot, 'lit', 'no', true],
    'flag does not differ' => [ConditionType::FlagIsNot, 'lit', 'yes', false],
]);

it('only matches an item interaction when that item is supplied', function (): void {
    $key = Item::factory()->for($this->game)->create();
    Interaction::factory()->for($this->hotspot)->requiring($key)->create(['response' => 'with key']);
    Interaction::factory()->for($this->hotspot)->verb(Verb::Use)->create(['response' => 'bare hands']);

    expect($this->resolver->resolve($this->state, $this->hotspot, Verb::Use)->response)->toBe('bare hands')
        ->and($this->resolver->resolve($this->state, $this->hotspot, Verb::Use, $key)->response)->toBe('with key');
});

it('does not match an interaction requiring a different item', function (): void {
    $key = Item::factory()->for($this->game)->create();
    $crowbar = Item::factory()->for($this->game)->create();
    Interaction::factory()->for($this->hotspot)->requiring($key)->create();

    expect($this->resolver->resolve($this->state, $this->hotspot, Verb::Use, $crowbar))->toBeNull();
});
