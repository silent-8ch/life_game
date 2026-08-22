<?php

namespace App\Filament\Resources\SupportTickets;

use App\Enums\TicketStatus;
use App\Filament\Resources\SupportTickets\Pages\EditSupportTicket;
use App\Filament\Resources\SupportTickets\Pages\ListSupportTickets;
use App\Filament\Resources\SupportTickets\Schemas\SupportTicketForm;
use App\Filament\Resources\SupportTickets\Tables\SupportTicketsTable;
use App\Models\SupportTicket;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

/**
 * "This is wrong", raised by somebody playing.
 *
 * Read-only by design apart from the note. A ticket is a record of what a
 * player saw, and an editable record of what somebody saw is worth much less
 * than one nobody can quietly tidy.
 */
class SupportTicketResource extends Resource
{
    protected static ?string $model = SupportTicket::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedFlag;

    protected static ?string $recordTitleAttribute = 'level_slug';

    /**
     * Nobody raises a ticket from in here — they come from inside the game.
     */
    public static function canCreate(): bool
    {
        return false;
    }

    /**
     * Open ones, on the badge, so the panel says when there is something to
     * read without anybody going to look.
     */
    public static function getNavigationBadge(): ?string
    {
        $open = SupportTicket::query()
            ->where('status', TicketStatus::Open)
            ->count();

        return $open === 0 ? null : (string) $open;
    }

    public static function form(Schema $schema): Schema
    {
        return SupportTicketForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return SupportTicketsTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [
            //
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListSupportTickets::route('/'),
            'edit' => EditSupportTicket::route('/{record}/edit'),
        ];
    }
}
