<?php

namespace App\Models;

use Database\Factories\HotspotFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\Pivot;
use Illuminate\Support\Carbon;

/**
 * A clickable region of a scene.
 *
 * @property int $id
 * @property int $scene_id
 * @property string $slug
 * @property string $name
 * @property int $x
 * @property int $y
 * @property int $width
 * @property int $height
 * @property bool $is_visible_by_default
 * @property int $sort_order
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Scene $scene
 * @property-read Collection<int, Interaction> $interactions
 * @property-read Pivot|null $pivot
 */
#[Fillable([
    'scene_id',
    'slug',
    'name',
    'x',
    'y',
    'width',
    'height',
    'is_visible_by_default',
    'sort_order',
])]
class Hotspot extends Model
{
    /** @use HasFactory<HotspotFactory> */
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_visible_by_default' => 'boolean',
        ];
    }

    /**
     * @return BelongsTo<Scene, $this>
     */
    public function scene(): BelongsTo
    {
        return $this->belongsTo(Scene::class);
    }

    /**
     * @return HasMany<Interaction, $this>
     */
    public function interactions(): HasMany
    {
        return $this->hasMany(Interaction::class);
    }
}
