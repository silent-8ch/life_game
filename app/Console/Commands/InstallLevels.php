<?php

namespace App\Console\Commands;

use App\Exceptions\LevelAlreadyDrawn;
use App\Exceptions\PretendingOnly;
use App\Models\Level;
use Database\Seeders\HallOfMirrorsSeeder;
use Database\Seeders\LevelEightSeeder;
use Database\Seeders\PortalDemoSeeder;
use Database\Seeders\TheHouseSeeder;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Puts authored levels into a database that must never be reseeded.
 *
 * The demo holds four levels that exist nowhere else — drawn by children, in no
 * repo and on no other machine — so it runs `migrate` on every deploy and
 * `db:seed` on none of them. Which means a level written in a seeder could
 * never reach the people actually playing. The doors were the visible case:
 * they worked, and the House on the demo had thirty-three things and no door to
 * open, because the House on the demo is not the House in `TheHouseSeeder`.
 *
 * ## Why this is safe to point at that database
 *
 * It does not decide what is safe to touch. **A level that is already there is
 * left entirely alone**, and what settles that is the unique index on
 * `(game_id, slug)` by way of `LevelAlreadyDrawn` — a rule in the schema rather
 * than a judgement in a command.
 *
 * Each seeder runs inside its own transaction. A seeder that finds its level
 * already drawn throws partway through, the transaction rolls back, and
 * whatever it had begun to write is gone. So the outcomes are: a level arrives
 * whole, or nothing about it changes. There is no third one.
 *
 * ## What it will not do
 *
 * It will not update a level that already exists, and that is deliberate rather
 * than unfinished. Somebody has been playing on the demo's copy; a seeder is
 * not a better authority on it than they are. If an authored level needs to
 * change on a database like that, the change belongs in the editor, made by
 * whoever owns it.
 */
class InstallLevels extends Command
{
    protected $signature = 'levels:install {--pretend : Say what would arrive, and write nothing}';

    protected $description = 'Add authored levels that are not in this database yet, and touch nothing that is';

    /**
     * The seeders that draw a level, in an order that respects what they need.
     *
     * `LifeSeeder` is not here: it makes the game itself, and a database with
     * no game is a fresh one, which wants `db:seed` rather than this.
     *
     * @var list<class-string>
     */
    private const AUTHORED = [
        TheHouseSeeder::class,
        PortalDemoSeeder::class,
        HallOfMirrorsSeeder::class,
        LevelEightSeeder::class,
    ];

    public function handle(): int
    {
        $before = Level::query()->pluck('slug')->all();

        $arrived = [];
        $already = [];
        $added = [];
        $lost = [];

        // A pretend run is one transaction around the whole thing, rolled back
        // at the end. It is the real work — the same seeders writing the same
        // rows — read back and then undone, rather than a guess at what the
        // real work would do. Pointing this at a database whose contents exist
        // nowhere else is exactly when a guess is not good enough.
        $run = function () use (&$arrived, &$already, &$added, &$lost, $before): void {
            foreach (self::AUTHORED as $seeder) {
                $short = class_basename($seeder);

                try {
                    DB::transaction(function () use ($seeder): void {
                        $this->callSilent('db:seed', [
                            '--class' => $seeder,
                            '--force' => true,
                        ]);
                    });

                    $arrived[] = $short;
                } catch (LevelAlreadyDrawn $there) {
                    $already[] = "{$short} ({$there->slug})";
                }
            }

            $after = Level::query()->pluck('slug')->all();
            $added = array_values(array_diff($after, $before));
            $lost = array_values(array_diff($before, $after));
        };

        if ($this->option('pretend')) {
            try {
                DB::transaction(function () use ($run): void {
                    $run();

                    // The only way out of a transaction that has done the work
                    // and must not keep it.
                    throw new PretendingOnly;
                });
            } catch (PretendingOnly) {
                // Rolled back, which was the point.
            }
        } else {
            $run();
        }

        foreach ($already as $one) {
            $this->line("  already there  {$one}");
        }

        foreach ($added as $slug) {
            $this->info("  added          {$slug}");
        }

        if ($arrived === [] && $added === []) {
            $this->line('  nothing to add.');
        }

        if ($this->option('pretend')) {
            $this->comment('  pretending — nothing was written.');
        }

        // A level cannot go missing by adding levels, so if one has, something
        // is wrong in a way that is worth saying loudly rather than counting.
        if ($lost !== []) {
            $this->error('  LOST: '.implode(', ', $lost));

            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
