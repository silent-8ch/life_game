<?php

namespace App\Services;

use App\Enums\ConditionType;
use App\Enums\Verb;
use App\Models\GameState;
use App\Models\Hotspot;
use App\Models\Interaction;
use App\Models\InteractionCondition;
use App\Models\Item;
use App\Models\LevelThing;

/**
 * Picks the interaction that should fire for a given verb, subject, and
 * inventory item.
 *
 * The subject is a hotspot in a scene game and a thing standing in a room in a
 * first-person one. Nothing below cares which: both own a list of interactions
 * and that is all this needs.
 */
class InteractionResolver
{
    /**
     * The highest priority interaction whose conditions are all satisfied, if any.
     */
    public function resolve(GameState $state, Hotspot|LevelThing $subject, Verb $verb, ?Item $item = null): ?Interaction
    {
        $state->loadMissing(['items', 'flags']);

        return $subject->interactions()
            ->with(['conditions', 'effects'])
            ->where('verb', $verb)
            ->where('required_item_id', $item?->id)
            ->orderByDesc('priority')
            ->orderBy('id')
            ->get()
            ->first(fn (Interaction $interaction): bool => $this->conditionsAreMet($state, $interaction));
    }

    private function conditionsAreMet(GameState $state, Interaction $interaction): bool
    {
        return $interaction->conditions->every(
            fn (InteractionCondition $condition): bool => $this->isMet($state, $condition)
        );
    }

    private function isMet(GameState $state, InteractionCondition $condition): bool
    {
        return match ($condition->type) {
            ConditionType::HasItem => $state->hasItem($condition->subject),
            ConditionType::MissingItem => ! $state->hasItem($condition->subject),
            ConditionType::FlagIs => $state->flagValue($condition->subject) === $condition->value,
            ConditionType::FlagIsNot => $state->flagValue($condition->subject) !== $condition->value,
        };
    }
}
