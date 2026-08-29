<?php

namespace App\Filament\Resources\Levels\Schemas;

use App\Models\Game;
use App\Services\LevelAssets;
use App\Services\LevelStarter;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Html;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\HtmlString;
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

                        // Yours by default, because drawing one is how a
                        // level is normally made and claiming it afterwards is
                        // a step nobody remembers.
                        //
                        // Still nullable, and still clearable. A level with
                        // nobody against it is an orphan, which is a real
                        // state — everything drawn before there were accounts
                        // is one, and an orphan stays editable by anybody
                        // rather than being locked away from everybody.
                        Select::make('owner_id')
                            ->label('Drawn by')
                            ->relationship('owner', 'name')
                            ->default(Auth::id())
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

                // One list of twelve rather than a file and a cell number.
                // Which strip a panorama is packed into is a fact about the
                // art, not a decision anybody makes.
                Section::make('Sky')
                    ->description('Shown by any sector marked as open to the sky.')
                    ->schema([
                        Select::make('sky')
                            ->label('Sky')
                            ->options(array_column($assets->skyChoices(), 'label', 'value'))
                            ->placeholder('No sky')
                            ->default('sky-day:0')
                            ->live(),
                        Html::make(fn (Get $get): Htmlable => self::skyPreview(
                            is_string($get('sky')) ? $get('sky') : null,
                        )),
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

    /**
     * The chosen panorama, shown as wide as the form is.
     *
     * A cell is equirectangular and 2:1, so laid out flat like this it is very
     * nearly what you would see turning a full circle on the spot: the left
     * edge and the right edge are the same direction. That is worth more than
     * a thumbnail, which at postage-stamp size cannot tell dusk from night.
     *
     * The strip holds `SKY_VARIANTS` cells side by side, so the box is filled
     * with a background that many times too wide and slid along. In CSS
     * percentages that is `variant / (cells - 1)`, because 100% means the
     * right edge of the image against the right edge of the box rather than
     * anything about the image's own width.
     */
    private static function skyPreview(?string $sky): Htmlable
    {
        if ($sky === null) {
            return new HtmlString('');
        }

        $image = Str::before($sky, ':');
        $variant = (int) Str::after($sky, ':');
        $across = LevelAssets::SKY_VARIANTS;

        $style = sprintf(
            'aspect-ratio:2/1;background-image:url(%s);background-size:%d%% 100%%;background-position:%s%% 50%%;background-repeat:no-repeat;',
            e(asset('sprites/bg/'.$image.'.png')),
            $across * 100,
            round($variant * 100 / ($across - 1), 4),
        );

        return new HtmlString(sprintf(
            '<div class="w-full overflow-hidden rounded-lg ring-1 ring-gray-950/10 dark:ring-white/20" style="%s"></div>',
            $style,
        ));
    }
}
