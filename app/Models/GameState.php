<?php

namespace App\Models;

use Database\Factories\GameStateFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * The save file for one game. Each game has exactly one player.
 *
 * @property int $id
 * @property int $game_id
 * @property int|null $current_scene_id
 * @property int|null $current_level_id
 * @property string|null $last_message
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Game $game
 * @property-read Scene|null $currentScene
 * @property-read Level|null $currentLevel
 * @property-read Collection<int, Item> $items
 * @property-read Collection<int, GameFlag> $flags
 * @property-read Collection<int, Hotspot> $hotspotOverrides
 */
#[Fillable(['game_id', 'current_scene_id', 'current_level_id', 'last_message'])]
class GameState extends Model
{
    /** @use HasFactory<GameStateFactory> */
    use HasFactory;

    /**
     * Retrieve the game's save file, starting a new one where the game opens if needed.
     */
    public static function for(Game $game): self
    {
        return $game->state ?? $game->state()->create($game->isFirstPerson()
            ? ['current_level_id' => $game->openingLevel()->id]
            : ['current_scene_id' => $game->openingScene()->id]);
    }

    /**
     * @return BelongsTo<Game, $this>
     */
    public function game(): BelongsTo
    {
        return $this->belongsTo(Game::class);
    }

    /**
     * @return BelongsTo<Scene, $this>
     */
    public function currentScene(): BelongsTo
    {
        return $this->belongsTo(Scene::class, 'current_scene_id');
    }

    /**
     * @return BelongsTo<Level, $this>
     */
    public function currentLevel(): BelongsTo
    {
        return $this->belongsTo(Level::class, 'current_level_id');
    }

    /**
     * Where the player left off, whichever kind of game this is.
     */
    public function locationName(): ?string
    {
        return $this->currentScene->name ?? $this->currentLevel->name ?? null;
    }

    /**
     * @return BelongsToMany<Item, $this>
     */
    public function items(): BelongsToMany
    {
        return $this->belongsToMany(Item::class)->withTimestamps()->orderBy('items.name');
    }

    /**
     * @return HasMany<GameFlag, $this>
     */
    public function flags(): HasMany
    {
        return $this->hasMany(GameFlag::class);
    }

    /**
     * Hotspots whose visibility has been changed from its default by an interaction.
     *
     * @return BelongsToMany<Hotspot, $this>
     */
    public function hotspotOverrides(): BelongsToMany
    {
        return $this->belongsToMany(Hotspot::class)->withPivot('is_visible')->withTimestamps();
    }

    public function hasItem(string $slug): bool
    {
        return $this->items->contains('slug', $slug);
    }

    public function flagValue(string $key): ?string
    {
        return $this->flags->firstWhere('key', $key)?->value;
    }

    /**
     * The hotspots of the current scene the player is allowed to see right now.
     *
     * @return Collection<int, Hotspot>
     */
    public function visibleHotspots(): Collection
    {
        $hotspots = $this->currentScene->hotspots ?? new Collection;

        return $hotspots->filter(
            fn (Hotspot $hotspot): bool => $this->isHotspotVisible($hotspot)
        )->values();
    }

    public function isHotspotVisible(Hotspot $hotspot): bool
    {
        $override = $this->hotspotOverrides->firstWhere('id', $hotspot->id);

        if ($override === null || $override->pivot === null) {
            return $hotspot->is_visible_by_default;
        }

        return (bool) $override->pivot->getAttribute('is_visible');
    }

    /**
     * Return the save file to its opening state.
     */
    public function reset(): void
    {
        $this->items()->detach();
        $this->hotspotOverrides()->detach();
        $this->flags()->delete();

        $this->update($this->game->isFirstPerson()
            ? ['current_level_id' => $this->game->openingLevel()->id, 'last_message' => null]
            : ['current_scene_id' => $this->game->openingScene()->id, 'last_message' => null]);

        $this->unsetRelations();
    }
}
