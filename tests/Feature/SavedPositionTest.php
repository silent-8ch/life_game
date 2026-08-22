<?php

use App\Models\Game;
use App\Models\GameState;
use App\Models\Level;
use Database\Seeders\LifeSeeder;
use Inertia\Testing\AssertableInertia;

/**
 * Remembering where the player got to.
 *
 * `current_level_id` already remembered which level and nothing remembered
 * where in it, so shutting the game and coming back put you on the front step.
 * That mattered little when a level was a room to look at, and matters more now
 * there are doors to have opened and stairs to have climbed.
 *
 * The thing worth pinning hardest is the **sign of the angle**. What is stored
 * is the player's own yaw, in degrees — deliberately not `levels.spawn_angle`,
 * which is its negative, because the engine reads a spawn as
 * `yaw = -degToRad(spawn.angle)`. Two encodings of one idea that disagree by a
 * sign is how a saved game ends up facing a wall, and this project has already
 * lost an evening to a reproduction that was mis-aimed for exactly that reason.
 */
beforeEach(function (): void {
    $this->seed(LifeSeeder::class);
    $this->game = Game::query()->where('slug', 'life')->sole();
});

it('has nowhere to put the player until they have been somewhere', function (): void {
    $this->get(route('games.show', $this->game))
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('standingAt', null)
        );
});

it('remembers where the player got to', function (): void {
    $this->post(route('games.position.store', $this->game), [
        'x' => 12.5,
        'z' => -3.25,
        'facing' => 135.0,
        'pitch' => -8.5,
    ])->assertNoContent();

    $state = GameState::for($this->game->fresh());

    expect($state->position_x)->toEqual(12.5)
        ->and($state->position_z)->toEqual(-3.25)
        ->and($state->facing)->toEqual(135.0)
        ->and($state->pitch)->toEqual(-8.5);
});

it('hands the spot back the same way round it was given', function (): void {
    // The whole point. A sign flip anywhere between here and the engine turns
    // "looking down the hall" into "looking at the wall behind you", and it
    // would look like a rendering fault rather than an arithmetic one.
    $this->post(route('games.position.store', $this->game), [
        'x' => 12.5,
        'z' => -3.25,
        'facing' => 135.0,
        'pitch' => -8.5,
    ]);

    $this->get(route('games.show', $this->game))
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('standingAt.x', 12.5)
            ->where('standingAt.z', -3.25)
            // Compared loosely: JSON hands 135.0 back as an int, and this test
            // is about the sign surviving the round trip, not about PHP's
            // opinion of whole numbers.
            ->where('standingAt.facing', fn (float|int $facing): bool => (float) $facing === 135.0)
            ->where('standingAt.pitch', fn (float|int $pitch): bool => (float) $pitch === -8.5)
        );
});

it('answers without a page, so nobody is teleported mid-stride', function (): void {
    // This is sent while somebody is walking about. Anything that re-renders
    // would rebuild the level under their feet — the same reason the
    // interaction round trip asks for two fields and not the geometry.
    $this->post(route('games.position.store', $this->game), [
        'x' => 1.0,
        'z' => 1.0,
        'facing' => 0.0,
        'pitch' => 0.0,
    ])
        ->assertNoContent()
        ->assertHeaderMissing('X-Inertia');
});

it('will not store a spot no level could contain', function (): void {
    // Not really a security boundary — anybody may post what they like about
    // their own save. It is a way of making a bug loud rather than filing a
    // player at x = 1e30 and puzzling over it a week later.
    $this->postJson(route('games.position.store', $this->game), [
        'x' => 1.0e30,
        'z' => 1.0,
        'facing' => 0.0,
        'pitch' => 0.0,
    ])->assertJsonValidationErrors(['x']);

    // And a pitch past straight up is somebody's arithmetic going wrong.
    $this->postJson(route('games.position.store', $this->game), [
        'x' => 1.0,
        'z' => 1.0,
        'facing' => 0.0,
        'pitch' => 400.0,
    ])->assertJsonValidationErrors(['pitch']);
});

it('offers nothing when the player is dropped into another level', function (): void {
    // ?level= is how the editor's Play button shows the level being edited. A
    // position is a place in one particular level and means nothing in another
    // — putting somebody at those coordinates in a different level would stand
    // them in a wall, or in mid-air.
    $this->post(route('games.position.store', $this->game), [
        'x' => 12.5,
        'z' => -3.25,
        'facing' => 135.0,
        'pitch' => 0.0,
    ]);

    // A second level, made here rather than found, so the guard is tested
    // rather than skipped on a game that happens to have one.
    $other = Level::factory()->for($this->game)->create(['slug' => 'somewhere-else']);

    $this->get(route('games.show', ['game' => $this->game, 'level' => $other->slug]))
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('level.slug', $other->slug)
            ->where('standingAt', null)
        );
});

it('forgets where you were when the save is started over', function (): void {
    // Leaving it behind would put a reset save back exactly where the old one
    // ended, which is the one thing starting over is meant not to do.
    $this->post(route('games.position.store', $this->game), [
        'x' => 12.5,
        'z' => -3.25,
        'facing' => 135.0,
        'pitch' => 0.0,
    ]);

    $this->delete(route('games.save.destroy', $this->game));

    $state = GameState::for($this->game->fresh());

    expect($state->position_x)->toBeNull()
        ->and($state->position_z)->toBeNull()
        ->and($state->facing)->toBeNull()
        ->and($state->pitch)->toBeNull();
});
