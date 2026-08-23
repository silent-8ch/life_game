<?php

use App\Enums\EffectType;
use App\Enums\ThingHinge;
use App\Enums\ThingRender;
use App\Enums\Verb;
use App\Models\Game;
use App\Models\GameState;
use App\Models\Level;
use App\Models\LevelThing;
use Database\Seeders\LifeSeeder;
use Inertia\Testing\AssertableInertia;

/**
 * Turning a thing, and letting go of the way, as ordinary effects.
 *
 * Paul: *a door is just a solid sprite that has a hinge with an action.* The
 * two halves of that action are `rotate_thing` and `set_blocking`, and neither
 * knows it is making a door — the same pair makes a drawbridge, a hatch, or a
 * shutter, and the difference between them is which edge the thing is hinged on
 * and what number somebody typed.
 *
 * They are also the only two effects the browser is ever told about, because
 * they are the only two whose result the player is standing inside: **you walk
 * through a door in the same frame it opens**, and an interaction is a round
 * trip that also returns an inventory and a message.
 */
/** Where the hatch sits in the payload's list of things. */
function hatchIndex(): int
{
    return Level::query()->where('slug', 'tech-demo')->sole()
        ->things()->orderBy('sort_order')->pluck('slug')->search('hatch');
}

beforeEach(function (): void {
    $this->seed(LifeSeeder::class);

    $this->game = Game::query()->where('slug', 'life')->sole();
    $this->level = Level::query()->where('slug', 'tech-demo')->sole();

    $this->hatch = $this->level->things()->create([
        'slug' => 'hatch',
        'name' => 'Hatch',
        'description' => 'Shut.',
        'kind' => 'door',
        'render' => ThingRender::Flat,
        'hinge' => ThingHinge::Top,
        'x' => 2, 'z' => 2, 'elevation' => 0,
        'width' => 1, 'depth' => 0.1, 'height' => 2,
        'angle' => 0, 'is_solid' => true, 'sort_order' => 99,
    ]);
});

/** Gives the hatch a Use that opens it, and fires it. */
function opensAndFire(LevelThing $thing, Game $game, float $degrees = 90): void
{
    $interaction = $thing->interactions()->create([
        'verb' => Verb::Use,
        'response' => 'It swings open.',
        'priority' => 0,
    ]);

    $interaction->effects()->createMany([
        ['type' => EffectType::RotateThing, 'subject' => $thing->slug, 'value' => (string) $degrees, 'sort_order' => 0],
        ['type' => EffectType::SetBlocking, 'subject' => $thing->slug, 'value' => '0', 'sort_order' => 1],
    ]);

    test()->post(route('games.interactions.store', $game), [
        'level' => $thing->level->slug,
        'thing' => $thing->slug,
        'verb' => 'use',
        'item' => '',
    ]);
}

it('writes a turn and a blocking onto the save rather than onto the level', function (): void {
    opensAndFire($this->hatch, $this->game);

    $state = GameState::for($this->game->fresh())->fresh(['thingOverrides']);
    $moved = $state->thingOverrides->firstWhere('slug', 'hatch');

    expect($moved)->not->toBeNull()
        ->and((float) $moved->pivot->getAttribute('turned'))->toEqual(90.0)
        ->and((bool) $moved->pivot->getAttribute('blocking'))->toBeFalse();

    // And the level is untouched. A level says how a thing was authored; a save
    // says what has happened to it since, and writing the second onto the first
    // would mean a level could not be played twice.
    expect($this->hatch->fresh()->is_solid)->toBeTrue();
});

it('keeps both halves when the two effects are fired together', function (): void {
    opensAndFire($this->hatch, $this->game);

    $state = GameState::for($this->game->fresh())->fresh(['thingOverrides']);
    $moved = $state->thingOverrides->firstWhere('slug', 'hatch');

    // One row, both columns. They are authored as separate effects and fire in
    // order, so turning it must not forget that it stopped blocking and
    // stopping it blocking must not forget the angle.
    expect($moved->pivot->getAttribute('turned'))->not->toBeNull()
        ->and($moved->pivot->getAttribute('blocking'))->not->toBeNull();
});

it('tells the browser what has been moved, and what a verb would move', function (): void {
    opensAndFire($this->hatch, $this->game);

    $this->get(route('games.show', $this->game).'?level=tech-demo')
        ->assertInertia(fn (AssertableInertia $page) => $page
            // What has happened, so a reload puts it back where it was left.
            ->where('moved.hatch.turned', 90)
            ->where('moved.hatch.blocking', false)
            // And what the verb would do, so the engine can do it the instant
            // it is asked rather than waiting for a round trip.
            ->where('level.things.'.hatchIndex().'.verbs.0.moves', [
                ['does' => 'rotate_thing', 'subject' => 'hatch', 'value' => '90'],
                ['does' => 'set_blocking', 'subject' => 'hatch', 'value' => '0'],
            ])
        );
});

it('tells the browser nothing about the effects it does not have to run', function (): void {
    $interaction = $this->hatch->interactions()->create([
        'verb' => Verb::Use,
        'response' => 'A key turns.',
        'priority' => 0,
    ]);

    $interaction->effects()->createMany([
        ['type' => EffectType::SetFlag, 'subject' => 'hatch-open', 'value' => 'yes', 'sort_order' => 0],
        ['type' => EffectType::RotateThing, 'subject' => 'hatch', 'value' => '90', 'sort_order' => 1],
    ]);

    $this->get(route('games.show', $this->game).'?level=tech-demo')
        ->assertInertia(fn (AssertableInertia $page) => $page
            // The rotate travels and the flag does not. A flag being set can
            // wait for the answer; a door cannot, and that is the whole of the
            // rule deciding what is in this list.
            ->where('level.things.'.hatchIndex().'.verbs.0.moves', [
                ['does' => 'rotate_thing', 'subject' => 'hatch', 'value' => '90'],
            ])
        );
});
