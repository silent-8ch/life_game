<?php

namespace App\Filament\Resources\Users\Tables;

use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class UsersTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('email')
                    ->label('Signs in as')
                    ->searchable(),
                TextColumn::make('levels_count')
                    ->label('Levels drawn')
                    ->counts('levels')
                    ->sortable(),
                TextColumn::make('created_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->defaultSort('name')
            ->recordActions([EditAction::make()])
            ->toolbarActions([
                // Deleting a person orphans their levels rather than taking
                // the levels with them — `nullOnDelete` on the column, and it
                // matters because the work outlasts the account.
                BulkActionGroup::make([DeleteBulkAction::make()]),
            ]);
    }
}
