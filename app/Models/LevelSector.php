<?php

namespace App\Models;

use Database\Factories\LevelSectorFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * A room: a closed polygon with its own floor and ceiling heights.
 *
 * @property int $id
 * @property int $level_id
 * @property string $slug
 * @property string $name
 * @property float $floor_height
 * @property float $ceiling_height
 * @property string|null $floor_texture
 * @property string|null $ceiling_texture
 * @property string|null $wall_texture
 * @property string|null $ambience
 * @property bool $is_sky
 * @property bool $is_water
 * @property int $sort_order
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Level $level
 * @property-read Collection<int, LevelSectorEdge> $edges
 */
#[Fillable([
    'level_id',
    'slug',
    'name',
    'floor_height',
    'ceiling_height',
    'floor_texture',
    'ceiling_texture',
    'wall_texture',
    'ambience',
    'is_sky',
    'is_water',
    'sort_order',
])]
class LevelSector extends Model
{
    /** @use HasFactory<LevelSectorFactory> */
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'floor_height' => 'float',
            'ceiling_height' => 'float',
            'is_sky' => 'boolean',
            'is_water' => 'boolean',
        ];
    }

    /**
     * @return BelongsTo<Level, $this>
     */
    public function level(): BelongsTo
    {
        return $this->belongsTo(Level::class);
    }

    /**
     * @return HasMany<LevelSectorEdge, $this>
     */
    public function edges(): HasMany
    {
        return $this->hasMany(LevelSectorEdge::class, 'sector_id')->orderBy('sort_order');
    }

    /**
     * How far it is from floor to ceiling.
     */
    public function headroom(): float
    {
        return $this->ceiling_height - $this->floor_height;
    }
}
