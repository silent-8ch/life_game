<?php

namespace Database\Factories;

use App\Models\Game;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Game>
 */
class GameFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $title = fake()->unique()->word();

        return [
            'starting_scene_id' => null,
            'slug' => Str::slug($title),
            'title' => Str::title($title),
            'tagline' => fake()->sentence(),
            'cover_image' => null,
            'is_published' => true,
            'sort_order' => 0,
        ];
    }

    public function unpublished(): self
    {
        return $this->state(['is_published' => false]);
    }
}
