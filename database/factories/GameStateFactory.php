<?php

namespace Database\Factories;

use App\Models\Game;
use App\Models\GameState;
use App\Models\Scene;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<GameState>
 */
class GameStateFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'game_id' => Game::factory(),
            'current_scene_id' => fn (array $attributes): int => Scene::factory()
                ->create(['game_id' => $attributes['game_id']])
                ->id,
            'last_message' => null,
        ];
    }
}
