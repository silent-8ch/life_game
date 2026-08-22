<?php

namespace Database\Factories;

use App\Models\Game;
use App\Models\Scene;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Scene>
 */
class SceneFactory extends Factory
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
            'background_image' => null,
            'background_color' => fake()->hexColor(),
        ];
    }
}
