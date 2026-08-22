<?php

use App\Models\Game;
use App\Models\Level;
use App\Services\LevelExporter;
use App\Services\LevelImporter;
use Database\Seeders\LifeSeeder;
use Database\Seeders\PortalDemoSeeder;
use Database\Seeders\TheHouseSeeder;

/**
 * Moving a level to another instance.
 *
 * The whole promise is that what comes back is what went out, so that is what
 * is tested: export a level, wipe it, import it, export again, and compare the
 * two files. Anything the exporter forgets to carry shows up as a difference
 * rather than as a level that looks subtly wrong in a month.
 *
 * That matters more than usual here because the columns are read from the
 * schema rather than listed. `level_sectors` and `level_things` gained columns
 * three times in one day — slopes, prop rendering, doors — and this is what
 * keeps the export honest when the next one lands.
 */
it('brings a level back exactly as it went out', function (): void {
    $this->seed(LifeSeeder::class);
    $this->seed(TheHouseSeeder::class);

    $game = Game::query()->where('slug', 'life')->sole();
    $house = $game->levels()->where('slug', 'house')->sole();

    $exporter = app(LevelExporter::class);
    $before = $exporter->export($house);

    app(LevelImporter::class)->import($game, $before);

    $again = $game->levels()->where('slug', 'house')->sole();

    expect($exporter->export($again))->toEqual($before);
});

it('replaces a level of the same slug rather than making a second', function (): void {
    $this->seed(LifeSeeder::class);
    $this->seed(PortalDemoSeeder::class);

    $game = Game::query()->where('slug', 'life')->sole();
    $plan = app(LevelExporter::class)->export(
        $game->levels()->where('slug', 'portals')->sole(),
    );

    app(LevelImporter::class)->import($game, $plan);
    app(LevelImporter::class)->import($game, $plan);

    // Importing twice is a thing people do, and two levels of one slug is a
    // level nobody can edit: the editor and the payload both fetch by slug.
    expect($game->levels()->where('slug', 'portals')->count())->toBe(1);
});

it('carries the corners by where they are, not by their ids', function (): void {
    $this->seed(LifeSeeder::class);
    $this->seed(PortalDemoSeeder::class);

    $game = Game::query()->where('slug', 'life')->sole();
    $portals = $game->levels()->where('slug', 'portals')->sole();

    $plan = app(LevelExporter::class)->export($portals);
    // Counted before the import, which deletes the level this model came from.
    $corners = $portals->vertices()->count();
    $named = collect($plan['sectors'])
        ->flatMap(fn (array $room): array => $room['edges'])
        ->map(fn (array $edge): string => "{$edge['x']},{$edge['z']}");

    // Ids mean nothing in another database. Two rooms share a corner because
    // they name the same spot, which is how the editor and the engine match
    // them too — so the file has to say where, and never which row.
    expect($named)->not->toBeEmpty()
        ->and(json_encode($plan))->not->toContain('vertex_id')
        ->and(json_encode($plan))->not->toContain('"id"');

    $imported = app(LevelImporter::class)->import($game, $plan);

    // And the corners that were shared before are still one corner after,
    // rather than one per room that happens to sit in the same place.
    expect($imported->vertices()->count())->toBe($corners);
});

it('refuses a file it does not understand', function (): void {
    $game = Game::query()->create([
        'slug' => 'nowhere',
        'title' => 'Nowhere',
        'tagline' => '',
        'sort_order' => 99,
    ]);

    expect(fn () => app(LevelImporter::class)->import($game, ['format' => 99]))
        ->toThrow(RuntimeException::class);
});

it('writes a file per level, named for the game it belongs to', function (): void {
    $this->seed(LifeSeeder::class);
    $this->seed(PortalDemoSeeder::class);

    $where = storage_path('framework/testing/levels');

    $this->artisan('levels:export', ['slug' => ['portals'], '--path' => $where])
        ->assertSuccessful();

    expect(file_exists("{$where}/life.portals.json"))->toBeTrue();

    $plan = json_decode(
        (string) file_get_contents("{$where}/life.portals.json"),
        true,
        flags: JSON_THROW_ON_ERROR,
    );

    expect($plan['format'])->toBe(1)
        ->and($plan['level']['slug'])->toBe('portals')
        ->and($plan['sectors'])->toHaveCount(8);

    unlink("{$where}/life.portals.json");
});
