<?php

use App\Enums\ActorBehaviour;
use App\Enums\ThingKind;
use App\Models\Level;
use App\Models\LevelSector;
use App\Models\LevelVertex;
use App\Models\User;
use App\Services\LevelAssets;
use App\Services\PersonStats;
use Database\Seeders\LifeSeeder;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia;

beforeEach(function (): void {
    $this->seed(LifeSeeder::class);
    $this->level = Level::query()->where('slug', 'tech-demo')->sole();
    $this->editor = User::factory()->create();
});

/**
 * A map the editor would send back: two rooms sharing one wall with a doorway in
 * it, someone wandering about, and a crate.
 *
 * @return array<string, mixed>
 */
function drawnMap(): array
{
    $corner = fn (float $x, float $z, bool $blocks = false): array => [
        'x' => $x,
        'z' => $z,
        'wallTexture' => null,
        'blocks' => $blocks,
        'isMirror' => false,
    ];

    return [
        'name' => 'Drawn',
        'description' => 'Two rooms and a way between them.',
        'playerSprite' => 'krystal',
        'spriteStyle' => 'realistic',
        'spawn' => ['x' => 1.0, 'z' => 1.0, 'angle' => 90],
        'ceilingHeight' => 3.0,
        'sky' => [
            'image' => 'sky-night',
            'variant' => 2,
        ],
        'things' => [
            [
                'slug' => 'krystal',
                'name' => 'Krystal',
                'description' => 'Krystal is wandering about.',
                'kind' => 'actor',
                'sprite' => 'krystal',
                'behaviour' => 'wander',
                'speed' => 1.1,
                'texture' => null,
                'x' => 1.0,
                'z' => 2.0,
                'elevation' => 0.0,
                'width' => 0.9,
                'depth' => 0.9,
                'height' => 1.7,
                'angle' => 0.0,
                'isSolid' => false,
            ],
            [
                'slug' => 'crate',
                'name' => 'Crate',
                'description' => 'A wooden crate.',
                'kind' => 'prop',
                'sprite' => null,
                'behaviour' => null,
                'speed' => 0.0,
                'texture' => 'oak-floor',
                'x' => 3.0,
                'z' => 3.0,
                'elevation' => 0.0,
                'width' => 0.6,
                'depth' => 0.6,
                'height' => 0.6,
                'angle' => 30.0,
                'isSolid' => true,
            ],
        ],
        'sectors' => [
            [
                'slug' => 'west',
                'name' => 'West',
                'floorHeight' => 0.0,
                'ceilingHeight' => 3.0,
                'floorTexture' => 'oak-floor',
                'ceilingTexture' => null,
                'wallTexture' => 'concrete-wall',
                'isSky' => false,
                'isWater' => false,
                'points' => [
                    $corner(0, 0),
                    $corner(4, 0, true),
                    $corner(4, 2),
                    $corner(4, 4),
                    $corner(0, 4),
                ],
            ],
            [
                'slug' => 'east',
                'name' => 'East',
                'floorHeight' => 0.3,
                'ceilingHeight' => 3.0,
                'floorTexture' => 'checker-floor',
                'ceilingTexture' => null,
                'wallTexture' => 'concrete-wall',
                'isSky' => true,
                'isWater' => false,
                'points' => [
                    $corner(4, 0),
                    $corner(8, 0),
                    $corner(8, 4),
                    $corner(4, 4),
                    $corner(4, 2),
                ],
            ],
        ],
    ];
}

it('keeps the editor behind a login', function (): void {
    $this->get(route('levels.editor', $this->level))
        ->assertRedirect(route('filament.admin.auth.login'));

    $this->put(route('levels.editor.update', $this->level), drawnMap())
        ->assertRedirect(route('filament.admin.auth.login'));
});

it('lets anyone who can sign in redraw a level, for now', function (): void {
    // There is no owner recorded for a game, so the policy says yes to every
    // signed-in user. Pinned here so that narrowing it is a deliberate change
    // with a failing test to change alongside it.
    expect($this->editor->can('update', $this->level))->toBeTrue();
});

it('turns away a save the policy will not have, without taking the page away', function (): void {
    Gate::before(fn (): bool => false);

    // Not 403, and deliberately not: `UpdateLevelMapRequest::failedAuthorization`
    // throws a validation failure instead, because a 403 page is the one answer
    // you must not give somebody holding unsaved work — the editor would
    // navigate and the draft would go with it. So the refusal comes back as
    // something to read, in the place the editor already listens for errors.
    $this->actingAs($this->editor)
        ->from(route('levels.editor', $this->level))
        ->put(route('levels.editor.update', $this->level), drawnMap())
        ->assertRedirect(route('levels.editor', $this->level))
        ->assertSessionHasErrors('save');

    expect($this->level->fresh()->sectors)->toHaveCount(3, 'The stored map is untouched.');
});

it('turns away the editor page itself when the policy says no', function (): void {
    Gate::before(fn (): bool => false);

    $this->actingAs($this->editor)
        ->get(route('levels.editor', $this->level))
        ->assertForbidden();
});

it('opens a level for editing, with the folder of textures to build from', function (): void {
    $this->actingAs($this->editor)
        ->get(route('levels.editor', $this->level))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('editor/level')
            ->where('level.slug', 'tech-demo')
            ->where('levelId', $this->level->id)
            ->where('game.slug', 'life')
            ->has('level.sectors', 3)
            ->has('assets.textures')
            ->has('assets.skies')
        );
});

it('lists only textures that are really there', function (): void {
    $this->actingAs($this->editor)
        ->get(route('levels.editor', $this->level))
        ->assertInertia(function (AssertableInertia $page): void {
            /** @var list<string> $textures */
            $textures = $page->toArray()['props']['assets']['textures'];

            expect($textures)->toContain('oak-floor')
                ->and($textures)->not->toContain('sky-day');

            foreach ($textures as $texture) {
                expect(public_path("sprites/textures/{$texture}.png"))->toBeFile();
            }
        });
});

it('saves a drawn map over the one that was there', function (): void {
    $this->actingAs($this->editor)
        ->put(route('levels.editor.update', $this->level), drawnMap())
        ->assertRedirect();

    $level = $this->level->fresh(['sectors.edges.vertex']);

    expect($level->name)->toBe('Drawn')
        ->and($level->spawn_angle)->toBe(90.0)
        ->and($level->sky_image)->toBe('sky-night')
        ->and($level->sky_variant)->toBe(2)
        ->and($level->sectors)->toHaveCount(2)
        ->and($level->sectors->pluck('slug')->all())->toBe(['west', 'east'])
        ->and($level->sectors->firstWhere('slug', 'east')->is_sky)->toBeTrue()
        ->and($level->sectors->firstWhere('slug', 'east')->floor_height)->toBe(0.3);
});

it('lets two rooms go on sharing the corners they were drawn on', function (): void {
    $this->actingAs($this->editor)
        ->put(route('levels.editor.update', $this->level), drawnMap())
        ->assertRedirect();

    $level = $this->level->fresh(['sectors.edges.vertex']);
    $sectors = $level->sectors->keyBy('slug');

    $sharedByBoth = $sectors['west']->edges
        ->pluck('vertex_id')
        ->intersect($sectors['east']->edges->pluck('vertex_id'));

    expect($sharedByBoth)->toHaveCount(3, 'The shared wall runs through three corners.');

    // And no corner is stored twice, or moving one would only move one room.
    $level->vertices->each(function (LevelVertex $vertex) use ($level): void {
        $sameSpot = $level->vertices->filter(
            fn (LevelVertex $other): bool => abs($other->x - $vertex->x) < 0.001
                && abs($other->z - $vertex->z) < 0.001
        );

        expect($sameSpot)->toHaveCount(1);
    });
});

it('remembers which walls were marked solid', function (): void {
    $this->actingAs($this->editor)
        ->put(route('levels.editor.update', $this->level), drawnMap())
        ->assertRedirect();

    $west = $this->level->fresh(['sectors.edges'])->sectors->firstWhere('slug', 'west');

    expect($west->edges->where('blocks', true))->toHaveCount(1)
        ->and($west->edges->firstWhere('blocks', true)->sort_order)->toBe(1);
});

it('saves the people and the furniture that were drawn with the map', function (): void {
    $this->actingAs($this->editor)
        ->put(route('levels.editor.update', $this->level), drawnMap())
        ->assertRedirect();

    $things = $this->level->fresh('things')->things->keyBy('slug');

    // What arrives replaces what was there, the same way the shape does.
    expect($things)->toHaveCount(2);

    expect($things['krystal']->kind)->toBe(ThingKind::Actor)
        ->and($things['krystal']->sprite)->toBe('krystal')
        ->and($things['krystal']->behaviour)->toBe(ActorBehaviour::Wander->value)
        ->and($things['krystal']->height)->toBe(1.7)
        ->and($things['krystal']->is_solid)->toBeFalse();

    expect($things['crate']->kind)->toBe(ThingKind::Prop)
        ->and($things['crate']->texture)->toBe('oak-floor')
        ->and($things['crate']->angle)->toBe(30.0)
        ->and($things['crate']->is_solid)->toBeTrue()
        ->and($things['crate']->sprite)->toBeNull();
});

it('hands the people back to the editor to draw with', function (): void {
    $this->actingAs($this->editor)
        ->put(route('levels.editor.update', $this->level), drawnMap());

    $this->actingAs($this->editor)
        ->get(route('levels.editor', $this->level))
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->has('level.things', 2)
            ->where('level.things.0.sprite', 'krystal')
            ->where('level.things.1.texture', 'oak-floor')
            ->has('assets.sprites')
        );
});

it('turns away a person with no sprite to be drawn from', function (): void {
    $map = drawnMap();
    $map['things'][0]['sprite'] = null;

    $this->actingAs($this->editor)
        ->put(route('levels.editor.update', $this->level), $map)
        ->assertSessionHasErrors('things.0.sprite');
});

it('turns away two things that are called the same', function (): void {
    $map = drawnMap();
    $map['things'][1]['slug'] = 'krystal';

    $this->actingAs($this->editor)
        ->put(route('levels.editor.update', $this->level), $map)
        ->assertSessionHasErrors('things.1.slug');
});

it('turns away a room with fewer than three corners', function (): void {
    $map = drawnMap();
    $map['sectors'][0]['points'] = array_slice($map['sectors'][0]['points'], 0, 2);

    $this->actingAs($this->editor)
        ->put(route('levels.editor.update', $this->level), $map)
        ->assertSessionHasErrors('sectors.0.points');

    expect($this->level->fresh()->sectors)->toHaveCount(3, 'The stored map is untouched.');
});

it('turns away a texture that is not in the folder', function (): void {
    $map = drawnMap();
    $map['sectors'][0]['floorTexture'] = 'not-a-texture';

    $this->actingAs($this->editor)
        ->put(route('levels.editor.update', $this->level), $map)
        ->assertSessionHasErrors('sectors.0.floorTexture');
});

it('turns away a sky that is not in the folder', function (): void {
    $map = drawnMap();
    $map['sky']['image'] = 'sky-eclipse';

    $this->actingAs($this->editor)
        ->put(route('levels.editor.update', $this->level), $map)
        ->assertSessionHasErrors('sky.image');
});

it('will not let a ceiling end up below its own floor', function (): void {
    $map = drawnMap();
    $map['sectors'][0]['floorHeight'] = 2.0;
    $map['sectors'][0]['ceilingHeight'] = 1.0;

    $this->actingAs($this->editor)
        ->put(route('levels.editor.update', $this->level), $map)
        ->assertRedirect();

    $west = $this->level->fresh(['sectors'])->sectors->firstWhere('slug', 'west');

    expect($west->ceiling_height)->toBeGreaterThanOrEqual($west->floor_height);
});

it('clears the sky when a level is saved without one', function (): void {
    $map = drawnMap();
    $map['sky'] = null;

    $this->actingAs($this->editor)
        ->put(route('levels.editor.update', $this->level), $map)
        ->assertRedirect();

    $level = $this->level->fresh();

    expect($level->sky_image)->toBeNull()
        ->and($level->sky_variant)->toBe(0);
});

it('leaves the retired horizon columns alone rather than wiping them', function (): void {
    // Paul had the parallax horizon layers taken out: they did not look good.
    // The columns and the art stayed, so the decision is reversible and the
    // levels that were given one have not quietly lost it — which means a save
    // must have no opinion about a horizon rather than nulling one.
    $this->level->update(['backdrop_theme' => 'hills', 'backdrop_layers' => [1, 2, 3]]);

    $this->actingAs($this->editor)
        ->put(route('levels.editor.update', $this->level), drawnMap())
        ->assertRedirect();

    $level = $this->level->fresh();

    expect($level->backdrop_theme)->toBe('hills')
        ->and($level->backdrop_layers)->toBe([1, 2, 3]);
});

it('offers every panorama as its own choice rather than a file and a cell', function (): void {
    $this->actingAs($this->editor)
        ->get(route('levels.editor', $this->level))
        ->assertInertia(function (AssertableInertia $page): void {
            /** @var list<array{value: string, image: string, variant: int, label: string}> $skies */
            $skies = $page->toArray()['props']['assets']['skies'];

            $assets = app(LevelAssets::class);
            $first = $assets->skies()[0];

            expect($skies)
                ->toHaveCount(count($assets->skies()) * LevelAssets::SKY_VARIANTS)
                ->and($skies[0])->toBe([
                    'value' => $first.':0',
                    'image' => $first,
                    'variant' => 0,
                    'label' => Str::headline(Str::after($first, 'sky-')).' 1',
                ])
                ->and(array_column($skies, 'label'))
                ->toContain('Day 4', 'Night 1', 'Sunset 4');

            foreach ($skies as $sky) {
                expect(public_path("sprites/bg/{$sky['image']}.png"))->toBeFile();
            }
        });
});

it('saves a map the game can be played from straight away', function (): void {
    $this->actingAs($this->editor)
        ->put(route('levels.editor.update', $this->level), drawnMap())
        ->assertRedirect();

    $this->get(route('games.show', $this->level->game))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('game/explore')
            ->has('level.sectors', 2)
            ->where('level.sectors.0.points.0.x', 0)
            ->where('level.sky.image', 'sky-night')
        );
});

it('does not touch other levels', function (): void {
    $other = Level::factory()->for($this->level->game)->create(['slug' => 'elsewhere']);
    LevelSector::factory()->for($other, 'level')->create(['slug' => 'keep-me']);

    $this->actingAs($this->editor)
        ->put(route('levels.editor.update', $this->level), drawnMap())
        ->assertRedirect();

    expect($other->fresh(['sectors'])->sectors->pluck('slug')->all())->toBe(['keep-me']);
});

it('saves a texture set on one wall only', function (): void {
    $map = drawnMap();
    // The wall of the west room that runs from (4,2) to (4,4).
    $map['sectors'][0]['points'][2]['wallTexture'] = 'red-brick-path';

    $this->actingAs(User::factory()->create())
        ->put(route('levels.editor.update', $this->level), $map)
        ->assertRedirect();

    $west = $this->level->fresh(['sectors.edges.vertex'])
        ->sectors
        ->firstWhere('slug', 'west');

    $painted = $west->edges->firstWhere('sort_order', 2);

    expect($painted->wall_texture)->toBe('red-brick-path')
        ->and($painted->vertex->x)->toBe(4.0)
        ->and($painted->vertex->z)->toBe(2.0);

    // And it stays that wall's business, not the room's or its neighbours'.
    expect($west->wall_texture)->toBe('concrete-wall')
        ->and($west->edges->where('sort_order', '!=', 2)->pluck('wall_texture')->filter())
        ->toBeEmpty();
});

it('hands a wall texture back to the editor to draw with', function (): void {
    $map = drawnMap();
    $map['sectors'][0]['points'][2]['wallTexture'] = 'red-brick-path';

    $this->actingAs(User::factory()->create())
        ->put(route('levels.editor.update', $this->level), $map);

    $this->actingAs(User::factory()->create())
        ->get(route('levels.editor', $this->level))
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('level.sectors.0.points.2.wallTexture', 'red-brick-path')
            ->where('level.sectors.0.points.0.wallTexture', null)
        );
});

it('keeps a texture on each side of a shared wall', function (): void {
    $map = drawnMap();

    // The wall the two rooms share: west's edge starting at (4,0), and east's
    // that runs back along the same pair of corners.
    $map['sectors'][0]['points'][1]['wallTexture'] = 'red-siding';
    $map['sectors'][0]['points'][1]['blocks'] = true;

    $east = $map['sectors'][1]['points'];
    $shared = collect($east)->search(
        fn (array $point, int $index): bool => $point['x'] === 4.0
            && $east[($index + 1) % count($east)]['x'] === 4.0
    );

    expect($shared)->not->toBeFalse('The two rooms should share a wall.');

    $map['sectors'][1]['points'][$shared]['wallTexture'] = 'subway-tile-wall';
    $map['sectors'][1]['points'][$shared]['blocks'] = true;

    $this->actingAs(User::factory()->create())
        ->put(route('levels.editor.update', $this->level), $map)
        ->assertRedirect();

    $sectors = $this->level->fresh(['sectors.edges.vertex'])->sectors->keyBy('slug');

    expect($sectors['west']->edges->firstWhere('sort_order', 1)->wall_texture)
        ->toBe('red-siding')
        ->and($sectors['east']->edges->firstWhere('sort_order', $shared)->wall_texture)
        ->toBe('subway-tile-wall');
});

it('remembers who the player is', function (): void {
    $this->actingAs($this->editor)
        ->put(route('levels.editor.update', $this->level), drawnMap())
        ->assertRedirect();

    expect($this->level->fresh()->player_sprite)->toBe('krystal');

    $this->actingAs($this->editor)
        ->get(route('levels.editor', $this->level))
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('level.playerSprite', 'krystal')
        );
});

it('turns away somebody there are no sheets for', function (): void {
    $map = drawnMap();
    $map['playerSprite'] = 'nobody';

    $this->actingAs($this->editor)
        ->put(route('levels.editor.update', $this->level), $map)
        ->assertSessionHasErrors('playerSprite');
});

/**
 * A complete SPECIAL block, as the Inspector sends one.
 *
 * @return array<string, int>
 */
function drawnStats(int $each = 5): array
{
    return array_fill_keys(PersonStats::ATTRIBUTES, $each);
}

it('keeps a person their own stats when they are given some', function (): void {
    $map = drawnMap();
    $map['things'][0]['stats'] = [...drawnStats(), 'luck' => 9, 'strength' => 2];

    $this->actingAs($this->editor)
        ->put(route('levels.editor.update', $this->level), $map)
        ->assertRedirect();

    $krystal = $this->level->fresh('things')->things->firstWhere('slug', 'krystal');

    // `toEqual` rather than `toBe`, and the difference is a database. MySQL's
    // `json` column normalises the order of an object's keys on the way in;
    // SQLite keeps the text it was handed. Neither is wrong — key order is not
    // part of what a JSON object means — so the assertion must not be, either.
    // This is the one place the gap between testing on SQLite and running on
    // MySQL has actually shown, and it showed the first time the suite was run
    // against MySQL rather than in production.
    expect($krystal->stats)->toEqual([...drawnStats(), 'luck' => 9, 'strength' => 2])
        ->and($krystal->stats()['luck'])->toBe(9);

    $this->actingAs($this->editor)
        ->get(route('levels.editor', $this->level))
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('level.things.0.stats.luck', 9)
            ->where('level.things.0.inheritedStats.luck', PersonStats::STARTING['krystal']['luck'])
        );
});

it('leaves a person with nothing of their own when none were sent', function (): void {
    $this->actingAs($this->editor)
        ->put(route('levels.editor.update', $this->level), drawnMap())
        ->assertRedirect();

    $krystal = $this->level->fresh('things')->things->firstWhere('slug', 'krystal');

    expect($krystal->stats)->toBeNull()
        ->and($krystal->stats())->toBe(PersonStats::STARTING['krystal']);

    $this->actingAs($this->editor)
        ->get(route('levels.editor', $this->level))
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('level.things.0.stats', null)
        );
});

it('turns away half a stat block', function (): void {
    $map = drawnMap();
    $stats = drawnStats();
    unset($stats['luck']);
    $map['things'][0]['stats'] = $stats;

    $this->actingAs($this->editor)
        ->put(route('levels.editor.update', $this->level), $map)
        ->assertSessionHasErrors('things.0.stats');
});

it('turns away a stat nobody has heard of', function (): void {
    $map = drawnMap();
    $stats = drawnStats();
    unset($stats['luck']);
    $map['things'][0]['stats'] = [...$stats, 'lukc' => 5];

    $this->actingAs($this->editor)
        ->put(route('levels.editor.update', $this->level), $map)
        ->assertSessionHasErrors('things.0.stats');
});

it('turns away a stat past the top of the range', function (): void {
    $map = drawnMap();
    $map['things'][0]['stats'] = [...drawnStats(), 'strength' => 11];

    $this->actingAs($this->editor)
        ->put(route('levels.editor.update', $this->level), $map)
        ->assertSessionHasErrors('things.0.stats.strength');
});

it('turns away a crate with a personality', function (): void {
    $map = drawnMap();
    $map['things'][1]['stats'] = drawnStats();

    $this->actingAs($this->editor)
        ->put(route('levels.editor.update', $this->level), $map)
        ->assertSessionHasErrors('things.1.stats');
});

it('saves the lines drawn between things, and finds them again on the way out', function (): void {
    $map = drawnMap();
    $map['lines'] = [
        ['from' => 'krystal', 'to' => 'crate'],
        ['from' => 'crate', 'to' => 'krystal'],
    ];

    $this->actingAs($this->editor)
        ->put(route('levels.editor.update', $this->level), $map)
        ->assertSessionHasNoErrors();

    $level = $this->level->fresh(['actionLines.from', 'actionLines.to']);

    expect($level->actionLines
        ->map(fn ($line): array => [$line->from->slug, $line->to->slug])
        ->all()
    )->toBe([['krystal', 'crate'], ['crate', 'krystal']]);

    // And back out the way the editor reads it: by slug, because that is what
    // the drawing is made of. Ids never leave the database.
    $this->actingAs($this->editor)
        ->get(route('levels.editor', $this->level))
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('level.lines', [
                ['from' => 'krystal', 'to' => 'crate'],
                ['from' => 'crate', 'to' => 'krystal'],
            ])
        );
});

it('cuts a line by saving a map without it', function (): void {
    $map = drawnMap();
    $map['lines'] = [['from' => 'krystal', 'to' => 'crate']];

    $this->actingAs($this->editor)
        ->put(route('levels.editor.update', $this->level), $map);

    $map['lines'] = [];

    $this->actingAs($this->editor)
        ->put(route('levels.editor.update', $this->level), $map)
        ->assertSessionHasNoErrors();

    expect($this->level->fresh()->actionLines)->toHaveCount(0);
});

it('turns away a line to a thing the map does not carry', function (): void {
    $map = drawnMap();
    $map['lines'] = [['from' => 'krystal', 'to' => 'ghost']];

    $this->actingAs($this->editor)
        ->put(route('levels.editor.update', $this->level), $map)
        ->assertSessionHasErrors('lines.0.to');
});

it('saves the art style the level is drawn in', function (): void {
    $map = drawnMap();
    $map['spriteStyle'] = 'illustrated';

    $this->actingAs($this->editor)
        ->put(route('levels.editor.update', $this->level), $map)
        ->assertRedirect();

    expect($this->level->fresh()->sprite_style)->toBe('illustrated');
});

it('turns away an art style that has no folder', function (): void {
    $map = drawnMap();
    $map['spriteStyle'] = 'nonesuch';

    $this->actingAs($this->editor)
        ->put(route('levels.editor.update', $this->level), $map)
        ->assertSessionHasErrors('spriteStyle');
});

it('offers the styles a level can be drawn in, more than one', function (): void {
    $this->actingAs($this->editor)
        ->get(route('levels.editor', $this->level))
        ->assertInertia(function (AssertableInertia $page): void {
            /** @var list<string> $styles */
            $styles = $page->toArray()['props']['assets']['styles'];

            expect($styles)->toContain('realistic')
                ->and($styles)->toContain('illustrated');
        });
});

it('lets a character be set extremely fast', function (): void {
    $map = drawnMap();
    $map['things'][0]['speed'] = 60.0;

    $this->actingAs($this->editor)
        ->put(route('levels.editor.update', $this->level), $map)
        ->assertRedirect();

    expect($this->level->fresh()->things->firstWhere('slug', 'krystal')->speed)->toBe(60.0);
});

it('still refuses a speed past the cap', function (): void {
    $map = drawnMap();
    $map['things'][0]['speed'] = 71.0;

    $this->actingAs($this->editor)
        ->put(route('levels.editor.update', $this->level), $map)
        ->assertSessionHasErrors('things.0.speed');
});
