<?php

namespace Database\Factories;

use App\Models\Level;
use App\Models\LevelSector;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<LevelSector>
 */
class LevelSectorFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $name = fake()->unique()->word();

        return [
            'level_id' => Level::factory(),
            'slug' => Str::slug($name),
            'name' => Str::title($name),
            'floor_height' => 0.0,
            'ceiling_height' => 3.0,
            'floor_texture' => null,
            'ceiling_texture' => null,
            'wall_texture' => null,
            'ambience' => null,
            'is_sky' => false,
            'is_water' => false,
            'sort_order' => 0,
        ];
    }

    public function openToTheSky(): self
    {
        return $this->state(fn (): array => ['is_sky' => true]);
    }

    public function flooded(): self
    {
        return $this->state(fn (): array => ['is_water' => true]);
    }
}
