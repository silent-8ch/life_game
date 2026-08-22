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
 * @property float $floor_slope
 * @property int|null $floor_slope_edge
 * @property float $ceiling_slope
 * @property int|null $ceiling_slope_edge
 * @property string|null $floor_texture
 * @property string|null $ceiling_texture
 * @property string|null $wall_texture
 * @property bool $is_sky
 * @property bool $is_invisible
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
    'floor_slope',
    'floor_slope_edge',
    'ceiling_slope',
    'ceiling_slope_edge',
    'floor_texture',
    'ceiling_texture',
    'wall_texture',
    'is_sky',
    'is_invisible',
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
            'floor_slope' => 'float',
            'floor_slope_edge' => 'integer',
            'ceiling_slope' => 'float',
            'ceiling_slope_edge' => 'integer',
            'is_sky' => 'boolean',
            'is_invisible' => 'boolean',
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
     * How far it is from floor to ceiling, at a spot or at its shallowest.
     *
     * With both surfaces sloping the gap varies across the room, and the number
     * worth knowing is the smallest one. Both planes are flat by construction,
     * so the extremes of the difference between them are always at a corner —
     * sampling the corners is exact, not an approximation, and there is no need
     * to walk the interior.
     */
    public function headroom(?float $x = null, ?float $z = null): float
    {
        if ($x !== null && $z !== null) {
            return $this->ceilingAt($x, $z) - $this->floorAt($x, $z);
        }

        $least = null;

        foreach ($this->corners() as [$cornerX, $cornerZ]) {
            $gap = $this->ceilingAt($cornerX, $cornerZ) - $this->floorAt($cornerX, $cornerZ);
            $least = $least === null ? $gap : min($least, $gap);
        }

        return $least ?? $this->ceiling_height - $this->floor_height;
    }

    /**
     * How high the floor is at a spot on the plan.
     */
    public function floorAt(float $x, float $z): float
    {
        return $this->heightAt(
            $this->floor_height,
            $this->floor_slope,
            $this->floor_slope_edge,
            $x,
            $z,
        );
    }

    /**
     * How high the ceiling is at a spot on the plan.
     */
    public function ceilingAt(float $x, float $z): float
    {
        return $this->heightAt(
            $this->ceiling_height,
            $this->ceiling_slope,
            $this->ceiling_slope_edge,
            $x,
            $z,
        );
    }

    /**
     * The sector's corners in order, as [x, z] pairs.
     *
     * @return list<array{float, float}>
     */
    public function corners(): array
    {
        $corners = [];

        foreach ($this->edges as $edge) {
            $corners[] = [(float) $edge->vertex->x, (float) $edge->vertex->z];
        }

        return $corners;
    }

    /**
     * A sloped surface's height at a spot: the base along the hinge wall, plus
     * the rise times how far into the room the spot is.
     *
     * This is the PHP half of a pair — the TypeScript in engine/sectors.ts is
     * the other, and the two have to agree. Two copies is the established cost
     * here, the same as LevelAssets::HEIGHTS, and for the same reason: the
     * server validates what the engine will draw.
     */
    private function heightAt(float $base, float $slope, ?int $hinge, float $x, float $z): float
    {
        if ($slope === 0.0 || $hinge === null) {
            return $base;
        }

        $corners = $this->corners();
        $count = count($corners);

        if ($count < 3 || $hinge >= $count) {
            return $base;
        }

        [$fromX, $fromZ] = $corners[$hinge];
        [$toX, $toZ] = $corners[($hinge + 1) % $count];

        $spanX = $toX - $fromX;
        $spanZ = $toZ - $fromZ;
        $length = sqrt($spanX * $spanX + $spanZ * $spanZ);

        if ($length < 1e-9) {
            return $base;
        }

        // The inward normal, the same way engine/sectors.ts works it out: the
        // edge turned a quarter turn, then flipped to face the room's inside if
        // the corners were wound the other way.
        $normalX = -$spanZ / $length;
        $normalZ = $spanX / $length;

        if ($this->windsClockwise($corners)) {
            $normalX = -$normalX;
            $normalZ = -$normalZ;
        }

        $into = ($x - $fromX) * $normalX + ($z - $fromZ) * $normalZ;

        return $base + $slope * $into;
    }

    /**
     * @param  list<array{float, float}>  $corners
     */
    private function windsClockwise(array $corners): bool
    {
        $twiceArea = 0.0;
        $count = count($corners);

        for ($index = 0; $index < $count; $index++) {
            [$x, $z] = $corners[$index];
            [$nextX, $nextZ] = $corners[($index + 1) % $count];

            $twiceArea += $x * $nextZ - $nextX * $z;
        }

        return $twiceArea < 0;
    }
}
