<?php

namespace Database\Factories;

use App\Enums\Verb;
use App\Models\Hotspot;
use App\Models\Interaction;
use App\Models\Item;
use App\Models\LevelThing;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Interaction>
 */
class InteractionFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'hotspot_id' => Hotspot::factory(),
            'verb' => Verb::Look,
            'required_item_id' => null,
            'response' => fake()->sentence(),
            'priority' => 0,
        ];
    }

    /** Hung on a thing standing in a level rather than on a hotspot. */
    public function on(LevelThing $thing): self
    {
        return $this->state([
            'hotspot_id' => null,
            'level_thing_id' => $thing->id,
        ]);
    }

    public function verb(Verb $verb): self
    {
        return $this->state(['verb' => $verb]);
    }

    public function requiring(Item $item): self
    {
        return $this->state(['verb' => Verb::Use, 'required_item_id' => $item->id]);
    }
}
