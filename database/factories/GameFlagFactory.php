<?php

namespace Database\Factories;

use App\Models\GameFlag;
use App\Models\GameState;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<GameFlag>
 */
class GameFlagFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'game_state_id' => GameState::factory(),
            'key' => fake()->unique()->slug(2),
            'value' => 'yes',
        ];
    }
}
