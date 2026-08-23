<?php

namespace App\Models;

use App\Enums\BindingResponse;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * What one thing does while one line is on, and while it is off.
 *
 * @property int $id
 * @property int $level_thing_id
 * @property string $line
 * @property BindingResponse $response
 * @property string $value_on
 * @property string $value_off
 * @property int $sort_order
 * @property-read LevelThing $levelThing
 */
#[Fillable([
    'line',
    'response',
    'value_on',
    'value_off',
    'sort_order',
])]
class LevelThingBinding extends Model
{
    /**
     * @return BelongsTo<LevelThing, $this>
     */
    public function levelThing(): BelongsTo
    {
        return $this->belongsTo(LevelThing::class);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'response' => BindingResponse::class,
        ];
    }
}
