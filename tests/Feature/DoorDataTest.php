<?php

use App\Enums\DoorSwing;
use App\Models\Game;
use App\Models\Level;
use App\Models\LevelThing;
use App\Models\User;
use Illuminate\Testing\TestResponse;

/**
 * Doors that open, as far as the database and the wire are concerned.
 *
 * The half worth testing hardest is the one that is new: a door has to stand in
 * a doorway. The hole in the wall and the thing in the hole are authored
 * separately — two runs of wall with a gap, and a thing placed in the gap — so
 * nothing has ever stopped them drifting apart. Move a wall and the door is
 * left standing in the middle of a room; place one carelessly and it is inside
 * solid brick. Both look plausible in plan and neither is found until somebody
 * walks at it.
 *
 * `is_open` is the *starting* state, and that distinction is the whole design.
 * Where a door stands while somebody is playing belongs to the engine, because
 * you walk through a door in the same frame it opens.
 */

/**
 * Two rooms sharing the wall along z = 4, with a door standing in it.
 *
 * @param  array<string, mixed>  $door
 * @param  array<string, mixed>  $changes
 * @return array<string, mixed>
 */
function aMapWithDoor(array $door = [], array $changes = []): array
{
    $corner = fn (float $x, float $z, bool $blocks = true, array $more = []): array => array_merge([
        'x' => $x,
        'z' => $z,
        'blocks' => $blocks,
        'wallTexture' => null,
        'isMirror' => false,
        'isSky' => false,
        'portalLink' => null,
    ], $more);

    return array_merge([
        'name' => 'Drawn',
        'description' => 'Two rooms and a door between them.',
        'spawn' => ['x' => 1.0, 'z' => 1.0, 'angle' => 0.0],
        'playerSprite' => 'paul',
        'ceilingHeight' => 3.0,
        'sky' => null,
        'things' => [array_merge([
            'slug' => 'front-door',
            'name' => 'Front door',
            'description' => 'A door.',
            'kind' => 'door',
            'sprite' => null,
            'behaviour' => null,
            'speed' => 0.0,
            'texture' => null,
            'x' => 4.0,
            'z' => 4.0,
            'elevation' => 0.0,
            'width' => 0.9,
            'depth' => 0.15,
            'height' => 2.0,
            'angle' => 0.0,
            'isSolid' => true,
            'isDoor' => true,
        ], $door)],
        'sectors' => [
            [
                'slug' => 'south',
                'name' => 'South',
                'floorHeight' => 0.0,
                'ceilingHeight' => 3.0,
                'floorTexture' => null,
                'ceilingTexture' => null,
                'wallTexture' => null,
                'isSky' => false,
                'isWater' => false,
                'points' => [
                    $corner(0, 0), $corner(8, 0),
                    // The shared wall, open from this side.
                    $corner(8, 4, false), $corner(0, 4),
                ],
            ],
            [
                'slug' => 'north',
                'name' => 'North',
                'floorHeight' => 0.0,
                'ceilingHeight' => 3.0,
                'floorTexture' => null,
                'ceilingTexture' => null,
                'wallTexture' => null,
                'isSky' => false,
                'isWater' => false,
                'points' => [
                    // And open from the other side too.
                    $corner(0, 4, false), $corner(8, 4),
                    $corner(8, 8), $corner(0, 8),
                ],
            ],
        ],
    ], $changes);
}

function saveDoorMap(array $map): TestResponse
{
    $level = Level::factory()->for(Game::factory())->create();

    return test()
        ->actingAs(User::factory()->create())
        ->putJson(route('levels.editor.update', $level), $map);
}

it('saves a door standing in a doorway', function (): void {
    saveDoorMap(aMapWithDoor())->assertRedirect()->assertSessionHasNoErrors();

    $door = LevelThing::where('slug', 'front-door')->firstOrFail();

    expect($door->is_door)->toBeTrue()
        ->and($door->swing)->toBe(DoorSwing::Swing)
        ->and($door->open_angle)->toEqual(90.0)
        ->and($door->open_seconds)->toEqual(0.4)
        ->and($door->is_open)->toBeFalse()
        ->and($door->opens_flag)->toBeNull();
});

it('round-trips how a door moves and how it is remembered', function (): void {
    saveDoorMap(aMapWithDoor([
        'swing' => 'slide',
        'openAngle' => 120.0,
        'openSeconds' => 1.25,
        'isOpen' => true,
        'opensFlag' => 'front-door-open',
    ]))->assertRedirect()->assertSessionHasNoErrors();

    $door = LevelThing::where('slug', 'front-door')->firstOrFail();

    expect($door->swing)->toBe(DoorSwing::Slide)
        ->and($door->open_angle)->toEqual(120.0)
        ->and($door->open_seconds)->toEqual(1.25)
        ->and($door->is_open)->toBeTrue()
        ->and($door->opens_flag)->toBe('front-door-open');
});

it('leaves a thing that is not a door alone', function (): void {
    // Every default is what a thing already placed was doing, so nothing
    // becomes a door by surprise — and a crate in the middle of a room is not
    // asked to stand in a doorway.
    saveDoorMap(aMapWithDoor([
        'slug' => 'crate',
        'kind' => 'prop',
        'isDoor' => false,
        'x' => 2.0,
        'z' => 2.0,
    ]))->assertRedirect()->assertSessionHasNoErrors();

    expect(LevelThing::where('slug', 'crate')->firstOrFail()->is_door)
        ->toBeFalse();
});

it('turns away a door standing in the middle of a room', function (): void {
    // The wall run moved and the door was left behind. Plausible in plan,
    // discovered by walking at it.
    saveDoorMap(aMapWithDoor(['x' => 2.0, 'z' => 1.0]))
        ->assertJsonValidationErrors(['things.0.isDoor']);
});

it('turns away a door inside a solid wall', function (): void {
    // The boundary is there, but it is shut from both sides. A door in it is a
    // door into brick.
    $map = aMapWithDoor();
    $map['sectors'][0]['points'][2]['blocks'] = true;
    $map['sectors'][1]['points'][0]['blocks'] = true;

    saveDoorMap($map)->assertJsonValidationErrors(['things.0.isDoor']);
});

it('turns away a door in a wall shut from only one side', function (): void {
    // Passability belongs to the boundary rather than to one room's idea of it.
    // Either side saying no is enough, and reading only the near side is the
    // mistake this pins.
    $map = aMapWithDoor();
    $map['sectors'][1]['points'][0]['blocks'] = true;

    saveDoorMap($map)->assertJsonValidationErrors(['things.0.isDoor']);
});

it('turns away a door on an outside wall', function (): void {
    // One room naming it is not a doorway: there is nothing on the other side
    // to walk into.
    saveDoorMap(aMapWithDoor(['x' => 4.0, 'z' => 0.0]))
        ->assertJsonValidationErrors(['things.0.isDoor']);
});

it('turns away a way of opening that does not exist', function (): void {
    saveDoorMap(aMapWithDoor(['swing' => 'dilate']))
        ->assertJsonValidationErrors(['things.0.swing']);
});

it('turns away an opening angle nobody could animate', function (): void {
    saveDoorMap(aMapWithDoor(['openAngle' => 400.0]))
        ->assertJsonValidationErrors(['things.0.openAngle']);
});
