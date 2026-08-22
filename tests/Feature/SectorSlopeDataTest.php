<?php

use App\Models\Game;
use App\Models\Level;
use App\Models\LevelSector;
use App\Models\LevelSectorEdge;
use App\Models\LevelVertex;
use App\Models\User;
use Illuminate\Testing\TestResponse;

/**
 * Floors and ceilings that are not level, as far as the server is concerned.
 *
 * The thing worth pinning hardest is the meaning of `floor_height`, which this
 * changes: it stops meaning "how high this floor is" and starts meaning "how
 * high this floor is along its hinge wall". That is what lets two rooms line up
 * for free — hinge them on the wall they share, give them the same base height,
 * and they meet flush there while each rises into its own room, because the
 * inward normal points opposite ways for the two sides.
 *
 * The PHP here is one half of a pair; `engine/sectors.ts` is the other, and the
 * two have to agree or the server will accept a room the engine draws
 * differently. Same established cost as `LevelAssets::HEIGHTS`.
 */

/**
 * A square room, four metres on a side, hinged as asked.
 *
 * @param  array<string, mixed>  $slopes
 */
function aSquareRoom(array $slopes = []): LevelSector
{
    $sector = new LevelSector(array_merge([
        'floor_height' => 0.0,
        'ceiling_height' => 3.0,
        'floor_slope' => 0.0,
        'floor_slope_edge' => null,
        'ceiling_slope' => 0.0,
        'ceiling_slope_edge' => null,
    ], $slopes));

    // Wound anticlockwise in x/z, which is what the editor draws.
    $corners = [[0.0, 0.0], [4.0, 0.0], [4.0, 4.0], [0.0, 4.0]];

    $sector->setRelation('edges', collect($corners)->map(
        fn (array $corner): LevelSectorEdge => tap(
            new LevelSectorEdge,
            fn (LevelSectorEdge $edge) => $edge->setRelation(
                'vertex',
                new LevelVertex(['x' => $corner[0], 'z' => $corner[1]]),
            )
        )
    ));

    return $sector;
}

it('leaves a flat floor alone wherever you stand on it', function (): void {
    $room = aSquareRoom();

    expect($room->floorAt(0, 0))->toEqual(0.0)
        ->and($room->floorAt(2, 2))->toEqual(0.0)
        ->and($room->floorAt(4, 4))->toEqual(0.0)
        ->and($room->ceilingAt(2, 2))->toEqual(3.0);
});

it('rises into the room from its hinge wall, not out of it', function (): void {
    // Wall 0 runs (0,0) to (4,0); the room is on the +z side of it.
    $room = aSquareRoom(['floor_slope' => 0.5, 'floor_slope_edge' => 0]);

    // Along the hinge itself the floor is exactly its base height. That is the
    // whole point of the convention.
    expect($room->floorAt(0, 0))->toEqual(0.0)
        ->and($room->floorAt(4, 0))->toEqual(0.0);

    // And it climbs going into the room, not away from it. Getting this
    // backwards is the obvious failure and it looks plausible on screen.
    expect($room->floorAt(2, 1))->toEqual(0.5)
        ->and($room->floorAt(2, 4))->toEqual(2.0);
});

it('lets two rooms meet flush along the wall they share', function (): void {
    // The same wall from each side, wound so each room's inward normal points
    // into itself. Same base height, same rise: they agree along the wall and
    // part company going into their own rooms.
    $south = aSquareRoom(['floor_slope' => 0.5, 'floor_slope_edge' => 0]);

    $north = new LevelSector([
        'floor_height' => 0.0,
        'ceiling_height' => 3.0,
        'floor_slope' => 0.5,
        'floor_slope_edge' => 0,
    ]);

    $corners = [[4.0, 0.0], [0.0, 0.0], [0.0, -4.0], [4.0, -4.0]];

    $north->setRelation('edges', collect($corners)->map(
        fn (array $corner): LevelSectorEdge => tap(
            new LevelSectorEdge,
            fn (LevelSectorEdge $edge) => $edge->setRelation(
                'vertex',
                new LevelVertex(['x' => $corner[0], 'z' => $corner[1]]),
            )
        )
    ));

    foreach ([[0.0, 0.0], [2.0, 0.0], [4.0, 0.0]] as [$x, $z]) {
        expect(abs($south->floorAt($x, $z) - $north->floorAt($x, $z)))
            ->toBeLessThan(0.001);
    }

    // Each rises into its own room, so a step away from the shared wall they
    // disagree — which is what makes it a slope rather than one big plane.
    expect($south->floorAt(2, 1))->toEqual(0.5)
        ->and($north->floorAt(2, -1))->toEqual(0.5);
});

it('measures headroom at its shallowest, not at the base', function (): void {
    // A floor climbing towards a flat ceiling: the gap is smallest in the far
    // corner, and that is the number that decides whether anybody fits.
    $room = aSquareRoom(['floor_slope' => 0.5, 'floor_slope_edge' => 0]);

    expect($room->headroom())->toEqual(1.0)
        ->and($room->headroom(2, 0))->toEqual(3.0)
        ->and($room->headroom(2, 4))->toEqual(1.0);
});

it('ignores a hinge that names a wall the room has not got', function (): void {
    // Rather than reading past the end of the list. A save cannot get here —
    // validation refuses it — but a row already in the table could, if a carve
    // ever shortened the room without clearing the hinge.
    $room = aSquareRoom(['floor_slope' => 0.5, 'floor_slope_edge' => 9]);

    expect($room->floorAt(2, 2))->toEqual(0.0);
});

/**
 * @return array<string, mixed>
 */
function aSlopedMap(array $sectorChanges = []): array
{
    return [
        'name' => 'Sloped',
        'description' => 'A room on a slant.',
        'spawn' => ['x' => 1.0, 'z' => 1.0, 'angle' => 0.0],
        'playerSprite' => 'paul',
        'ceilingHeight' => 3.0,
        'sky' => null,
        'things' => [],
        'sectors' => [array_merge([
            'slug' => 'ramp',
            'name' => 'Ramp',
            'floorHeight' => 0.0,
            'ceilingHeight' => 3.0,
            'floorTexture' => null,
            'ceilingTexture' => null,
            'wallTexture' => null,
            'isSky' => false,
            'isWater' => false,
            'points' => [
                ['x' => 0.0, 'z' => 0.0, 'blocks' => true, 'wallTexture' => null, 'isMirror' => false, 'isSky' => false, 'portalLink' => null],
                ['x' => 4.0, 'z' => 0.0, 'blocks' => true, 'wallTexture' => null, 'isMirror' => false, 'isSky' => false, 'portalLink' => null],
                ['x' => 4.0, 'z' => 4.0, 'blocks' => true, 'wallTexture' => null, 'isMirror' => false, 'isSky' => false, 'portalLink' => null],
                ['x' => 0.0, 'z' => 4.0, 'blocks' => true, 'wallTexture' => null, 'isMirror' => false, 'isSky' => false, 'portalLink' => null],
            ],
        ], $sectorChanges)],
    ];
}

function saveSloped(array $map): TestResponse
{
    $level = Level::factory()->for(Game::factory())->create();

    return test()
        ->actingAs(User::factory()->create())
        ->putJson(route('levels.editor.update', $level), $map);
}

it('round-trips a slope and its hinge', function (): void {
    saveSloped(aSlopedMap([
        'floorSlope' => 0.5,
        'floorSlopeEdge' => 0,
        'ceilingSlope' => 0.25,
        'ceilingSlopeEdge' => 2,
    ]))->assertRedirect()->assertSessionHasNoErrors();

    $sector = LevelSector::where('slug', 'ramp')->firstOrFail();

    expect($sector->floor_slope)->toEqual(0.5)
        ->and($sector->floor_slope_edge)->toEqual(0)
        ->and($sector->ceiling_slope)->toEqual(0.25)
        ->and($sector->ceiling_slope_edge)->toEqual(2);
});

it('saves a room that says nothing about slopes, and leaves it flat', function (): void {
    saveSloped(aSlopedMap())->assertRedirect()->assertSessionHasNoErrors();

    $sector = LevelSector::where('slug', 'ramp')->firstOrFail();

    expect($sector->floor_slope)->toEqual(0.0)
        ->and($sector->floor_slope_edge)->toBeNull()
        ->and($sector->ceiling_slope)->toEqual(0.0);
});

it('turns away a hinge on a wall the room has not got', function (): void {
    saveSloped(aSlopedMap(['floorSlope' => 0.5, 'floorSlopeEdge' => 7]))
        ->assertJsonValidationErrors(['sectors.0.floorSlopeEdge']);
});

it('turns away a slope with no wall to hinge on', function (): void {
    saveSloped(aSlopedMap(['floorSlope' => 0.5, 'floorSlopeEdge' => null]))
        ->assertJsonValidationErrors(['sectors.0.floorSlopeEdge']);
});

it('turns away a slope that puts the ceiling under the floor in a corner', function (): void {
    // Four metres of room at a rise of one is four metres of climb, into a
    // ceiling three metres up. The flat clamp cannot fix this by raising the
    // ceiling — there is no single number to raise — so it is refused.
    saveSloped(aSlopedMap(['floorSlope' => 1.0, 'floorSlopeEdge' => 0]))
        ->assertJsonValidationErrors(['sectors.0.ceilingSlope']);
});
