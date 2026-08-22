<?php

use App\Enums\EffectType;
use App\Models\Game;
use App\Models\GameState;
use App\Models\Hotspot;
use App\Models\Interaction;
use App\Models\Item;
use App\Models\Scene;
use App\Services\EffectApplier;

beforeEach(function (): void {
    $this->game = Game::factory()->create();
    $this->scene = Scene::factory()->for($this->game)->create(['slug' => 'cellar']);
    $this->hotspot = Hotspot::factory()->for($this->scene)->create(['slug' => 'crate']);
    $this->state = GameState::factory()->create([
        'game_id' => $this->game->id,
        'current_scene_id' => $this->scene->id,
    ]);
    $this->applier = app(EffectApplier::class);
});

/**
 * @param  list<array{0: EffectType, 1: string, 2?: string}>  $effects
 */
function applyEffects(array $effects): void
{
    $interaction = Interaction::factory()->for(test()->hotspot)->create();

    foreach ($effects as $index => $effect) {
        $interaction->effects()->create([
            'type' => $effect[0],
            'subject' => $effect[1],
            'value' => $effect[2] ?? null,
            'sort_order' => $index,
        ]);
    }

    test()->applier->apply(test()->state, $interaction);
}

it('gives an item', function (): void {
    Item::factory()->for($this->game)->create(['slug' => 'lantern']);

    applyEffects([[EffectType::GiveItem, 'lantern']]);

    expect($this->state->fresh()->hasItem('lantern'))->toBeTrue();
});

it('gives an item the player already holds only once', function (): void {
    $lantern = Item::factory()->for($this->game)->create(['slug' => 'lantern']);
    $this->state->items()->attach($lantern);

    applyEffects([[EffectType::GiveItem, 'lantern']]);

    expect($this->state->items()->count())->toBe(1);
});

it('removes an item', function (): void {
    $lantern = Item::factory()->for($this->game)->create(['slug' => 'lantern']);
    $this->state->items()->attach($lantern);

    applyEffects([[EffectType::RemoveItem, 'lantern']]);

    expect($this->state->fresh()->hasItem('lantern'))->toBeFalse();
});

it('sets a flag', function (): void {
    applyEffects([[EffectType::SetFlag, 'crate_open', 'yes']]);

    expect($this->state->fresh()->flagValue('crate_open'))->toBe('yes');
});

it('overwrites an existing flag', function (): void {
    $this->state->flags()->create(['key' => 'crate_open', 'value' => 'no']);

    applyEffects([[EffectType::SetFlag, 'crate_open', 'yes']]);

    expect($this->state->fresh()->flagValue('crate_open'))->toBe('yes')
        ->and($this->state->flags()->count())->toBe(1);
});

it('moves the player to another scene', function (): void {
    Scene::factory()->for($this->game)->create(['slug' => 'attic']);

    applyEffects([[EffectType::MoveToScene, 'attic']]);

    expect($this->state->fresh()->currentScene->slug)->toBe('attic');
});

it('reveals a hidden hotspot', function (): void {
    Hotspot::factory()->for($this->scene)->hidden()->create(['slug' => 'hatch']);

    applyEffects([[EffectType::RevealHotspot, 'hatch']]);

    expect($this->state->fresh()->visibleHotspots()->pluck('slug'))->toContain('hatch');
});

it('hides a visible hotspot', function (): void {
    Hotspot::factory()->for($this->scene)->create(['slug' => 'hatch']);

    applyEffects([[EffectType::HideHotspot, 'hatch']]);

    expect($this->state->fresh()->visibleHotspots()->pluck('slug'))->not->toContain('hatch');
});

it('can toggle the same hotspot twice', function (): void {
    Hotspot::factory()->for($this->scene)->hidden()->create(['slug' => 'hatch']);

    applyEffects([[EffectType::RevealHotspot, 'hatch']]);
    applyEffects([[EffectType::HideHotspot, 'hatch']]);

    expect($this->state->fresh()->visibleHotspots()->pluck('slug'))->not->toContain('hatch');
});

it('targets a hotspot in another scene with a prefixed slug', function (): void {
    $attic = Scene::factory()->for($this->game)->create(['slug' => 'attic']);
    Hotspot::factory()->for($attic)->hidden()->create(['slug' => 'crate']);

    applyEffects([[EffectType::RevealHotspot, 'attic/crate']]);

    $this->state->update(['current_scene_id' => $attic->id]);

    expect($this->state->fresh()->visibleHotspots()->pluck('slug'))->toContain('crate');
});

it('fails loudly when an effect references something that does not exist', function (): void {
    applyEffects([[EffectType::GiveItem, 'nonexistent']]);
})->throws(RuntimeException::class, 'unknown item');

it('will not reach an item belonging to another game', function (): void {
    Item::factory()->for(Game::factory())->create(['slug' => 'lantern']);

    applyEffects([[EffectType::GiveItem, 'lantern']]);
})->throws(RuntimeException::class, 'unknown item');

it('will not reach a scene belonging to another game', function (): void {
    Scene::factory()->for(Game::factory())->create(['slug' => 'attic']);

    applyEffects([[EffectType::MoveToScene, 'attic']]);
})->throws(RuntimeException::class, 'unknown scene');

it('applies effects in order', function (): void {
    Item::factory()->for($this->game)->create(['slug' => 'lantern']);

    applyEffects([
        [EffectType::GiveItem, 'lantern'],
        [EffectType::RemoveItem, 'lantern'],
    ]);

    expect($this->state->fresh()->hasItem('lantern'))->toBeFalse();
});
