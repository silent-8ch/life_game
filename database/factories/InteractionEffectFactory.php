<?php

namespace Database\Factories;

use App\Enums\EffectType;
use App\Models\Interaction;
use App\Models\InteractionEffect;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<InteractionEffect>
 */
class InteractionEffectFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'interaction_id' => Interaction::factory(),
            'type' => EffectType::SetFlag,
            'subject' => fake()->slug(2),
            'value' => 'yes',
            'sort_order' => 0,
        ];
    }
}
