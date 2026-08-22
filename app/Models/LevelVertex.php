<?php

namespace App\Models;

use Database\Factories\LevelVertexFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * A corner on the floor plan, shared by every sector that meets there.
 *
 * @property int $id
 * @property int $level_id
 * @property float $x
 * @property float $z
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Level $level
 * @property-read Collection<int, LevelSectorEdge> $edges
 */
#[Fillable(['level_id', 'x', 'z'])]
class LevelVertex extends Model
{
    /** @use HasFactory<LevelVertexFactory> */
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'x' => 'float',
            'z' => 'float',
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
        return $this->hasMany(LevelSectorEdge::class, 'vertex_id');
    }
}
