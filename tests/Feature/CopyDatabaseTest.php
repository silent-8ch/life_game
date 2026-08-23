<?php

use App\Models\Level;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;

/**
 * Moving every row from one database to another.
 *
 * The copy that matters is the demo's, and it happens once: its sqlite file is
 * the only copy of four levels drawn by children. So what is worth testing is
 * not that rows arrive — it is the two ways a copy can look like it worked and
 * not have: a table the target does not have, and a target that already held
 * something.
 *
 * Two sqlite files stand in for the two databases. The drivers differ in the
 * real move; what this pins is the command's own decisions, which do not.
 */
beforeEach(function (): void {
    $this->source = base_path('storage/framework/testing/copy-source.sqlite');
    $this->target = base_path('storage/framework/testing/copy-target.sqlite');

    File::ensureDirectoryExists(dirname($this->source));

    foreach ([$this->source, $this->target] as $file) {
        File::delete($file);
        File::put($file, '');
    }

    foreach (['copy_source' => $this->source, 'copy_target' => $this->target] as $name => $file) {
        config(["database.connections.{$name}" => [
            'driver' => 'sqlite',
            'database' => $file,
            'prefix' => '',
            'foreign_key_constraints' => false,
        ]]);

        DB::purge($name);
    }
});

afterEach(function (): void {
    File::delete([$this->source, $this->target]);
});

/** Runs every migration against one of the stand-in databases. */
function migrateInto(string $connection): void
{
    Artisan::call('migrate', ['--database' => $connection, '--force' => true]);
}

it('copies every row across and says the two sides match', function (): void {
    migrateInto('copy_source');
    migrateInto('copy_target');

    $game = DB::connection('copy_source')->table('games')->insertGetId([
        'slug' => 'life', 'title' => 'Life', 'tagline' => '',
        'is_published' => true, 'sort_order' => 0,
        'created_at' => now(), 'updated_at' => now(),
    ]);

    DB::connection('copy_source')->table('levels')->insert([
        ['game_id' => $game, 'slug' => 'attic', 'name' => 'Attic', 'description' => '',
            'spawn_x' => 0, 'spawn_z' => 0, 'spawn_angle' => 0, 'ceiling_height' => 3,
            'created_at' => now(), 'updated_at' => now()],
        ['game_id' => $game, 'slug' => 'cellar', 'name' => 'Cellar', 'description' => '',
            'spawn_x' => 0, 'spawn_z' => 0, 'spawn_angle' => 0, 'ceiling_height' => 3,
            'created_at' => now(), 'updated_at' => now()],
    ]);

    $this->artisan('db:copy', ['--from' => 'copy_source', '--to' => 'copy_target'])
        ->assertSuccessful();

    expect(DB::connection('copy_target')->table('levels')->pluck('slug')->all())
        ->toBe(['attic', 'cellar'])
        ->and(DB::connection('copy_target')->table('games')->count())->toBe(1);
});

it('replaces whatever the target already held rather than adding to it', function (): void {
    migrateInto('copy_source');
    migrateInto('copy_target');

    $row = fn (string $slug): array => [
        'slug' => $slug, 'title' => $slug, 'tagline' => '',
        'is_published' => true, 'sort_order' => 0,
        'created_at' => now(), 'updated_at' => now(),
    ];

    DB::connection('copy_source')->table('games')->insert($row('life'));
    DB::connection('copy_target')->table('games')->insert($row('something-else'));

    $this->artisan('db:copy', ['--from' => 'copy_source', '--to' => 'copy_target'])
        ->assertSuccessful();

    // The copy is the source, not the source plus whatever was there. A target
    // that keeps a stale row is the failure that looks like success.
    expect(DB::connection('copy_target')->table('games')->pluck('slug')->all())
        ->toBe(['life']);
});

it('never copies the migrations table, so the target keeps its own account', function (): void {
    migrateInto('copy_source');
    migrateInto('copy_target');

    DB::connection('copy_source')->table('migrations')->insert([
        'migration' => '1970_01_01_000000_something_the_target_never_ran', 'batch' => 99,
    ]);

    $this->artisan('db:copy', ['--from' => 'copy_source', '--to' => 'copy_target'])
        ->assertSuccessful();

    expect(DB::connection('copy_target')->table('migrations')
        ->where('migration', 'like', '%something_the_target_never_ran')->exists()
    )->toBeFalse();
});

it('skips a table the target has not got, and says so', function (): void {
    migrateInto('copy_source');
    migrateInto('copy_target');

    Schema::connection('copy_target')->drop('levels');

    $this->artisan('db:copy', ['--from' => 'copy_source', '--to' => 'copy_target'])
        ->expectsOutputToContain('Skipping [levels]')
        ->assertSuccessful();
});

it('refuses to copy a database onto itself', function (): void {
    $this->artisan('db:copy', ['--from' => 'copy_source', '--to' => 'copy_source'])
        ->assertFailed();
});

it('writes nothing when it is only pretending', function (): void {
    migrateInto('copy_source');
    migrateInto('copy_target');

    DB::connection('copy_source')->table('games')->insert([
        'slug' => 'life', 'title' => 'Life', 'tagline' => '',
        'is_published' => true, 'sort_order' => 0,
        'created_at' => now(), 'updated_at' => now(),
    ]);

    $this->artisan('db:copy', [
        '--from' => 'copy_source', '--to' => 'copy_target', '--pretend' => true,
    ])->assertSuccessful();

    expect(DB::connection('copy_target')->table('games')->count())->toBe(0);
});

it('leaves the level the app itself is using alone', function (): void {
    // The command names its connections, so the default one is never a party to
    // the copy unless somebody says so. Worth pinning: this runs against the
    // real database in the one situation it exists for.
    migrateInto('copy_source');
    migrateInto('copy_target');

    $before = Level::count();

    $this->artisan('db:copy', ['--from' => 'copy_source', '--to' => 'copy_target'])
        ->assertSuccessful();

    expect(Level::count())->toBe($before);
});
