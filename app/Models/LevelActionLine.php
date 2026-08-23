<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A line drawn by hand from one thing to another.
 *
 * The wire itself, and the whole of how two things are connected. A chain is
 * two of these and nobody types a name.
 *
 * @property int $id
 * @property int $level_id
 * @property int $from_thing_id
 * @property int $to_thing_id
 */
#[Fillable([
    'from_thing_id',
    'to_thing_id',
])]
class LevelActionLine extends Model
{
    /**
     * @return BelongsTo<Level, $this>
     */
    public function level(): BelongsTo
    {
        return $this->belongsTo(Level::class);
    }

    /**
     * @return BelongsTo<LevelThing, $this>
     */
    public function from(): BelongsTo
    {
        return $this->belongsTo(LevelThing::class, 'from_thing_id');
    }

    /**
     * @return BelongsTo<LevelThing, $this>
     */
    public function to(): BelongsTo
    {
        return $this->belongsTo(LevelThing::class, 'to_thing_id');
    }
}
