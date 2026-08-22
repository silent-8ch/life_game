<?php

namespace App\Filament\Resources\SupportTickets\Tables;

use App\Enums\TicketStatus;
use App\Models\SupportTicket;
use Filament\Actions\Action;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\ViewAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

/**
 * Every "this is wrong" anybody has raised.
 *
 * Open first and newest first, because a list of tickets is read to find the
 * ones nobody has dealt with. The level is a filter rather than only a column:
 * faults come in clusters, and three reports from the same staircase is the
 * shape of the thing worth looking at.
 */
class SupportTicketsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('created_at')
                    ->label('Raised')
                    ->since()
                    ->sortable(),
                TextColumn::make('level_slug')
                    ->label('Level')
                    ->searchable()
                    ->sortable()
                    // The level row may be gone; the slug still reads as a place.
                    ->description(fn (SupportTicket $ticket): ?string => $ticket->level === null
                        ? 'that level has since been deleted'
                        : null),
                TextColumn::make('standing_in')
                    ->label('Room')
                    ->placeholder('outside every room')
                    ->searchable(),
                TextColumn::make('note')
                    ->label('Said')
                    ->limit(70)
                    ->placeholder('nothing')
                    ->wrap(),
                TextColumn::make('user.name')
                    ->label('Reported by')
                    // Playing needs no account, so most of these are empty and
                    // that is not a fault.
                    ->placeholder('not signed in')
                    ->toggleable(),
                IconColumn::make('status')
                    ->label('Done')
                    ->boolean()
                    ->getStateUsing(fn (SupportTicket $ticket): bool => $ticket->status === TicketStatus::Resolved)
                    ->sortable(),
                TextColumn::make('shots_count')
                    ->label('Pictures')
                    ->counts('shots')
                    ->toggleable(),
            ])
            ->defaultSort('created_at', 'desc')
            ->filters([
                SelectFilter::make('status')
                    ->options([
                        TicketStatus::Open->value => 'Still open',
                        TicketStatus::Resolved->value => 'Dealt with',
                    ])
                    ->default(TicketStatus::Open->value),
                SelectFilter::make('level_slug')
                    ->label('Level')
                    ->options(fn (): array => SupportTicket::query()
                        ->distinct()
                        ->orderBy('level_slug')
                        ->pluck('level_slug', 'level_slug')
                        ->all()),
            ])
            ->recordActions([
                ViewAction::make(),
                Action::make('resolve')
                    ->label(fn (SupportTicket $ticket): string => $ticket->status === TicketStatus::Resolved
                        ? 'Reopen'
                        : 'Mark done')
                    ->icon(fn (SupportTicket $ticket): string => $ticket->status === TicketStatus::Resolved
                        ? 'heroicon-o-arrow-uturn-left'
                        : 'heroicon-o-check')
                    ->action(fn (SupportTicket $ticket) => $ticket->status === TicketStatus::Resolved
                        ? $ticket->reopen()
                        : $ticket->markResolved()),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ]);
    }
}
