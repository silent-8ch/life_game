<?php

namespace App\Models;

use App\Enums\TicketStatus;
use App\Services\SpotCapture;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * A player saying "this is wrong", from where they were standing.
 *
 * @property int $id
 * @property int $game_id
 * @property int|null $level_id
 * @property string $level_slug
 * @property int|null $user_id
 * @property string|null $note
 * @property float $at_x
 * @property float $at_z
 * @property float $at_eye
 * @property float $at_yaw
 * @property float $at_pitch
 * @property string|null $standing_in
 * @property string|null $looking_at
 * @property string|null $holding
 * @property bool $is_running
 * @property array<string, mixed> $screen
 * @property array<int, mixed> $nearby
 * @property array<int, mixed>|null $legend
 * @property TicketStatus $status
 * @property Carbon|null $resolved_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Game $game
 * @property-read Level|null $level
 * @property-read User|null $user
 * @property-read Collection<int, SupportTicketShot> $shots
 */
#[Fillable([
    'game_id',
    'level_id',
    'level_slug',
    'user_id',
    'note',
    'at_x',
    'at_z',
    'at_eye',
    'at_yaw',
    'at_pitch',
    'standing_in',
    'looking_at',
    'holding',
    'is_running',
    'screen',
    'nearby',
    'legend',
    'status',
    'resolved_at',
])]
class SupportTicket extends Model
{
    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'at_x' => 'float',
            'at_z' => 'float',
            'at_eye' => 'float',
            'at_yaw' => 'float',
            'at_pitch' => 'float',
            'is_running' => 'boolean',
            'screen' => 'array',
            'nearby' => 'array',
            'legend' => 'array',
            'status' => TicketStatus::class,
            'resolved_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Game, $this>
     */
    public function game(): BelongsTo
    {
        return $this->belongsTo(Game::class);
    }

    /**
     * The level it happened in, or null once that level has been deleted. The
     * slug is kept beside it so the place still reads as somewhere.
     *
     * @return BelongsTo<Level, $this>
     */
    public function level(): BelongsTo
    {
        return $this->belongsTo(Level::class);
    }

    /**
     * Who reported it, or null. Playing does not require an account — only the
     * editor and the admin panel sit behind a login.
     *
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return HasMany<SupportTicketShot, $this>
     */
    public function shots(): HasMany
    {
        return $this->hasMany(SupportTicketShot::class);
    }

    /**
     * Where the reporter was, spelled out the way `?at=` takes it, so a ticket
     * can be stood on again by pasting it into the address bar.
     */
    public function standingAt(): string
    {
        return sprintf(
            '%s,%s,%s,%s',
            round($this->at_x, 4),
            round($this->at_z, 4),
            round($this->at_yaw, 2),
            round($this->at_pitch, 2),
        );
    }

    /**
     * Where this ticket's pictures and its legend live.
     */
    public function folder(): string
    {
        return "support-tickets/{$this->id}";
    }

    public function markResolved(): void
    {
        $this->update([
            'status' => TicketStatus::Resolved,
            'resolved_at' => now(),
        ]);
    }

    public function reopen(): void
    {
        $this->update([
            'status' => TicketStatus::Open,
            'resolved_at' => null,
        ]);
    }

    protected static function booted(): void
    {
        // The rows go with the ticket by foreign key; the files on disk do not,
        // and nothing else would ever tidy them.
        static::deleting(function (self $ticket): void {
            app(SpotCapture::class)->forget($ticket->folder());
        });
    }
}
