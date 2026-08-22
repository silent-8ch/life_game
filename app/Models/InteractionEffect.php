<?php

namespace App\Models;

use App\Enums\EffectType;
use Database\Factories\InteractionEffectFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A change its interaction writes back onto the save file.
 *
 * @property int $id
 * @property int $interaction_id
 * @property EffectType $type
 * @property string $subject
 * @property string|null $value
 * @property int $sort_order
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Interaction $interaction
 */
#[Fillable(['interaction_id', 'type', 'subject', 'value', 'sort_order'])]
class InteractionEffect extends Model
{
    /** @use HasFactory<InteractionEffectFactory> */
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'type' => EffectType::class,
        ];
    }

    /**
     * @return BelongsTo<Interaction, $this>
     */
    public function interaction(): BelongsTo
    {
        return $this->belongsTo(Interaction::class);
    }
}
