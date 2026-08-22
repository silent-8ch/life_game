<?php

namespace App\Models;

use App\Enums\ConditionType;
use Database\Factories\InteractionConditionFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A requirement that must hold before its interaction may fire.
 *
 * @property int $id
 * @property int $interaction_id
 * @property ConditionType $type
 * @property string $subject
 * @property string|null $value
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Interaction $interaction
 */
#[Fillable(['interaction_id', 'type', 'subject', 'value'])]
class InteractionCondition extends Model
{
    /** @use HasFactory<InteractionConditionFactory> */
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'type' => ConditionType::class,
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
