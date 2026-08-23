<?php

use App\Enums\ThingRender;
use App\Enums\ThingUvMode;
use App\Models\Game;
use App\Models\Level;
use App\Models\LevelThing;
use App\Models\User;
use App\Services\LevelAssets;
use Illuminate\Support\Facades\File;
use Illuminate\Testing\TestResponse;

/**
 * How a thing is drawn, as far as the database and the wire are concerned.
 *
 * A thing has always been a box with a texture tiled over it, which is right
 * for furniture and wrong for anything with a silhouette. These columns are the
 * authoring half of fixing that; the engine half reads them.
 *
 * Every default is what a row already in the table was doing implicitly, so the
 * thing worth pinning hardest is that a payload which says nothing about how a
 * thing is drawn still saves, and still describes the same box it did before.
 */

/**
 * @return array<string, mixed>
 */
function aMapWithThing(array $changes = []): array
{
    return [
        'name' => 'Drawn',
        'description' => 'A level drawn in the editor.',
        'spawn' => ['x' => 1.0, 'z' => 1.0, 'angle' => 0.0],
        'playerSprite' => 'paul',
        'ceilingHeight' => 3.0,
        'sky' => null,
        'things' => [array_merge([
            'slug' => 'pot-plant',
            'name' => 'Pot plant',
            'description' => 'It stands in the corner.',
            'kind' => 'prop',
            'sprite' => null,
            'behaviour' => null,
            'speed' => 0.0,
            'texture' => null,
            'x' => 2.0,
            'z' => 2.0,
            'elevation' => 0.0,
            'width' => 0.6,
            'depth' => 0.6,
            'height' => 1.2,
            'angle' => 0.0,
            'isSolid' => false,
        ], $changes)],
        'sectors' => [[
            'slug' => 'room',
            'name' => 'Room',
            'floorHeight' => 0.0,
            'ceilingHeight' => 3.0,
            'floorTexture' => null,
            'ceilingTexture' => null,
            'wallTexture' => null,
            'isSky' => false,
            'isWater' => false,
            'points' => [
                ['x' => 0.0, 'z' => 0.0, 'blocks' => true, 'wallTexture' => null, 'isMirror' => false, 'isSky' => false, 'portalLink' => null],
                ['x' => 6.0, 'z' => 0.0, 'blocks' => true, 'wallTexture' => null, 'isMirror' => false, 'isSky' => false, 'portalLink' => null],
                ['x' => 6.0, 'z' => 6.0, 'blocks' => true, 'wallTexture' => null, 'isMirror' => false, 'isSky' => false, 'portalLink' => null],
                ['x' => 0.0, 'z' => 6.0, 'blocks' => true, 'wallTexture' => null, 'isMirror' => false, 'isSky' => false, 'portalLink' => null],
            ],
        ]],
    ];
}

function saveMap(array $map): TestResponse
{
    $level = Level::factory()->for(Game::factory())->create();

    return test()
        ->actingAs(User::factory()->create())
        ->putJson(route('levels.editor.update', $level), $map);
}

it('draws a thing the way it always did when the save says nothing about it', function (): void {
    // The whole point of the defaults. Every level authored before these
    // columns existed goes on being a tiled box, and every save from a client
    // that has not been told about them still works.
    // A save redirects back to the editor; 302 is what success looks like.
    saveMap(aMapWithThing())->assertRedirect()->assertSessionHasNoErrors();

    $thing = LevelThing::where('slug', 'pot-plant')->firstOrFail();

    expect($thing->render)->toBe(ThingRender::Box)
        ->and($thing->uv_mode)->toBe(ThingUvMode::Tile)
        ->and($thing->plane_count)->toEqual(2)
        ->and($thing->texture_alt)->toBeNull()
        ->and($thing->alt_flag)->toBeNull()
        ->and($thing->animation_frames)->toEqual(1)
        ->and($thing->animation_fps)->toEqual(8.0);
});

it('round-trips how a thing is drawn', function (): void {
    saveMap(aMapWithThing([
        'render' => 'cross',
        'planeCount' => 3,
        'uvMode' => 'fit',
        'animationFrames' => 4,
        'animationFps' => 12.0,
    ]))->assertRedirect()->assertSessionHasNoErrors();

    $thing = LevelThing::where('slug', 'pot-plant')->firstOrFail();

    expect($thing->render)->toBe(ThingRender::Cross)
        ->and($thing->plane_count)->toEqual(3)
        ->and($thing->uv_mode)->toBe(ThingUvMode::Fit)
        ->and($thing->animation_frames)->toEqual(4)
        ->and($thing->animation_fps)->toEqual(12.0);
});

it('round-trips a flat thing, which keeps its angle and nothing else', function (): void {
    saveMap(aMapWithThing(['render' => 'flat', 'angle' => 30.0]))
        ->assertRedirect()->assertSessionHasNoErrors();

    $thing = LevelThing::where('slug', 'pot-plant')->firstOrFail();

    // The angle is the whole of what a flat thing is authored with. `box`,
    // `billboard` and `cross` left the same gap between them — none of them is
    // one quad at a fixed angle, which is what a window, a picture, a sign or a
    // door is — and closing it needed no column, because a thing has carried an
    // angle since before any of them existed.
    expect($thing->render)->toBe(ThingRender::Flat)
        ->and($thing->angle)->toEqual(30.0);
});

it('turns away a star with a number of planes nobody draws', function (): void {
    // Two quads or three. One is a billboard that forgot to turn, and four is
    // twice the fill rate for a silhouette nobody can tell from three.
    saveMap(aMapWithThing(['render' => 'cross', 'planeCount' => 4]))
        ->assertJsonValidationErrors(['things.0.planeCount']);
});

it('turns away a way of drawing that does not exist', function (): void {
    saveMap(aMapWithThing(['render' => 'hologram']))
        ->assertJsonValidationErrors(['things.0.render']);
});

it('turns away an alternate texture with no flag to show it, and the other way round', function (): void {
    // Either alone is silent at render time: a second texture nothing can reach,
    // or a flag naming a texture that is not there.
    saveMap(aMapWithThing(['altFlag' => 'lamp-on']))
        ->assertJsonValidationErrors(['things.0.textureAlt']);
});

it('turns away a prop texture that is not in the folder', function (): void {
    saveMap(aMapWithThing(['textureAlt' => 'not-a-real-prop', 'altFlag' => 'lamp-on']))
        ->assertJsonValidationErrors(['things.0.textureAlt']);
});

it('lists the prop art apart from the tiling textures', function (): void {
    $assets = app(LevelAssets::class);

    // They differ in kind — a surface texture is opaque, square and seamless; a
    // prop carries alpha and never repeats — and mixing them puts pot plants in
    // the wall-texture dropdown.
    expect($assets->props())->toBeArray()
        ->and(array_intersect($assets->props(), $assets->textures()))->toBe([]);
});

it('lets a thing be drawn from prop art as well as from a tiling texture', function (): void {
    // A billboard or a cross needs cutout art, which lives in the props folder,
    // so `texture` accepts either kind of picture. Which one is right depends on
    // how the thing is drawn rather than on what it is, and the editor's picker
    // offers the matching list.
    //
    // Written against a real file because LevelAssets reads the folder. There
    // is no prop art in the repo yet — it is coming from outside — so this
    // makes its own and takes it away again.
    $folder = public_path('sprites/props');
    $file = $folder.'/test-fern.png';

    File::ensureDirectoryExists($folder);
    File::put($file, (string) base64_decode(
        'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg=='
    ));

    try {
        saveMap(aMapWithThing([
            'render' => 'cross',
            'texture' => 'test-fern',
        ]))->assertRedirect()->assertSessionHasNoErrors();

        expect(LevelThing::where('slug', 'pot-plant')->firstOrFail()->texture)
            ->toBe('test-fern');
    } finally {
        File::delete($file);
    }
});
