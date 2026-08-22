<?php

use App\Models\Game;
use App\Models\Interaction;
use App\Models\Item;
use App\Models\Level;
use App\Models\LevelThing;
use App\Models\User;
use Database\Seeders\LifeSeeder;
use Inertia\Testing\AssertableInertia;

/**
 * Authoring interactions in the map editor.
 *
 * They are saved with the map, the same way the people and the furniture are:
 * a level's things are rebuilt wholesale on every save, so anything hung on one
 * has to arrive with it or it is gone. What that buys is one screen — you place
 * a thing and say what it does without leaving the floor plan.
 */
beforeEach(function (): void {
    $this->seed(LifeSeeder::class);

    $this->game = Game::query()->where('slug', 'life')->sole();
    $this->level = Level::query()->where('slug', 'tech-demo')->sole();
    $this->editor = User::factory()->create();

    $this->key = Item::query()->create([
        'game_id' => $this->game->id,
        'slug' => 'shed-key',
        'name' => 'shed key',
        'description' => 'A small key.',
    ]);
});

/**
 * A map with one thing on it, carrying whatever interactions are handed in.
 *
 * @param  array<int, array<string, mixed>>  $interactions
 * @return array<string, mixed>
 */
function mapWithInteractions(array $interactions): array
{
    $corner = fn (float $x, float $z): array => [
        'x' => $x,
        'z' => $z,
        'wallTexture' => null,
        'blocks' => false,
        'isMirror' => false,
        'isSky' => false,
        'portalLink' => null,
    ];

    return [
        'name' => 'Drawn',
        'description' => 'One room and one pot.',
        'playerSprite' => 'paul',
        'spawn' => ['x' => 1.0, 'z' => 1.0, 'angle' => 0],
        'ceilingHeight' => 3.0,
        'sky' => null,
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
            'points' => [$corner(0, 0), $corner(4, 0), $corner(4, 4), $corner(0, 4)],
        ]],
        'things' => [[
            'slug' => 'flower-pot',
            'name' => 'Flower pot',
            'description' => 'A cracked pot.',
            'kind' => 'prop',
            'sprite' => null,
            'behaviour' => null,
            'speed' => 0.0,
            'texture' => null,
            'x' => 2.0,
            'z' => 2.0,
            'elevation' => 0.0,
            'width' => 0.4,
            'depth' => 0.4,
            'height' => 0.4,
            'angle' => 0.0,
            'isSolid' => true,
            'interactions' => $interactions,
        ]],
    ];
}

it('saves what a thing answers to along with the thing', function (): void {
    $this->actingAs($this->editor)
        ->put(route('levels.editor.update', $this->level), mapWithInteractions([
            [
                'verb' => 'use',
                'response' => 'You lift the pot and find a key.',
                'priority' => 5,
                'requiredItem' => null,
                'conditions' => [
                    ['type' => 'flag_is_not', 'subject' => 'pot-lifted', 'value' => 'yes'],
                ],
                'effects' => [
                    ['type' => 'give_item', 'subject' => 'shed-key', 'value' => null],
                    ['type' => 'set_flag', 'subject' => 'pot-lifted', 'value' => 'yes'],
                ],
            ],
        ]))
        ->assertRedirect();

    $thing = $this->level->fresh()->things()
        ->with('interactions.conditions', 'interactions.effects')
        ->where('slug', 'flower-pot')
        ->sole();

    expect($thing->interactions)->toHaveCount(1);

    $interaction = $thing->interactions->first();

    expect($interaction->verb->value)->toBe('use')
        ->and($interaction->priority)->toBe(5)
        ->and($interaction->required_item_id)->toBeNull()
        ->and($interaction->conditions->pluck('subject')->all())->toBe(['pot-lifted'])
        // Effects keep the order they were written in: the flag is set after
        // the key has changed hands, not before.
        ->and($interaction->effects->pluck('type.value')->all())
        ->toBe(['give_item', 'set_flag']);
});

it('resolves the item a verb needs by its slug', function (): void {
    $this->actingAs($this->editor)
        ->put(route('levels.editor.update', $this->level), mapWithInteractions([
            [
                'verb' => 'use',
                'response' => 'The key turns.',
                'priority' => 0,
                'requiredItem' => 'shed-key',
                'conditions' => [],
                'effects' => [],
            ],
        ]))
        ->assertRedirect();

    $interaction = Interaction::query()->whereNotNull('level_thing_id')->sole();

    expect($interaction->required_item_id)->toBe($this->key->id);
});

it('refuses an item this game has never heard of', function (): void {
    $this->actingAs($this->editor)
        ->put(route('levels.editor.update', $this->level), mapWithInteractions([
            [
                'verb' => 'take',
                'response' => 'You take it.',
                'priority' => 0,
                'requiredItem' => null,
                'conditions' => [],
                'effects' => [
                    ['type' => 'give_item', 'subject' => 'moon-on-a-stick', 'value' => null],
                ],
            ],
        ]))
        ->assertSessionHasErrors('things.0.interactions.0.effects.0.subject');
});

it('replaces the interactions a thing had rather than adding to them', function (): void {
    $save = fn (string $response) => $this->actingAs($this->editor)
        ->put(route('levels.editor.update', $this->level), mapWithInteractions([
            [
                'verb' => 'look',
                'response' => $response,
                'priority' => 0,
                'requiredItem' => null,
                'conditions' => [],
                'effects' => [],
            ],
        ]));

    $save('It is a pot.')->assertRedirect();
    $save('It is a cracked pot.')->assertRedirect();

    expect(Interaction::query()->whereNotNull('level_thing_id')->get())
        ->toHaveCount(1)
        ->and(Interaction::query()->whereNotNull('level_thing_id')->sole()->response)
        ->toBe('It is a cracked pot.');
});

it('takes an interaction with the thing it belonged to', function (): void {
    $this->actingAs($this->editor)
        ->put(route('levels.editor.update', $this->level), mapWithInteractions([
            [
                'verb' => 'look',
                'response' => 'It is a pot.',
                'priority' => 0,
                'requiredItem' => null,
                'conditions' => [],
                'effects' => [],
            ],
        ]))
        ->assertRedirect();

    $withoutThePot = mapWithInteractions([]);
    $withoutThePot['things'] = [];

    $this->actingAs($this->editor)
        ->put(route('levels.editor.update', $this->level), $withoutThePot)
        ->assertRedirect();

    expect(Interaction::query()->whereNotNull('level_thing_id')->count())->toBe(0);
});

it('opens the editor with the whole tree, and the game\'s items to pick from', function (): void {
    $pot = LevelThing::factory()->create([
        'level_id' => $this->level->id,
        'slug' => 'flower-pot',
    ]);

    $interaction = Interaction::factory()->on($pot)->create([
        'response' => 'It is a pot.',
    ]);

    $interaction->conditions()->create([
        'type' => 'flag_is',
        'subject' => 'pot-lifted',
        'value' => 'yes',
    ]);

    $this->actingAs($this->editor)
        ->get(route('levels.editor', $this->level))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('assets.items', [['slug' => 'shed-key', 'name' => 'shed key']])
            ->where('level.things', fn ($things) => collect($things)
                ->firstWhere('slug', 'flower-pot')['interactions'] === [[
                    'verb' => 'look',
                    'response' => 'It is a pot.',
                    'priority' => 0,
                    'requiredItem' => null,
                    'conditions' => [
                        ['type' => 'flag_is', 'subject' => 'pot-lifted', 'value' => 'yes'],
                    ],
                    'effects' => [],
                ]]
            )
            ->etc()
        );
});
