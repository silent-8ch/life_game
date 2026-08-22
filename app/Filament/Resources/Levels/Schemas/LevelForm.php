<?php

namespace App\Filament\Resources\Levels\Schemas;

use App\Models\Game;
use App\Services\LevelAssets;
use App\Services\LevelStarter;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Unique;

/**
 * Everything about a level except its shape. The shape is drawn in the map
 * editor, which is a button away on the list.
 */
class LevelForm
{
    public static function configure(Schema $schema): Schema
    {
        $assets = app(LevelAssets::class);

        $game = Game::query()->orderByDesc('id')->value('id');
        $fresh = app(LevelStarter::class)->freeName($game);

        return $schema
            ->components([
                Section::make('Level')
                    ->columns(2)
                    ->schema([
                        Select::make('game_id')
                            ->relationship('game', 'title')
                            ->default($game)
                            ->required(),

                        // Nullable on purpose. A level with nobody against it
                        // is an orphan, which is a real state — everything
                        // drawn before there were accounts is one, and an
                        // orphan stays editable by anybody rather than being
                        // locked away from everybody.
                        Select::make('owner_id')
                            ->label('Drawn by')
                            ->relationship('owner', 'name')
                            ->placeholder('Nobody')
                            ->searchable()
                            ->preload(),
                        TextInput::make('name')
                            ->required()
                            ->default($fresh['name'])
                            ->live(onBlur: true)
                            // Only while creating, so renaming a level later
                            // cannot quietly break the links that use its slug.
                            ->afterStateUpdated(fn (Set $set, ?string $state, string $operation): mixed => $operation === 'create'
                                ? $set('slug', Str::slug($state ?? ''))
                                : null),
                        TextInput::make('slug')
                            ->required()
                            ->default($fresh['slug'])
                            ->unique(
                                ignoreRecord: true,
                                modifyRuleUsing: fn (Unique $rule, Get $get): Unique => $rule
                                    ->where('game_id', $get('game_id')),
                            )
                            ->helperText('Unique within its game.'),
                        TextInput::make('ceiling_height')
                            ->numeric()
                            ->required()
                            ->default(3)
                            ->suffix('m')
                            ->helperText('The height new sectors start at.'),
                        Textarea::make('description')
                            ->required()
                            ->default('A level waiting to be drawn.')
                            ->columnSpanFull(),
                    ]),

                Section::make('Where the player starts')
                    ->columns(3)
                    ->schema([
                        TextInput::make('spawn_x')->numeric()->required()->default(0)->suffix('m'),
                        TextInput::make('spawn_z')->numeric()->required()->default(0)->suffix('m'),
                        TextInput::make('spawn_angle')
                            ->numeric()
                            ->required()
                            ->default(0)
                            ->suffix('°')
                            ->helperText('0 faces north, 90 faces east.'),
                    ]),

                Section::make('Sky')
                    ->description('Shown by any sector marked as open to the sky.')
                    ->columns(2)
                    ->schema([
                        Select::make('sky_image')
                            ->label('Sky')
                            ->options(fn (): array => array_combine(
                                $assets->skies(),
                                array_map(
                                    fn (string $sky): string => Str::headline(Str::after($sky, 'sky-')),
                                    $assets->skies(),
                                ),
                            ))
                            ->placeholder('No sky')
                            ->default('sky-day')
                            ->live(),
                        Select::make('sky_variant')
                            ->label('Variant')
                            ->options([0 => 'One', 1 => 'Two', 2 => 'Three', 3 => 'Four'])
                            ->default(0)
                            ->required(),
                        Select::make('backdrop_theme')
                            ->label('Horizon')
                            ->options(fn (): array => array_combine(
                                array_keys($assets->backdrops()),
                                array_map(
                                    fn (string $theme): string => Str::headline($theme),
                                    array_keys($assets->backdrops()),
                                ),
                            ))
                            ->placeholder('Bare sky')
                            ->default('hills')
                            ->live(),
                        CheckboxList::make('backdrop_layers')
                            ->label('Layers')
                            ->options([1 => 'Far', 2 => 'Middle', 3 => 'Near'])
                            ->columns(3)
                            ->default([1, 2, 3])
                            ->helperText('Stacked furthest first; nearer layers drift more as you walk.'),
                    ]),

                Section::make('Wireframe colours')
                    ->description('Used for any surface that has no texture yet.')
                    ->columns(3)
                    ->collapsed()
                    ->schema([
                        TextInput::make('wall_color')->required()->default('#7fe0c9'),
                        TextInput::make('floor_color')->required()->default('#2f6f5e'),
                        TextInput::make('accent_color')->required()->default('#fbbf24'),
                    ]),
            ]);
    }
}
