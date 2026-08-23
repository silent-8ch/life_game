<?php

namespace App\Models;

use App\Enums\ThingHinge;
use App\Enums\ThingKind;
use App\Enums\ThingRender;
use App\Enums\ThingUvMode;
use App\Services\PersonStats;
use Database\Factories\LevelThingFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\Pivot;
use Illuminate\Support\Carbon;

/**
 * A box in a level: furniture, a door, a window. Its slug is unique within its level.
 *
 * @property int $id
 * @property int $level_id
 * @property string $slug
 * @property string $name
 * @property string $description
 * @property ThingKind $kind
 * @property string|null $sprite
 * @property string|null $behaviour
 * @property array<string, int>|null $stats
 * @property float $speed
 * @property string|null $texture
 * @property ThingRender $render
 * @property ThingHinge|null $hinge
 * @property-read Pivot|null $pivot
 * @property int $plane_count
 * @property ThingUvMode $uv_mode
 * @property string|null $texture_alt
 * @property string|null $alt_flag
 * @property int $animation_frames
 * @property float $animation_fps
 * @property float $x
 * @property float $z
 * @property float $elevation
 * @property float $width
 * @property float $depth
 * @property float $height
 * @property float $angle
 * @property bool $is_solid
 * @property int $sort_order
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Level $level
 * @property-read Collection<int, Interaction> $interactions
 */
#[Fillable([
    'level_id',
    'slug',
    'name',
    'description',
    'kind',
    'sprite',
    'behaviour',
    'stats',
    'speed',
    'texture',
    'render',
    'plane_count',
    'uv_mode',
    'texture_alt',
    'alt_flag',
    'animation_frames',
    'animation_fps',
    'x',
    'z',
    'elevation',
    'width',
    'depth',
    'height',
    'angle',
    'hinge',
    'is_solid',
    'sort_order',
])]
class LevelThing extends Model
{
    /** @use HasFactory<LevelThingFactory> */
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'kind' => ThingKind::class,
            'render' => ThingRender::class,
            'hinge' => ThingHinge::class,
            'uv_mode' => ThingUvMode::class,
            'stats' => 'array',
            'plane_count' => 'integer',
            'animation_frames' => 'integer',
            'animation_fps' => 'float',
            'speed' => 'float',
            'x' => 'float',
            'z' => 'float',
            'elevation' => 'float',
            'width' => 'float',
            'depth' => 'float',
            'height' => 'float',
            'angle' => 'float',
            'is_solid' => 'boolean',
        ];
    }

    /**
     * What this person is made of. Their own block if they were given one,
     * and otherwise whatever their sprite starts with — all or nothing, never
     * a half of each.
     *
     * @return array<string, int>
     */
    public function stats(): array
    {
        return $this->stats ?? app(PersonStats::class)->for($this->sprite);
    }

    /**
     * @return BelongsTo<Level, $this>
     */
    public function level(): BelongsTo
    {
        return $this->belongsTo(Level::class);
    }

    /**
     * What the player can do to it, and what happens when they do.
     *
     * @return HasMany<Interaction, $this>
     */
    public function interactions(): HasMany
    {
        return $this->hasMany(Interaction::class);
    }
}
