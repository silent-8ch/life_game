<?php

namespace Database\Factories;

use App\Enums\ThingKind;
use App\Models\Level;
use App\Models\LevelThing;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<LevelThing>
 */
class LevelThingFactory extends Factory
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
            'description' => fake()->sentence(),
            'kind' => ThingKind::Prop,
            'x' => fake()->randomFloat(2, 0, 8),
            'z' => fake()->randomFloat(2, 0, 8),
            'elevation' => 0.0,
            'width' => 1.0,
            'depth' => 1.0,
            'height' => 1.0,
            'angle' => 0.0,
            'is_solid' => true,
            'sort_order' => 0,
        ];
    }

    public function kind(ThingKind $kind): self
    {
        return $this->state(fn (): array => ['kind' => $kind]);
    }
}
