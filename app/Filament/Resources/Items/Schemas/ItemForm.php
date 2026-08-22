<?php

namespace App\Filament\Resources\Items\Schemas;

use App\Models\Game;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Unique;

/**
 * Something the player can be carrying.
 *
 * Items are what an interaction hands over, takes away, or asks to see before
 * it will fire, and they are named by slug wherever they are used — in the map
 * editor's interaction panel and in the seeders both. So the slug is what has
 * to be unique, and only within its own game.
 */
class ItemForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Select::make('game_id')
                    ->label('Game')
                    ->relationship('game', 'title')
                    ->default(fn (): ?int => Game::query()->orderByDesc('id')->value('id'))
                    ->required(),
                TextInput::make('name')
                    ->required()
                    ->live(onBlur: true)
                    // Only while creating, so renaming an item later cannot
                    // quietly break the interactions that name it.
                    ->afterStateUpdated(fn (Set $set, ?string $state, string $operation): mixed => $operation === 'create'
                        ? $set('slug', Str::slug($state ?? ''))
                        : null),
                TextInput::make('slug')
                    ->required()
                    ->unique(
                        ignoreRecord: true,
                        modifyRuleUsing: fn (Unique $rule, Get $get): Unique => $rule
                            ->where('game_id', $get('game_id')),
                    )
                    ->helperText('Unique within its game. This is the name interactions use.'),
                Textarea::make('description')
                    ->required()
                    ->columnSpanFull(),
                TextInput::make('icon')
                    ->helperText('Optional image name for the inventory tray.'),
            ]);
    }
}
