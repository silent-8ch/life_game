<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One picture belonging to a ticket.
 *
 * A row apiece rather than columns on the ticket, because which pictures a
 * ticket carries is a thing that will change. Today the client sends three —
 * the ordinary frame, a wireframe, and the colour-coded walls the debug view
 * paints, which is what lets a ticket name the wall that is wrong rather than
 * only showing it. A fourth, or a ticket that only manages one, is a row count
 * rather than a migration.
 *
 * The file sits on the **local** disk. These are bytes posted by whoever was
 * playing, and playing needs no account, so they are never served from
 * `public/` — the admin panel reads them through Laravel.
 *
 * @property int $id
 * @property int $support_ticket_id
 * @property string $kind
 * @property string $path
 * @property int $bytes
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read SupportTicket $ticket
 */
#[Fillable(['support_ticket_id', 'kind', 'path', 'bytes'])]
class SupportTicketShot extends Model
{
    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['bytes' => 'integer'];
    }

    /**
     * @return BelongsTo<SupportTicket, $this>
     */
    public function ticket(): BelongsTo
    {
        return $this->belongsTo(SupportTicket::class, 'support_ticket_id');
    }
}
