<?php

namespace App\Services;

use App\Enums\EffectType;
use App\Models\GameState;
use App\Models\Hotspot;
use App\Models\Interaction;
use App\Models\InteractionEffect;
use App\Models\Item;
use App\Models\Scene;
use RuntimeException;

/**
 * Writes the consequences of a fired interaction back onto the save file.
 *
 * Every slug an effect names is resolved within the save file's own game, so two
 * games may reuse the same slugs without colliding.
 */
class EffectApplier
{
    public function apply(GameState $state, Interaction $interaction): void
    {
        $interaction->loadMissing('effects');

        foreach ($interaction->effects as $effect) {
            $this->applyEffect($state, $interaction, $effect);
        }

        $state->unsetRelations();
    }

    private function applyEffect(GameState $state, Interaction $interaction, InteractionEffect $effect): void
    {
        match ($effect->type) {
            EffectType::GiveItem => $state->items()->syncWithoutDetaching([$this->item($state, $effect)->id]),
            EffectType::RemoveItem => $state->items()->detach($this->item($state, $effect)->id),
            EffectType::SetFlag => $state->flags()->updateOrCreate(
                ['key' => $effect->subject],
                ['value' => (string) $effect->value],
            ),
            EffectType::MoveToScene => $state->update(['current_scene_id' => $this->scene($state, $effect->subject, $effect)->id]),
            EffectType::RevealHotspot => $this->setHotspotVisibility($state, $interaction, $effect, true),
            EffectType::HideHotspot => $this->setHotspotVisibility($state, $interaction, $effect, false),
        };
    }

    private function setHotspotVisibility(GameState $state, Interaction $interaction, InteractionEffect $effect, bool $isVisible): void
    {
        $hotspot = $this->hotspot($state, $interaction, $effect);

        $state->hotspotOverrides()->syncWithoutDetaching([$hotspot->id => ['is_visible' => $isVisible]]);
    }

    private function item(GameState $state, InteractionEffect $effect): Item
    {
        return Item::query()
            ->where('game_id', $state->game_id)
            ->where('slug', $effect->subject)
            ->firstOr(fn () => throw new RuntimeException("Effect {$effect->id} references unknown item [{$effect->subject}]."));
    }

    private function scene(GameState $state, string $slug, InteractionEffect $effect): Scene
    {
        return Scene::query()
            ->where('game_id', $state->game_id)
            ->where('slug', $slug)
            ->firstOr(fn () => throw new RuntimeException("Effect {$effect->id} references unknown scene [{$slug}]."));
    }

    /**
     * Hotspot slugs are unique per scene. A bare slug targets the scene the interaction
     * lives in; prefix it with `scene-slug/` to target a hotspot in another scene.
     */
    private function hotspot(GameState $state, Interaction $interaction, InteractionEffect $effect): Hotspot
    {
        if ($interaction->hotspot === null) {
            throw new RuntimeException(
                "Effect {$effect->id} targets a hotspot, but interaction {$interaction->id} does not belong to a scene."
            );
        }

        [$sceneSlug, $hotspotSlug] = str_contains($effect->subject, '/')
            ? explode('/', $effect->subject, 2)
            : [null, $effect->subject];

        $sceneId = $sceneSlug === null
            ? $interaction->hotspot->scene_id
            : $this->scene($state, $sceneSlug, $effect)->id;

        return Hotspot::query()
            ->where('scene_id', $sceneId)
            ->where('slug', $hotspotSlug)
            ->firstOr(fn () => throw new RuntimeException("Effect {$effect->id} references unknown hotspot [{$effect->subject}]."));
    }
}
