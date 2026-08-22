<?php

namespace Database\Factories;

use App\Models\Hotspot;
use App\Models\Scene;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Hotspot>
 */
class HotspotFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $name = fake()->unique()->word();

        return [
            'scene_id' => Scene::factory(),
            'slug' => Str::slug($name),
            'name' => Str::title($name),
            'x' => fake()->numberBetween(0, 70),
            'y' => fake()->numberBetween(0, 70),
            'width' => fake()->numberBetween(5, 30),
            'height' => fake()->numberBetween(5, 30),
            'is_visible_by_default' => true,
            'sort_order' => 0,
        ];
    }

    public function hidden(): self
    {
        return $this->state(['is_visible_by_default' => false]);
    }
}
