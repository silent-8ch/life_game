<?php

namespace App\Models;

use Database\Factories\SceneFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * A room. Its slug is unique within its game, not globally.
 *
 * @property int $id
 * @property int $game_id
 * @property string $slug
 * @property string $name
 * @property string $description
 * @property string|null $background_image
 * @property string $background_color
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Game $game
 * @property-read Collection<int, Hotspot> $hotspots
 */
#[Fillable(['game_id', 'slug', 'name', 'description', 'background_image', 'background_color'])]
class Scene extends Model
{
    /** @use HasFactory<SceneFactory> */
    use HasFactory;

    /**
     * @return BelongsTo<Game, $this>
     */
    public function game(): BelongsTo
    {
        return $this->belongsTo(Game::class);
    }

    /**
     * @return HasMany<Hotspot, $this>
     */
    public function hotspots(): HasMany
    {
        return $this->hasMany(Hotspot::class)->orderBy('sort_order');
    }
}
