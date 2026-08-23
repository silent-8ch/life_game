<?php

namespace App\Services;

use App\Enums\EffectType;
use App\Models\GameState;
use App\Models\Hotspot;
use App\Models\Interaction;
use App\Models\InteractionEffect;
use App\Models\Item;
use App\Models\LevelThing;
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
            EffectType::RotateThing => $this->overrideThing($state, $interaction, $effect, [
                'turned' => (float) $effect->value,
            ]),
            EffectType::SetBlocking => $this->overrideThing($state, $interaction, $effect, [
                'blocking' => filter_var($effect->value, FILTER_VALIDATE_BOOL),
            ]),
        };
    }

    private function setHotspotVisibility(GameState $state, Interaction $interaction, InteractionEffect $effect, bool $isVisible): void
    {
        $hotspot = $this->hotspot($state, $interaction, $effect);

        $state->hotspotOverrides()->syncWithoutDetaching([$hotspot->id => ['is_visible' => $isVisible]]);
    }

    /**
     * Records what has happened to a thing, without touching how it was drawn.
     *
     * `syncWithoutDetaching` rather than a replace, because the two effects are
     * authored separately and a door's Use fires both: turning it must not
     * forget that it stopped blocking, and stopping it blocking must not forget
     * the angle. One row per thing per save, each column written by whichever
     * effect owns it.
     *
     * @param  array{turned?: float, blocking?: bool}  $change
     */
    private function overrideThing(
        GameState $state,
        Interaction $interaction,
        InteractionEffect $effect,
        array $change,
    ): void {
        $state->thingOverrides()->syncWithoutDetaching([
            $this->thing($state, $interaction, $effect)->id => $change,
        ]);
    }

    /**
     * The thing an effect names, within the level the interaction belongs to.
     *
     * A bare slug means a thing in the same level, which is the only kind
     * anything has needed: a door's own Use turns the door. Resolved through the
     * interaction rather than through the save's current level, so an effect
     * fired on a level somebody has since walked out of still names the right
     * thing.
     */
    private function thing(GameState $state, Interaction $interaction, InteractionEffect $effect): LevelThing
    {
        $level = $interaction->levelThing?->level;

        if ($level === null) {
            throw new RuntimeException(
                "Effect {$effect->id} targets a thing, but interaction {$interaction->id} does not belong to a level."
            );
        }

        return LevelThing::query()
            ->where('level_id', $level->id)
            ->where('slug', $effect->subject)
            ->firstOr(fn () => throw new RuntimeException(
                "Effect {$effect->id} references unknown thing [{$effect->subject}]."
            ));
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
