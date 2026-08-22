<?php

namespace App\Services;

use App\Models\Game;
use App\Models\Item;
use App\Models\Level;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Builds a level again from what {@see LevelExporter} wrote.
 *
 * Replaces rather than merges: a level of the same slug in the same game is
 * deleted first, so importing twice leaves one level and not two, and an import
 * is always the whole level rather than a patch. Everything hangs off the level
 * row by cascade, so deleting it takes its sectors, edges, things and
 * interactions with it.
 *
 * Corners are matched by coordinate on the way in, exactly as the editor does:
 * two rooms share a corner because they name the same spot.
 */
class LevelImporter
{
    /**
     * @param  array<string, mixed>  $plan
     */
    public function import(Game $game, array $plan): Level
    {
        if (($plan['format'] ?? null) !== 1) {
            throw new RuntimeException(
                'That file is not a level export this version can read.',
            );
        }

        return DB::transaction(function () use ($game, $plan): Level {
            $game->levels()->where('slug', $plan['level']['slug'])->delete();

            /** @var Level $level */
            $level = $game->levels()->create($plan['level']);

            foreach ($plan['sectors'] as $room) {
                $edges = $room['edges'];
                unset($room['edges']);

                $sector = $level->sectors()->create($room);

                foreach ($edges as $edge) {
                    $vertex = $level->vertices()->firstOrCreate([
                        'x' => $edge['x'],
                        'z' => $edge['z'],
                    ]);

                    unset($edge['x'], $edge['z']);

                    $sector->edges()->create([
                        ...$edge,
                        'vertex_id' => $vertex->id,
                    ]);
                }
            }

            foreach ($plan['things'] as $what) {
                $interactions = $what['interactions'] ?? [];
                unset($what['interactions']);

                $thing = $level->things()->create($what);

                foreach ($interactions as $interaction) {
                    $conditions = $interaction['conditions'] ?? [];
                    $effects = $interaction['effects'] ?? [];
                    $item = $interaction['requiredItem'] ?? null;

                    unset(
                        $interaction['conditions'],
                        $interaction['effects'],
                        $interaction['requiredItem'],
                    );

                    $made = $thing->interactions()->create([
                        ...$interaction,
                        // An item that does not exist in the game being
                        // imported into leaves the interaction unguarded rather
                        // than failing the whole import. The alternative is a
                        // level that will not load because of one verb.
                        'required_item_id' => $item === null
                            ? null
                            : Item::query()
                                ->where('game_id', $game->id)
                                ->where('slug', $item)
                                ->value('id'),
                    ]);

                    foreach ($conditions as $condition) {
                        $made->conditions()->create($condition);
                    }

                    foreach ($effects as $effect) {
                        $made->effects()->create($effect);
                    }
                }
            }

            return $level;
        });
    }
}
