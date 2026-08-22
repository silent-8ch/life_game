<?php

namespace App\Models;

use Database\Factories\LevelSectorEdgeFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One side of a sector's polygon, running from its vertex to the next one along.
 *
 * @property int $id
 * @property int $sector_id
 * @property int $vertex_id
 * @property int $sort_order
 * @property string|null $wall_texture
 * @property bool $blocks
 * @property bool $is_mirror
 * @property bool $is_sky
 * @property string|null $portal_link
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read LevelSector $sector
 * @property-read LevelVertex $vertex
 */
#[Fillable(['sector_id', 'vertex_id', 'sort_order', 'wall_texture', 'blocks', 'is_mirror', 'is_sky', 'portal_link'])]
class LevelSectorEdge extends Model
{
    /** @use HasFactory<LevelSectorEdgeFactory> */
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'blocks' => 'boolean',
            'is_mirror' => 'boolean',
            'is_sky' => 'boolean',
        ];
    }

    /**
     * @return BelongsTo<LevelSector, $this>
     */
    public function sector(): BelongsTo
    {
        return $this->belongsTo(LevelSector::class, 'sector_id');
    }

    /**
     * @return BelongsTo<LevelVertex, $this>
     */
    public function vertex(): BelongsTo
    {
        return $this->belongsTo(LevelVertex::class, 'vertex_id');
    }
}
