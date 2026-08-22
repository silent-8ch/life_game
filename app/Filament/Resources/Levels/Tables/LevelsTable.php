<?php

namespace App\Filament\Resources\Levels\Tables;

use App\Models\Level;
use Filament\Actions\Action;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;

class LevelsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('owner.name')
                    ->label('Drawn by')
                    ->placeholder('Nobody')
                    ->sortable(),
                TextColumn::make('game.title')
                    ->label('Game')
                    ->searchable(),
                TextColumn::make('sectors_count')
                    ->label('Rooms')
                    ->counts('sectors')
                    ->sortable(),
                TextColumn::make('things_count')
                    ->label('Things')
                    ->counts('things')
                    ->sortable(),
                TextColumn::make('sky_image')
                    ->label('Sky')
                    ->placeholder('Indoors')
                    ->formatStateUsing(fn (?string $state): string => str_replace('sky-', '', $state ?? '')),
                TextColumn::make('backdrop_theme')
                    ->label('Horizon')
                    ->placeholder('None'),
                TextColumn::make('updated_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                // On by default, because a list of everybody's levels is the
                // thing this was added to stop. Orphans are somebody's work
                // too, so switching it off shows them rather than hiding them
                // behind a second control.
                TernaryFilter::make('mine')
                    ->label('Drawn by me')
                    ->placeholder('Everyone')
                    ->trueLabel('Only mine')
                    ->falseLabel('Only other people')
                    ->default(true)
                    ->queries(
                        true: fn (Builder $query): Builder => $query->where('owner_id', Auth::id()),
                        false: fn (Builder $query): Builder => $query->where(fn (Builder $q): Builder => $q
                            ->whereNot('owner_id', Auth::id())->orWhereNull('owner_id')),
                        blank: fn (Builder $query): Builder => $query,
                    ),
            ])
            ->recordActions([
                Action::make('map')
                    ->label('Edit map')
                    ->icon(Heroicon::OutlinedMap)
                    ->url(fn (Level $record): string => route('levels.editor', $record))
                    ->openUrlInNewTab(),
                Action::make('play')
                    ->label('Play')
                    ->icon(Heroicon::OutlinedPlay)
                    ->url(fn (Level $record): string => route('games.show', [
                        'game' => $record->game,
                        'level' => $record->slug,
                    ]))
                    ->openUrlInNewTab(),
                EditAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ])
            ->defaultSort('name');
    }
}
