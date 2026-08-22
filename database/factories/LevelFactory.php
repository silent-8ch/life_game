<?php

namespace Database\Factories;

use App\Models\Game;
use App\Models\Level;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Level>
 */
class LevelFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $name = fake()->unique()->word();

        return [
            'game_id' => Game::factory(),
            'slug' => Str::slug($name),
            'name' => Str::title($name),
            'description' => fake()->sentence(),
            'spawn_x' => 0.0,
            'spawn_z' => 0.0,
            'spawn_angle' => 0.0,
            'ceiling_height' => 3.0,
            'wall_color' => fake()->hexColor(),
            'floor_color' => fake()->hexColor(),
            'accent_color' => fake()->hexColor(),
        ];
    }
}
