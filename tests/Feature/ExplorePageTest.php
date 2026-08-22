<?php

use App\Models\Game;
use App\Models\GameState;
use App\Models\Level;
use App\Services\LevelAssets;
use App\Services\PersonStats;
use Database\Seeders\LifeSeeder;
use Database\Seeders\TheHouseSeeder;
use Inertia\Testing\AssertableInertia;

beforeEach(function (): void {
    $this->seed(LifeSeeder::class);
    $this->game = Game::query()->where('slug', 'life')->sole();
});

it('renders the starting level in the first person', function (): void {
    $this->get(route('games.show', $this->game))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('game/explore')
            ->where('game.slug', 'life')
            ->where('level.slug', 'tech-demo')
            ->where('level.ceilingHeight', 3)
            ->where('level.spawn.x', 2.6)
            ->where('level.spawn.z', 4.4)
            ->has('level.sectors', 3)
            ->has('level.things', 18)
            ->has('inventory', 0)
            ->where('message', null)
        );
});

it('sends every sector as a polygon the browser can build', function (): void {
    $this->get(route('games.show', $this->game))
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->has('level.sectors.0', fn (AssertableInertia $sector) => $sector
                ->hasAll([
                    'slug', 'name', 'floorHeight', 'ceilingHeight',
                    'floorSlope', 'floorSlopeEdge',
                    'ceilingSlope', 'ceilingSlopeEdge',
                    'floorTexture', 'ceilingTexture', 'wallTexture',
                    'isSky', 'isWater', 'points',
                ])
                ->has('points.0', fn (AssertableInertia $point) => $point
                    ->hasAll([
                        'x', 'z', 'wallTexture', 'blocks', 'isMirror', 'isSky',
                        'portalLink',
                    ])
                )
            )
        );
});

it('tells the engine which set of sprite sheets to draw people from', function (): void {
    $this->get(route('games.show', $this->game))
        ->assertInertia(function (AssertableInertia $page): void {
            $style = $page->toArray()['props']['level']['spriteStyle'];

            expect($style)->toBe(LevelAssets::STYLE)
                ->and(public_path("sprites/{$style}"))->toBeDirectory();
        });
});

it('sends the sky and its backdrop layers', function (): void {
    $this->get(route('games.show', $this->game))
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('level.sky.image', 'sky-day')
            ->where('level.sky.theme', 'hills')
            ->has('level.sky.layers', 3)
        );
});

it('sends every thing as a box with a kind', function (): void {
    $this->get(route('games.show', $this->game))
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->has('level.things.0', fn (AssertableInertia $thing) => $thing
                ->hasAll([
                    'slug', 'name', 'description', 'kind',
                    'sprite', 'behaviour', 'stats', 'speed', 'texture',
                    'render', 'planeCount', 'uvMode', 'textureAlt', 'altFlag',
                    'animationFrames', 'animationFps',
                    'x', 'z', 'elevation', 'width', 'depth', 'height',
                    'angle', 'isSolid',
                    'isDoor', 'swing', 'openAngle', 'openSeconds',
                    'isOpen', 'opensFlag',
                    'verbs',
                ])
            )
        );
});

it('opens the save file on a level rather than a scene', function (): void {
    expect(GameState::query()->count())->toBe(0);

    $this->get(route('games.show', $this->game))->assertOk();

    $state = GameState::for($this->game->fresh());

    expect($state->currentLevel?->slug)->toBe('tech-demo')
        ->and($state->current_scene_id)->toBeNull();
});

it('starts the game at its authored starting level', function (): void {
    $spare = Level::factory()->for($this->game)->create(['slug' => 'roof']);
    $this->game->update(['starting_level_id' => $spare->id]);

    $this->get(route('games.show', $this->game))
        ->assertInertia(fn (AssertableInertia $page) => $page->where('level.slug', 'roof'));
});

it('names the level the player is in on the index', function (): void {
    GameState::for($this->game);

    $this->get(route('games.index'))
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('games.0.slug', 'life')
            ->where('games.0.inProgress', true)
            ->where('games.0.currentLocationName', 'Tech Demo')
        );
});

it('restarts a first person game back at its starting level', function (): void {
    $spare = Level::factory()->for($this->game)->create(['slug' => 'roof']);
    $state = GameState::for($this->game);
    $state->update(['current_level_id' => $spare->id, 'last_message' => 'Something happened.']);

    $this->delete(route('games.save.destroy', $this->game))
        ->assertRedirect(route('games.show', $this->game));

    $state->refresh();

    expect($state->currentLevel?->slug)->toBe('tech-demo')
        ->and($state->last_message)->toBeNull();
});

it('does not serve an unpublished first person game', function (): void {
    $this->game->update(['is_published' => false]);

    $this->get(route('games.show', $this->game))->assertNotFound();
});

it('plays one particular level when the visit asks for it', function (): void {
    $this->seed(TheHouseSeeder::class);

    $game = Game::query()->where('slug', 'life')->sole();

    // The save opens the house; the editor's Play button asks for the other one.
    $this->get(route('games.show', ['game' => $game, 'level' => 'tech-demo']))
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('game/explore')
            ->where('level.slug', 'tech-demo')
        );

    // And it is only for that visit — the save still points where it did.
    $this->get(route('games.show', $game))
        ->assertInertia(fn (AssertableInertia $page) => $page->where('level.slug', 'house'));
});

it('ignores a level that is not part of the game', function (): void {
    $game = Game::query()->where('slug', 'life')->sole();

    $this->get(route('games.show', ['game' => $game, 'level' => 'no-such-level']))
        ->assertInertia(fn (AssertableInertia $page) => $page->where('level.slug', 'tech-demo'));
});

it('sends the numbers a person starts with, resolved', function (): void {
    $this->get(route('games.show', $this->game))
        ->assertInertia(function (AssertableInertia $page): void {
            /** @var array<int, array<string, mixed>> $things */
            $things = $page->toArray()['props']['level']['things'];

            $people = array_values(array_filter(
                $things,
                fn (array $thing): bool => $thing['kind'] === 'actor',
            ));

            expect($people)->not->toBeEmpty();

            foreach ($people as $person) {
                expect($person['stats'])
                    ->toBe(PersonStats::STARTING[$person['sprite']], $person['slug']);
            }

            // Furniture is never asked what it is made of.
            foreach ($things as $thing) {
                if ($thing['kind'] !== 'actor') {
                    expect($thing['stats'])->toBeNull();
                }
            }
        });
});

it('sends what the player themselves starts with', function (): void {
    $this->get(route('games.show', $this->game))
        ->assertInertia(function (AssertableInertia $page): void {
            $level = $page->toArray()['props']['level'];

            expect($level['playerStats'])
                ->toBe(PersonStats::STARTING[$level['playerSprite']]);
        });
});

it('hands a person their own numbers over their sprite\'s', function (): void {
    $level = Level::query()->where('slug', 'tech-demo')->sole();
    $person = $level->things()->where('kind', 'actor')->firstOrFail();

    $person->update(['stats' => [...PersonStats::STARTING[$person->sprite], 'luck' => 10]]);

    $this->get(route('games.show', $this->game))
        ->assertInertia(function (AssertableInertia $page) use ($person): void {
            /** @var array<int, array<string, mixed>> $things */
            $things = $page->toArray()['props']['level']['things'];

            $sent = collect($things)->firstWhere('slug', $person->slug);

            expect($sent['stats']['luck'])->toBe(10)
                ->and($sent['stats'])->toHaveCount(count(PersonStats::ATTRIBUTES));
        });
});

it('sends the flags the save has set, so the level can show what the world knows', function (): void {
    // Without this the alt-texture columns on a thing name a flag that nothing
    // can read: the column says *which* flag, and until now the browser was
    // never told what any flag said. A lamp switched on could not be drawn on.
    $state = GameState::for($this->game);

    $state->flags()->create(['key' => 'lamp_on', 'value' => 'yes']);
    $state->flags()->create(['key' => 'drawer_open', 'value' => 'no']);

    $this->get(route('games.show', $this->game))
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('flags.lamp_on', 'yes')
            ->where('flags.drawer_open', 'no')
        );
});

it('leaves a flag nobody has touched out altogether', function (): void {
    // Absent rather than empty, so "is this set" is a question about the keys.
    // An unset flag and one set to nothing are different states and the client
    // has no way to tell them apart if both arrive as an empty string.
    GameState::for($this->game);

    $this->get(route('games.show', $this->game))
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->has('flags')
            ->missing('flags.never_touched')
        );
});

it('reads the flags fresh on every request rather than once', function (): void {
    // The whole reason this is not a load-time snapshot. After an interaction
    // the browser asks for `inventory, flags, message` and deliberately not the
    // level, so the geometry it is holding is never rebuilt — which only works
    // if flags are a plain prop re-read each time rather than baked in.
    //
    // The partial reload itself is Inertia's business and testing it would test
    // Inertia. What is worth pinning here is that a flag changed between two
    // requests comes back changed.
    $state = GameState::for($this->game);

    $state->flags()->create(['key' => 'lamp_on', 'value' => 'no']);

    $this->get(route('games.show', $this->game))
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('flags.lamp_on', 'no')
        );

    $state->flags()->where('key', 'lamp_on')->update(['value' => 'yes']);

    $this->get(route('games.show', $this->game))
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('flags.lamp_on', 'yes')
        );
});
