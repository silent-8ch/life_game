<?php

namespace Database\Factories;

use App\Enums\ConditionType;
use App\Models\Interaction;
use App\Models\InteractionCondition;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<InteractionCondition>
 */
class InteractionConditionFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'interaction_id' => Interaction::factory(),
            'type' => ConditionType::HasItem,
            'subject' => fake()->slug(2),
            'value' => null,
        ];
    }
}
