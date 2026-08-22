<?php

namespace App\Models;

use Database\Factories\GameFlagFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A named value the story has recorded, such as `drawer_open`.
 *
 * @property int $id
 * @property int $game_state_id
 * @property string $key
 * @property string $value
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read GameState $gameState
 */
#[Fillable(['game_state_id', 'key', 'value'])]
class GameFlag extends Model
{
    /** @use HasFactory<GameFlagFactory> */
    use HasFactory;

    /**
     * @return BelongsTo<GameState, $this>
     */
    public function gameState(): BelongsTo
    {
        return $this->belongsTo(GameState::class);
    }
}
