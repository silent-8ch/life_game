<?php

namespace Database\Seeders;

use App\Models\Game;
use App\Services\LevelImporter;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\File;

/**
 * Levels that were exported from a running game rather than authored by hand.
 *
 * `php artisan levels:export` writes them; this puts them back. That is what
 * moving a level to another instance is: commit the files, pull, seed.
 *
 * **An exported file wins over an authored level of the same slug.** It runs
 * last and the importer replaces rather than merges, so whatever was actually
 * being played is what the other instance gets — which is the point of
 * exporting at all. Delete a file to fall back to the seeder that authors it.
 *
 * The authored seeders are still the way to *write* a level. Nothing here
 * invents anything; every file came out of a database.
 */
class ExportedLevelsSeeder extends Seeder
{
    /** Where `levels:export` writes, and where this reads. */
    private const LEVELS = __DIR__.'/data/levels';

    public function run(LevelImporter $importer): void
    {
        if (! File::isDirectory(self::LEVELS)) {
            return;
        }

        foreach (File::files(self::LEVELS) as $file) {
            if ($file->getExtension() !== 'json') {
                continue;
            }

            // Named `{game}.{level}.json`, so which game a level belongs to
            // survives the trip without being buried in the file.
            [$game] = explode('.', $file->getFilename(), 2);

            $into = Game::query()->where('slug', $game)->first();

            if ($into === null) {
                $this->command->warn(
                    "Skipped {$file->getFilename()}: no game called {$game}.",
                );

                continue;
            }

            $plan = json_decode(
                File::get($file->getPathname()),
                true,
                flags: JSON_THROW_ON_ERROR,
            );

            $level = $importer->import($into, $plan);

            $this->command->line(sprintf(
                '  %s: %d rooms, %d things',
                $level->slug,
                $level->sectors()->count(),
                $level->things()->count(),
            ));
        }
    }
}
