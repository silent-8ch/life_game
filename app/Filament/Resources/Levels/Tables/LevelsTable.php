<?php

namespace App\Filament\Resources\Levels\Tables;

use App\Models\Level;
use Filament\Actions\Action;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class LevelsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')
                    ->searchable()
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
