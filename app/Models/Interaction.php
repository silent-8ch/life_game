<?php

namespace App\Models;

use App\Enums\Verb;
use Database\Factories\InteractionFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * What a verb does to a hotspot or to a thing standing in a level, given the
 * conditions are met.
 *
 * Exactly one of the two owners is set. A scene game hangs these off a hotspot;
 * a first-person level hangs them off the thing the crosshair is resting on.
 *
 * @property int $id
 * @property int|null $hotspot_id
 * @property int|null $level_thing_id
 * @property Verb $verb
 * @property int|null $required_item_id
 * @property string $response
 * @property int $priority
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Hotspot|null $hotspot
 * @property-read LevelThing|null $levelThing
 * @property-read Item|null $requiredItem
 * @property-read Collection<int, InteractionCondition> $conditions
 * @property-read Collection<int, InteractionEffect> $effects
 */
#[Fillable(['hotspot_id', 'level_thing_id', 'verb', 'required_item_id', 'response', 'priority'])]
class Interaction extends Model
{
    /** @use HasFactory<InteractionFactory> */
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'verb' => Verb::class,
        ];
    }

    /**
     * @return BelongsTo<Hotspot, $this>
     */
    public function hotspot(): BelongsTo
    {
        return $this->belongsTo(Hotspot::class);
    }

    /**
     * @return BelongsTo<LevelThing, $this>
     */
    public function levelThing(): BelongsTo
    {
        return $this->belongsTo(LevelThing::class);
    }

    /**
     * @return BelongsTo<Item, $this>
     */
    public function requiredItem(): BelongsTo
    {
        return $this->belongsTo(Item::class, 'required_item_id');
    }

    /**
     * @return HasMany<InteractionCondition, $this>
     */
    public function conditions(): HasMany
    {
        return $this->hasMany(InteractionCondition::class);
    }

    /**
     * @return HasMany<InteractionEffect, $this>
     */
    public function effects(): HasMany
    {
        return $this->hasMany(InteractionEffect::class)->orderBy('sort_order');
    }
}
