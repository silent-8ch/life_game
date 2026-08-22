<?php

namespace App\Services;

use App\Enums\ActorBehaviour;
use App\Enums\ThingKind;
use App\Models\Level;
use App\Models\LevelSector;
use Illuminate\Support\Facades\DB;

/**
 * The room a brand new level starts with, so the map editor opens on something
 * walkable rather than an empty grid.
 */
class LevelStarter
{
    public function __construct(private LevelAssets $assets) {}

    /** How wide and deep the first room is, in metres. */
    public const SIZE = 8.0;

    public const FLOOR_TEXTURE = 'oak-floor';

    public const CEILING_TEXTURE = 'cream-plaster-wall';

    public const WALL_TEXTURE = 'cream-plaster-wall';

    /**
     * A name no level in the game is using yet: New Level, then New Level 2,
     * and so on, so creating several in a row never trips the unique slug.
     *
     * @return array{name: string, slug: string}
     */
    public function freeName(?int $gameId): array
    {
        $taken = Level::query()
            ->where('game_id', $gameId)
            ->pluck('slug')
            ->all();

        $number = 1;

        while (in_array(self::slugFor($number), $taken, strict: true)) {
            $number++;
        }

        return [
            'name' => $number === 1 ? 'New Level' : "New Level {$number}",
            'slug' => self::slugFor($number),
        ];
    }

    private static function slugFor(int $number): string
    {
        return $number === 1 ? 'new-level' : "new-level-{$number}";
    }

    /**
     * Puts one square room around wherever the player starts. Does nothing to a
     * level that already has a shape, so it is safe to call twice.
     */
    public function room(Level $level): Level
    {
        if ($level->sectors()->exists()) {
            return $level;
        }

        $half = self::SIZE / 2;

        // Wound so the signed area is positive, which is how the engine works
        // out which side of a wall faces into the room.
        $corners = [
            [$level->spawn_x - $half, $level->spawn_z - $half],
            [$level->spawn_x + $half, $level->spawn_z - $half],
            [$level->spawn_x + $half, $level->spawn_z + $half],
            [$level->spawn_x - $half, $level->spawn_z + $half],
        ];

        DB::transaction(function () use ($level, $corners): void {
            /** @var LevelSector $sector */
            $sector = $level->sectors()->create([
                'slug' => 'room',
                'name' => 'Room',
                'floor_height' => 0,
                'ceiling_height' => $level->ceiling_height,
                'floor_texture' => self::FLOOR_TEXTURE,
                'ceiling_texture' => self::CEILING_TEXTURE,
                'wall_texture' => self::WALL_TEXTURE,
                'is_sky' => false,
                'is_water' => false,
                'sort_order' => 0,
            ]);

            foreach ($corners as $index => [$x, $z]) {
                $vertex = $level->vertices()->create(['x' => $x, 'z' => $z]);

                $sector->edges()->create([
                    'vertex_id' => $vertex->id,
                    'sort_order' => $index,
                    'wall_texture' => null,
                    'blocks' => true,
                    'is_mirror' => false,
                ]);
            }
        });

        $this->household($level);

        return $level->fresh(['sectors.edges.vertex', 'things']);
    }

    /**
     * Everyone but the player, stood about the room so that a new level has
     * somebody in it. They are ordinary things once they are here: move them,
     * or throw them out, in the map editor.
     */
    private function household(Level $level): void
    {
        $people = $this->assets->household();
        $spread = (self::SIZE / 2) - 1;

        foreach ($people as $index => $sprite) {
            // Spaced around a ring, well clear of the walls and of each other.
            $turn = (2 * M_PI * $index) / max(count($people), 1);

            $level->things()->create([
                'slug' => $sprite,
                'name' => ucfirst($sprite),
                'description' => ucfirst($sprite).' is wandering about.',
                'kind' => ThingKind::Actor,
                'sprite' => $sprite,
                'behaviour' => ActorBehaviour::Wander,
                'speed' => 1.1,
                'x' => $level->spawn_x + sin($turn) * $spread,
                'z' => $level->spawn_z + cos($turn) * $spread,
                'elevation' => 0,
                'width' => 0.9,
                'depth' => 0.9,
                'height' => LevelAssets::HEIGHTS[$sprite] ?? 1.7,
                'angle' => 0,
                'is_solid' => false,
                'sort_order' => $index,
            ]);
        }
    }
}
