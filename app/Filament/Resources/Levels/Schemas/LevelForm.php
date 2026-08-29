<?php

namespace App\Filament\Resources\Levels\Schemas;

use App\Models\Game;
use App\Services\LevelAssets;
use App\Services\LevelStarter;
use App\Services\WireframePreview;
use Filament\Forms\Components\ColorPicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Grid;
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

                // One line per panorama, named after its own file. There is
                // no second question about which cell of which strip, because
                // there are no strips any more.
                Section::make('Sky')
                    ->description('Shown by any sector marked as open to the sky.')
                    ->schema([
                        Select::make('sky_image')
                            ->label('Sky')
                            ->options(array_column($assets->skyChoices(), 'label', 'image'))
                            ->placeholder('No sky')
                            ->default('sky-day-1')
                            ->live(),
                        Html::make(fn (Get $get): Htmlable => self::skyPreview(
                            $assets,
                            is_string($get('sky_image')) ? $get('sky_image') : null,
                        )),
                    ]),

                Section::make('Wireframe colours')
                    ->description('Used for any surface that has no texture yet.')
                    ->collapsed()
                    ->schema([
                        Grid::make(3)->schema([
                            ColorPicker::make('wall_color')
                                ->label('Wall')
                                ->required()
                                ->default('#7fe0c9')
                                ->live(onBlur: true),
                            ColorPicker::make('floor_color')
                                ->label('Floor')
                                ->required()
                                ->default('#2f6f5e')
                                ->live(onBlur: true),
                            ColorPicker::make('accent_color')
                                ->label('Accent')
                                ->required()
                                ->default('#fbbf24')
                                ->live(onBlur: true),
                        ]),
                        // Not three swatches. The engine dims a solid fill to a
                        // ninth of its brightness and draws the grid over it at
                        // full strength, so a swatch shows a colour the game
                        // never paints — and how the three read against each
                        // other is the whole decision.
                        Html::make(fn (Get $get): Htmlable => app(WireframePreview::class)->render(
                            is_string($get('wall_color')) ? $get('wall_color') : '#000000',
                            is_string($get('floor_color')) ? $get('floor_color') : '#000000',
                            is_string($get('accent_color')) ? $get('accent_color') : '#000000',
                        )),
                    ]),
            ]);
    }

    /**
     * The chosen panorama, shown as wide as the form is.
     *
     * A sky is equirectangular and 2:1, so laid out flat like this it is very
     * nearly what you would see turning a full circle on the spot: the left
     * edge and the right edge are the same direction. That is worth more than
     * a thumbnail, which at postage-stamp size cannot tell dusk from night.
     */
    private static function skyPreview(LevelAssets $assets, ?string $image): Htmlable
    {
        if ($image === null) {
            return new HtmlString('');
        }

        return new HtmlString(sprintf(
            '<div class="w-full overflow-hidden rounded-lg ring-1 ring-gray-950/10 dark:ring-white/20" style="aspect-ratio:2/1;background-image:url(%s);background-size:100%% 100%%;background-repeat:no-repeat;"></div>',
            e(asset($assets->skyPath($image))),
        ));
    }
}
