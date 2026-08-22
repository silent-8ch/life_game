<?php

namespace Database\Factories;

use App\Models\LevelSector;
use App\Models\LevelSectorEdge;
use App\Models\LevelVertex;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<LevelSectorEdge>
 */
class LevelSectorEdgeFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'sector_id' => LevelSector::factory(),
            'vertex_id' => LevelVertex::factory(),
            'sort_order' => 0,
            'wall_texture' => null,
            'blocks' => false,
            'is_mirror' => false,
        ];
    }

    public function blocking(): self
    {
        return $this->state(fn (): array => ['blocks' => true]);
    }

    public function mirrored(): self
    {
        return $this->state(fn (): array => ['is_mirror' => true]);
    }
}
