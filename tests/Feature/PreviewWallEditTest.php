<?php

use App\Models\Game;
use App\Models\Level;
use App\Models\LevelSector;
use App\Models\User;
use Illuminate\Support\Facades\Gate;
use Illuminate\Testing\TestResponse;

/**
 * Changing one wall while standing in front of it.
 *
 * The point of the feature is the things you can only judge from inside the
 * room — a mirror most of all — so the edit goes straight to the level rather
 * than into a save file, with the same authority as the map editor.
 *
 * The part worth testing hardest is that it changes **the wall that was named
 * and no other**. A wall is named by its room and its position in that room's
 * point list, and every editor operation that rewrites the list moves the
 * index: splitting, welding, carving. The pair survives a save and not an edit,
 * so the client sends the corners it saw and this refuses when they no longer
 * match. Without that, somebody carving a room while another person is standing
 * in it silently retextures whichever wall inherited the number.
 */
beforeEach(function (): void {
    $this->level = Level::factory()->for(Game::factory())->create();

    $this->sector = LevelSector::factory()->for($this->level, 'level')->create([
        'slug' => 'hall',
        'name' => 'Hall',
    ]);

    // A square room, wound the way the editor draws them.
    foreach ([[0, 0], [8, 0], [8, 4], [0, 4]] as $index => [$x, $z]) {
        $this->sector->edges()->create([
            'vertex_id' => $this->level->vertices()->create(['x' => $x, 'z' => $z])->id,
            'sort_order' => $index,
            'wall_texture' => null,
            'blocks' => true,
            'is_mirror' => false,
            'is_sky' => false,
            'portal_link' => null,
        ]);
    }

    $this->user = User::factory()->create();
});

/**
 * @param  array<string, mixed>  $changes
 */
function editWall(array $changes): TestResponse
{
    return test()
        ->actingAs(test()->user)
        ->patchJson(route('levels.wall.update', test()->level), array_merge([
            'sector' => 'hall',
            'index' => 0,
        ], $changes));
}

it('will not let somebody who is only playing change a wall', function (): void {
    // The edit writes to the level everybody sees, so it needs the same
    // authority as the editor. Playing is read-only.
    $this->patchJson(route('levels.wall.update', $this->level), [
        'sector' => 'hall',
        'index' => 0,
        'isMirror' => true,
    ])->assertUnauthorized();
});

it('refuses when the gate says no, even to somebody signed in', function (): void {
    Gate::before(fn (): bool => false);

    editWall(['isMirror' => true])->assertForbidden();
});

it('makes a wall a mirror', function (): void {
    // The change the whole feature is for: you cannot tell whether a room wants
    // a mirror from a floor plan.
    editWall(['isMirror' => true])->assertOk();

    expect($this->sector->edges()->where('sort_order', 0)->sole()->is_mirror)
        ->toBeTrue();
});

it('changes only what it was asked to change', function (): void {
    // A request saying nothing about `blocks` must leave `blocks` alone rather
    // than setting it false — which is what makes the difference between
    // `sometimes` and `nullable` matter here.
    editWall(['wallTexture' => 'oak-floor'])->assertOk();

    $edge = $this->sector->edges()->where('sort_order', 0)->sole();

    expect($edge->wall_texture)->toBe('oak-floor')
        ->and($edge->blocks)->toBeTrue()
        ->and($edge->is_mirror)->toBeFalse()
        ->and($edge->is_sky)->toBeFalse();
});

it('leaves every other wall alone', function (): void {
    // The one thing a whole-level rewrite would make hard to be sure of.
    $before = $this->sector->edges()->where('sort_order', '!=', 0)->pluck('id');

    editWall(['isMirror' => true, 'wallTexture' => 'oak-floor'])->assertOk();

    foreach ($this->sector->edges()->where('sort_order', '!=', 0)->get() as $edge) {
        expect($edge->is_mirror)->toBeFalse()
            ->and($edge->wall_texture)->toBeNull();
    }

    // And the rows are the same rows. A save from the editor deletes and
    // recreates everything; this must not, or every id in the level churns to
    // set one boolean.
    expect($this->sector->edges()->where('sort_order', '!=', 0)->pluck('id')->all())
        ->toBe($before->all());
});

it('keeps the wall it edited, rather than rebuilding it', function (): void {
    $id = $this->sector->edges()->where('sort_order', 0)->sole()->id;

    editWall(['blocks' => false])->assertOk();

    expect($this->sector->edges()->where('sort_order', 0)->sole()->id)->toBe($id);
});

it('turns down a texture that is not in the folder', function (): void {
    editWall(['wallTexture' => 'not-a-real-texture'])
        ->assertJsonValidationErrors(['wallTexture']);
});

it('turns down a request that asks for nothing', function (): void {
    editWall([])->assertJsonValidationErrors(['wallTexture']);
});

it('says so when the room has gone', function (): void {
    editWall(['sector' => 'no-such-room', 'isMirror' => true])
        ->assertNotFound();
});

it('says so when the wall has gone', function (): void {
    editWall(['index' => 99, 'isMirror' => true])
        ->assertNotFound();
});

it('changes the wall when the corners still match', function (): void {
    // Wall 0 runs (0,0) to (8,0).
    editWall([
        'isMirror' => true,
        'expect' => [
            'from' => ['x' => 0, 'z' => 0],
            'to' => ['x' => 8, 'z' => 0],
        ],
    ])->assertOk();

    expect($this->sector->edges()->where('sort_order', 0)->sole()->is_mirror)
        ->toBeTrue();
});

it('refuses when the wall at that number is no longer the wall they saw', function (): void {
    // What a carve does: the room is redrawn, the indexes shift, and index 0 is
    // now somewhere else entirely. Changing it anyway would retexture a wall
    // nobody was looking at, and nothing would say so.
    editWall([
        'isMirror' => true,
        'expect' => [
            'from' => ['x' => 0, 'z' => 0],
            'to' => ['x' => 8, 'z' => 4],
        ],
    ])->assertStatus(409);

    expect($this->sector->edges()->where('sort_order', 0)->sole()->is_mirror)
        ->toBeFalse();
});

it('still works for a client that cannot say what it saw', function (): void {
    // The check is a safeguard, not a requirement. Editing without it is better
    // than not editing.
    editWall(['isSky' => true])->assertOk();

    expect($this->sector->edges()->where('sort_order', 0)->sole()->is_sky)
        ->toBeTrue();
});

it('hands back the wall as it now stands', function (): void {
    // So the client can draw the result rather than guessing at it, and find
    // out immediately if it disagrees.
    editWall(['isMirror' => true, 'blocks' => false])
        ->assertOk()
        ->assertJson([
            'sector' => 'hall',
            'index' => 0,
            'isMirror' => true,
            'blocks' => false,
        ]);
});
