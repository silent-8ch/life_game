<?php

namespace App\Services;

use App\Models\Level;
use Illuminate\Support\Facades\Schema;

/**
 * A level as a portable file: everything needed to build it again somewhere
 * else, and nothing tied to where it came from.
 *
 * Ids go, because they mean nothing in another database — a sector's edges name
 * their corner by coordinate, the way the engine and the editor already match
 * corners, and a thing's interactions hang off the thing. Timestamps go for the
 * same reason.
 *
 * The columns are read from the schema rather than listed here on purpose.
 * `level_sectors` and `level_things` have both gained columns three times in a
 * day — slopes, prop rendering, doors — and a hand-written list is a list that
 * silently stops carrying the newest field, which would show up as a level that
 * imports looking subtly wrong rather than as an error.
 */
class LevelExporter
{
    /** Columns that describe where a row lives rather than what it is. */
    private const SKIP = ['id', 'created_at', 'updated_at'];

    /**
     * @return array<string, mixed>
     */
    public function export(Level $level): array
    {
        $level->load([
            'sectors.edges.vertex',
            'things.interactions.conditions',
            'things.interactions.effects',
            'things.interactions.requiredItem',
        ]);

        return [
            'format' => 1,
            'level' => $this->columns($level, 'levels', ['game_id']),
            'sectors' => $level->sectors
                ->sortBy('sort_order')
                ->values()
                ->map(fn ($sector): array => [
                    ...$this->columns($sector, 'level_sectors', ['level_id']),
                    'edges' => $sector->edges
                        ->sortBy('sort_order')
                        ->values()
                        ->map(fn ($edge): array => [
                            ...$this->columns($edge, 'level_sector_edges', [
                                'sector_id', 'vertex_id',
                            ]),
                            // By coordinate, which is how corners are matched
                            // everywhere else: two rooms share one because they
                            // name the same spot, never because they hold an id.
                            'x' => (float) $edge->vertex->x,
                            'z' => (float) $edge->vertex->z,
                        ])->all(),
                ])->all(),
            'things' => $level->things
                ->sortBy('sort_order')
                ->values()
                ->map(fn ($thing): array => [
                    ...$this->columns($thing, 'level_things', ['level_id']),
                    'interactions' => $thing->interactions
                        ->map(fn ($interaction): array => [
                            ...$this->columns($interaction, 'interactions', [
                                'level_thing_id', 'hotspot_id', 'required_item_id',
                            ]),
                            // Items belong to the game, not the level, so they
                            // travel by slug and are matched on the far side.
                            'requiredItem' => $interaction->requiredItem?->slug,
                            'conditions' => $interaction->conditions
                                ->map(fn ($condition): array => $this->columns(
                                    $condition,
                                    'interaction_conditions',
                                    ['interaction_id'],
                                ))->all(),
                            'effects' => $interaction->effects
                                ->map(fn ($effect): array => $this->columns(
                                    $effect,
                                    'interaction_effects',
                                    ['interaction_id'],
                                ))->all(),
                        ])->all(),
                ])->all(),
        ];
    }

    /**
     * Every column a table actually has, less the ones that only mean something
     * in the database it came out of.
     *
     * @param  list<string>  $also
     * @return array<string, mixed>
     */
    private function columns(object $row, string $table, array $also = []): array
    {
        $skip = [...self::SKIP, ...$also];
        $out = [];

        foreach (Schema::getColumnListing($table) as $column) {
            if (in_array($column, $skip, true)) {
                continue;
            }

            $value = $row->{$column};

            $out[$column] = $value instanceof \BackedEnum ? $value->value : $value;
        }

        return $out;
    }
}
