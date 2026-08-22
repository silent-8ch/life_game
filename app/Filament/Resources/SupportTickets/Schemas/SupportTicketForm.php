<?php

namespace App\Filament\Resources\SupportTickets\Schemas;

use App\Models\SupportTicket;
use Filament\Forms\Components\Textarea;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

/**
 * One ticket, read.
 *
 * Almost everything here is a fact somebody's browser reported and nothing here
 * should be editable — a ticket is a record of what a player saw, and an
 * editable record of what somebody saw is worth much less. Only the note can be
 * changed, and only to add to it.
 *
 * The two things that make a ticket actionable rather than merely readable are
 * the address that stands you where they stood, and the boundaries that were
 * within reach — almost every fault reported in this project has turned out to
 * be at a room boundary, and that list is where it shows.
 */
class SupportTicketForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('What they said')->schema([
                Textarea::make('note')
                    ->label('Their words')
                    ->placeholder('They said nothing — the picture is the report.')
                    ->rows(3),
            ]),

            Section::make('Where they stood')
                ->description('Paste the address into the game to stand there yourself.')
                ->schema([
                    TextEntry::make('level_slug')->label('Level'),
                    TextEntry::make('standing_in_slug')
                        ->label('Room')
                        ->placeholder('outside every room'),
                    TextEntry::make('at')
                        ->label('Address')
                        ->state(fn (SupportTicket $ticket): string => sprintf(
                            '?level=%s&at=%s',
                            $ticket->level_slug,
                            $ticket->standingAt(),
                        ))
                        ->copyable()
                        ->helperText('x, z, yaw, pitch — the player\'s own yaw, not a spawn angle.'),
                    TextEntry::make('at_eye')
                        ->label('Eye height')
                        ->numeric(decimalPlaces: 2)
                        ->suffix(' m'),
                    TextEntry::make('looking_at')
                        ->label('Looking at')
                        ->placeholder('nothing in particular'),
                    TextEntry::make('holding')
                        ->label('Holding')
                        ->placeholder('nothing'),
                ])
                ->columns(2),

            Section::make('What was within reach')
                ->description('Almost every fault reported here has been at a room boundary.')
                ->schema([
                    TextEntry::make('nearby')
                        ->label('')
                        ->state(fn (SupportTicket $ticket): string => self::boundaries($ticket))
                        ->placeholder('No boundary was within reach.')
                        ->markdown(),
                ]),

            Section::make('Their screen')->schema([
                TextEntry::make('screen')
                    ->label('')
                    ->state(fn (SupportTicket $ticket): string => sprintf(
                        '%s x %s at %sx%s',
                        $ticket->screen['width'] ?? '?',
                        $ticket->screen['height'] ?? '?',
                        $ticket->screen['pixelRatio'] ?? '?',
                        ($ticket->screen['touch'] ?? false) ? ', on a touchscreen' : '',
                    )),
                TextEntry::make('is_running')
                    ->label('Moving')
                    ->state(fn (SupportTicket $ticket): string => $ticket->is_running
                        ? 'running'
                        : 'walking or standing still'),
            ])->columns(2),
        ]);
    }

    /**
     * The boundaries within reach, as a person would read them.
     */
    private static function boundaries(SupportTicket $ticket): string
    {
        $lines = [];

        foreach ($ticket->nearby as $edge) {
            if (! is_array($edge)) {
                continue;
            }

            /** @var array<string, mixed> $edge */
            $rooms = is_array($edge['rooms'] ?? null) ? $edge['rooms'] : [];

            $lines[] = sprintf(
                '- **%s cm** from %s — %s',
                number_format(((float) ($edge['distance'] ?? 0)) * 100, 1),
                implode(' | ', array_map('strval', $rooms)),
                ($edge['open'] ?? false) ? 'open' : 'blocked',
            );
        }

        return implode("\n", $lines);
    }
}
