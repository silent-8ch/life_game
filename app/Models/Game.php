<?php

namespace App\Models;

use Database\Factories\GameFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\RouteKey;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Carbon;

/**
 * One adventure. Everything authored — scenes, items, interactions — belongs to a game.
 *
 * @property int $id
 * @property int|null $starting_scene_id
 * @property int|null $starting_level_id
 * @property string $slug
 * @property string $title
 * @property string $tagline
 * @property string|null $cover_image
 * @property bool $is_published
 * @property int $sort_order
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Scene|null $startingScene
 * @property-read Level|null $startingLevel
 * @property-read Collection<int, Scene> $scenes
 * @property-read Collection<int, Level> $levels
 * @property-read Collection<int, Item> $items
 * @property-read GameState|null $state
 */
#[RouteKey('slug')]
#[Fillable([
    'starting_scene_id',
    'starting_level_id',
    'slug',
    'title',
    'tagline',
    'cover_image',
    'is_published',
    'sort_order',
])]
class Game extends Model
{
    /** @use HasFactory<GameFactory> */
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_published' => 'boolean',
        ];
    }

    /**
     * @return BelongsTo<Scene, $this>
     */
    public function startingScene(): BelongsTo
    {
        return $this->belongsTo(Scene::class, 'starting_scene_id');
    }

    /**
     * @return BelongsTo<Level, $this>
     */
    public function startingLevel(): BelongsTo
    {
        return $this->belongsTo(Level::class, 'starting_level_id');
    }

    /**
     * @return HasMany<Scene, $this>
     */
    public function scenes(): HasMany
    {
        return $this->hasMany(Scene::class);
    }

    /**
     * @return HasMany<Level, $this>
     */
    public function levels(): HasMany
    {
        return $this->hasMany(Level::class);
    }

    /**
     * @return HasMany<Item, $this>
     */
    public function items(): HasMany
    {
        return $this->hasMany(Item::class);
    }

    /**
     * @return HasOne<GameState, $this>
     */
    public function state(): HasOne
    {
        return $this->hasOne(GameState::class);
    }

    /**
     * The scene the game opens in: the authored one, or the first scene created.
     */
    public function openingScene(): Scene
    {
        return $this->startingScene ?? $this->scenes()->orderBy('id')->firstOrFail();
    }

    /**
     * The level the game opens in: the authored one, or the first level created.
     */
    public function openingLevel(): Level
    {
        return $this->startingLevel ?? $this->levels()->orderBy('id')->firstOrFail();
    }

    /**
     * Whether the game is walked around in the first person rather than clicked through.
     */
    public function isFirstPerson(): bool
    {
        return $this->starting_level_id !== null || $this->levels()->exists();
    }

    /**
     * @param  Builder<Game>  $query
     */
    #[Scope]
    protected function published(Builder $query): void
    {
        $query->where('is_published', true);
    }
}
