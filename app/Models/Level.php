<?php

namespace App\Models;

use Database\Factories\LevelFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * A room you walk around in. Its slug is unique within its game, not globally.
 *
 * @property int $id
 * @property int $game_id
 * @property string $slug
 * @property string $name
 * @property string $description
 * @property string $player_sprite
 * @property string $sprite_style
 * @property float $spawn_x
 * @property float $spawn_z
 * @property float $spawn_angle
 * @property float $ceiling_height
 * @property string|null $sky_image
 * @property string|null $backdrop_theme
 * @property list<int>|null $backdrop_layers
 * @property string $wall_color
 * @property string $floor_color
 * @property string $accent_color
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Game $game
 * @property-read Collection<int, LevelVertex> $vertices
 * @property-read Collection<int, LevelSector> $sectors
 * @property-read Collection<int, LevelThing> $things
 */
#[Fillable([
    'game_id',
    'slug',
    'name',
    'description',
    'player_sprite',
    'sprite_style',
    'spawn_x',
    'spawn_z',
    'spawn_angle',
    'ceiling_height',
    'sky_image',
    'backdrop_theme',
    'backdrop_layers',
    'wall_color',
    'floor_color',
    'accent_color',
    'owner_id',
])]
class Level extends Model
{
    /** @use HasFactory<LevelFactory> */
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'spawn_x' => 'float',
            'spawn_z' => 'float',
            'spawn_angle' => 'float',
            'ceiling_height' => 'float',
            'backdrop_layers' => 'array',
        ];
    }

    /**
     * Who drew it, or null if it predates anybody having an account.
     *
     * @return BelongsTo<User, $this>
     */
    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_id');
    }

    /**
     * Levels a person may edit: their own, and every orphan.
     *
     * An orphan is unclaimed rather than protected — the levels that existed
     * before accounts did belong to nobody, and locking them would strand the
     * work rather than look after it.
     *
     * @param  Builder<Level>  $query
     */
    #[Scope]
    protected function editableBy(Builder $query, User $user): void
    {
        $query->where(function (Builder $inner) use ($user): void {
            $inner->where('owner_id', $user->id)->orWhereNull('owner_id');
        });
    }

    /**
     * @return BelongsTo<Game, $this>
     */
    public function game(): BelongsTo
    {
        return $this->belongsTo(Game::class);
    }

    /**
     * @return HasMany<LevelVertex, $this>
     */
    public function vertices(): HasMany
    {
        return $this->hasMany(LevelVertex::class);
    }

    /**
     * @return HasMany<LevelSector, $this>
     */
    public function sectors(): HasMany
    {
        return $this->hasMany(LevelSector::class)->orderBy('sort_order');
    }

    /**
     * @return HasMany<LevelThing, $this>
     */
    public function things(): HasMany
    {
        return $this->hasMany(LevelThing::class)->orderBy('sort_order');
    }

    /**
     * The lines drawn between the things in this level.
     *
     * @return HasMany<LevelActionLine, $this>
     */
    public function actionLines(): HasMany
    {
        return $this->hasMany(LevelActionLine::class);
    }
}
