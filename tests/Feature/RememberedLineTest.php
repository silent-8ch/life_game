<?php

use App\Enums\EmitWhen;
use App\Models\Game;
use App\Models\GameState;
use App\Models\Level;
use App\Models\LevelThing;
use Database\Seeders\LifeSeeder;

/**
 * The one flag the browser is allowed to write, and everything it is not.
 *
 * Line names and flag names share a namespace, so an endpoint that took a name
 * and wrote it would be an endpoint for setting any flag in the game — which is
 * every lock in every game. The guarantee instead is that a flag may only be
 * written by the thing that answers for it: a listener writing the name it
 * declares, or a lever writing its own latch.
 *
 * What is actually being protected here is a save file, so the tests that
 * matter most are the refusals.
 */
beforeEach(function (): void {
    $this->seed(LifeSeeder::class);

    $this->game = Game::query()->where('slug', 'life')->sole();
    $this->level = Level::query()->where('slug', 'tech-demo')->sole();
});

/** A thing in the level, made only to declare something. */
function declaring(Level $level, array $fields): LevelThing
{
    return LevelThing::factory()->for($level, 'level')->create($fields);
}

it('remembers a flag a listener declares', function (): void {
    declaring($this->level, ['slug' => 'writer', 'writes_flag' => 'lamp-lit']);

    $this->post(route('games.line.store', $this->game), [
        'level' => 'tech-demo',
        'flag' => 'lamp-lit',
        'on' => true,
    ])->assertNoContent();

    expect(GameState::for($this->game)->flags()->pluck('value', 'key')->all())
        ->toBe(['lamp-lit' => 'on']);
});

it('forgets it again rather than storing an off', function (): void {
    declaring($this->level, ['slug' => 'writer', 'writes_flag' => 'lamp-lit']);

    $state = GameState::for($this->game);
    $state->flags()->create(['key' => 'lamp-lit', 'value' => 'on']);

    $this->post(route('games.line.store', $this->game), [
        'level' => 'tech-demo',
        'flag' => 'lamp-lit',
        'on' => false,
    ])->assertNoContent();

    // Absent rather than empty: "is this set" is a question about the keys, and
    // an unset flag must not be tellable from one set to nothing.
    expect($state->fresh()->flags)->toHaveCount(0);
});

it('remembers a lever by its own latch', function (): void {
    declaring($this->level, [
        'slug' => 'big-switch',
        'emit_when' => EmitWhen::Used,
    ]);

    $this->post(route('games.line.store', $this->game), [
        'level' => 'tech-demo',
        'flag' => 'lever:big-switch',
        'on' => true,
    ])->assertNoContent();

    expect(GameState::for($this->game)->flags()->pluck('key')->all())
        ->toBe(['lever:big-switch']);
});

it('turns away a latch for a thing that is not a lever', function (): void {
    declaring($this->level, ['slug' => 'crate-2', 'emit_when' => null]);

    $this->post(route('games.line.store', $this->game), [
        'level' => 'tech-demo',
        'flag' => 'lever:crate-2',
        'on' => true,
    ])->assertSessionHasErrors('flag');

    expect(GameState::for($this->game)->flags)->toHaveCount(0);
});

it('turns away a flag nothing in the level answers for', function (): void {
    $this->post(route('games.line.store', $this->game), [
        'level' => 'tech-demo',
        'flag' => 'front-door-unlocked',
        'on' => true,
    ])->assertSessionHasErrors('flag');

    expect(GameState::for($this->game)->flags)->toHaveCount(0);
});

it('turns away a listener in some other level', function (): void {
    $elsewhere = Level::factory()->for($this->game, 'game')
        ->create(['slug' => 'attic']);

    declaring($elsewhere, ['slug' => 'writer', 'writes_flag' => 'far-away']);

    $this->post(route('games.line.store', $this->game), [
        'level' => 'tech-demo',
        'flag' => 'far-away',
        'on' => true,
    ])->assertSessionHasErrors('flag');

    expect(GameState::for($this->game)->flags)->toHaveCount(0);
});

it('turns away a level in some other game', function (): void {
    $other = Game::factory()->create();
    $theirs = Level::factory()->for($other, 'game')->create(['slug' => 'theirs']);

    declaring($theirs, ['slug' => 'writer', 'writes_flag' => 'theirs-lit']);

    $this->post(route('games.line.store', $this->game), [
        'level' => 'theirs',
        'flag' => 'theirs-lit',
        'on' => true,
    ])->assertSessionHasErrors('flag');

    expect(GameState::for($this->game)->flags)->toHaveCount(0);
});
