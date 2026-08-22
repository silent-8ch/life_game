<?php

namespace Database\Factories;

use App\Models\Level;
use App\Models\LevelVertex;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<LevelVertex>
 */
class LevelVertexFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'level_id' => Level::factory(),
            'x' => fake()->randomFloat(2, 0, 16),
            'z' => fake()->randomFloat(2, 0, 16),
        ];
    }

    public function at(float $x, float $z): self
    {
        return $this->state(fn (): array => ['x' => $x, 'z' => $z]);
    }
}
