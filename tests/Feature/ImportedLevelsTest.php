<?php

use App\Enums\ThingKind;
use App\Models\Level;
use App\Models\LevelSector;
use App\Models\LevelThing;
use App\Services\LevelAssets;
use Database\Seeders\ImportedLevelsSeeder;
use Database\Seeders\LifeSeeder;

/**
 * The levels drawn on the original instance and exported into
 * `ImportedLevelsSeeder`, checked against what they were drawn as: the same
 * rooms, the same corners, the same people standing in them.
 */
beforeEach(function (): void {
    $this->seed(LifeSeeder::class);
    $this->seed(ImportedLevelsSeeder::class);
});

/**
 * What each level was drawn as: its name, and how many rooms, corners and
 * things it had on the instance it came from.
 *
 * @var list<array{0: string, 1: string, 2: int, 3: int, 4: int}>
 */
$drawn = [
    ['new-level', 'New Level', 22, 45, 6],
    ['new-level-for-children', 'New Level for Children', 10, 33, 17],
    ['william-level', 'William Level', 2, 8, 5],
    ['wade-wade-wade', 'Wade wade WADE', 1, 4, 4],
    ['will-world', 'Will world', 20, 38, 6],
    ['will', 'WILL', 1, 4, 6],
];

$slugs = array_map(fn (array $level): string => $level[0], $drawn);

it('brings every drawn level over whole', function (string $slug, string $name, int $sectors, int $vertices, int $things): void {
    $level = Level::query()->where('slug', $slug)->with('sectors.edges')->sole();

    expect($level->name)->toBe($name)
        ->and($level->game->slug)->toBe('life')
        ->and($level->sectors)->toHaveCount($sectors)
        ->and($level->vertices()->count())->toBe($vertices)
        ->and($level->things()->count())->toBe($things);
})->with($drawn);

/**
 * Two rooms were drawn freehand and repeat a corner several times over — the
 * room in `new-level-for-children` and `room-23` in `will-world`. That is how
 * they were drawn and how they come across, so this asks only that each room
 * is a closed run of walls with no gap in it.
 */
it('closes every room it brings over', function (string $slug): void {
    $level = Level::query()->where('slug', $slug)->with('sectors.edges.vertex')->sole();

    foreach ($level->sectors as $sector) {
        expect($sector->edges->count())->toBeGreaterThanOrEqual(3, "$slug/{$sector->slug} is not a polygon");

        expect($sector->edges->pluck('sort_order')->all())
            ->toBe(range(0, $sector->edges->count() - 1), "$slug/{$sector->slug} has a gap in its walls");
    }
})->with($slugs);

it('spawns the player inside a room', function (string $slug): void {
    $level = Level::query()->where('slug', $slug)->with('sectors.edges.vertex')->sole();

    $inside = $level->sectors->contains(fn (LevelSector $sector): bool => encloses(
        $sector->edges->map(fn ($edge): array => [$edge->vertex->x, $edge->vertex->z])->all(),
        $level->spawn_x,
        $level->spawn_z,
    ));

    expect($inside)->toBeTrue("$slug spawns outside every room");
})->with($slugs);

it('gives every person a sprite sheet that exists', function (): void {
    $available = app(LevelAssets::class)->sprites();

    $actors = LevelThing::query()->where('kind', ThingKind::Actor)->get();

    expect($actors)->not->toBeEmpty();

    foreach ($actors as $actor) {
        expect($actor->sprite)->toBeIn($available)
            ->and($actor->behaviour)->not->toBeNull()
            ->and($actor->height)->toBeGreaterThan(0);
    }
});

it('keeps the one prop that was placed, with its texture', function (): void {
    $prop = LevelThing::query()
        ->where('kind', ThingKind::Prop)
        ->whereRelation('level', 'slug', 'new-level-for-children')
        ->sole();

    expect($prop->name)->toBe('Thing')
        ->and($prop->texture)->toBe('dry-grass')
        ->and($prop->is_solid)->toBeTrue();
});

it('gives each level the player sprite it was drawn with', function (): void {
    expect(Level::query()->whereIn('slug', ['william-level', 'will-world', 'will'])->pluck('player_sprite')->unique()->all())
        ->toBe(['william'])
        ->and(Level::query()->where('slug', 'wade-wade-wade')->value('player_sprite'))->toBe('wade');
});

/**
 * Whether a point falls inside a polygon, by casting a ray east and counting
 * the walls it crosses.
 *
 * @param  list<array{0: float, 1: float}>  $corners
 */
function encloses(array $corners, float $x, float $z): bool
{
    $inside = false;

    for ($i = 0, $j = count($corners) - 1; $i < count($corners); $j = $i++) {
        [$ix, $iz] = $corners[$i];
        [$jx, $jz] = $corners[$j];

        if (($iz > $z) !== ($jz > $z) && $x < ($jx - $ix) * ($z - $iz) / ($jz - $iz) + $ix) {
            $inside = ! $inside;
        }
    }

    return $inside;
}
