<?php

namespace App\Console\Commands;

use App\Models\Level;
use App\Services\LevelExporter;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

/**
 * Writes levels out as files that can be committed and seeded somewhere else.
 *
 * The point is moving a level between instances. Everything a level needs
 * travels with it — rooms, walls, corners by coordinate, things and their
 * verbs — and nothing that only means something in the database it came from.
 */
class ExportLevels extends Command
{
    protected $signature = 'levels:export
        {slug?* : Which levels, by slug. All of them if you name none.}
        {--path= : Where to write. Defaults to database/seeders/data/levels.}';

    protected $description = 'Write levels out as portable JSON.';

    public function handle(LevelExporter $exporter): int
    {
        $where = $this->option('path') ?? database_path('seeders/data/levels');

        File::ensureDirectoryExists($where);

        /** @var list<string> $wanted */
        $wanted = $this->argument('slug');

        $levels = Level::query()
            ->when($wanted !== [], fn ($query) => $query->whereIn('slug', $wanted))
            ->orderBy('game_id')
            ->orderBy('slug')
            ->get();

        if ($levels->isEmpty()) {
            $this->error('No levels matched.');

            return self::FAILURE;
        }

        foreach ($levels as $level) {
            $plan = $exporter->export($level);
            $file = "{$where}/{$level->game->slug}.{$level->slug}.json";

            File::put(
                $file,
                json_encode($plan, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)."\n",
            );

            $this->line(sprintf(
                '%s: %d rooms, %d walls, %d things -> %s',
                $level->slug,
                count($plan['sectors']),
                array_sum(array_map(fn ($room): int => count($room['edges']), $plan['sectors'])),
                count($plan['things']),
                str_replace(base_path().'/', '', $file),
            ));
        }

        return self::SUCCESS;
    }
}
