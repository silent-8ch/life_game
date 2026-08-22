<?php

namespace App\Http\Controllers;

use App\Enums\Verb;
use App\Models\Game;
use App\Models\GameFlag;
use App\Models\GameState;
use App\Models\Hotspot;
use App\Models\Item;
use App\Services\LevelPayload;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class GameController extends Controller
{
    /**
     * List the adventures that are available to play.
     */
    public function index(): Response
    {
        $games = Game::query()
            ->published()
            ->with(['state.currentScene', 'state.currentLevel'])
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get();

        return Inertia::render('games/index', [
            'games' => $games->map(fn (Game $game): array => [
                'slug' => $game->slug,
                'title' => $game->title,
                'tagline' => $game->tagline,
                'coverImage' => $game->cover_image,
                'inProgress' => $game->state !== null,
                'currentLocationName' => $game->state?->locationName(),
            ])->all(),
        ]);
    }

    /**
     * Hand the player to the engine the game was authored for.
     */
    public function show(Request $request, Game $game): Response
    {
        abort_unless($game->is_published, 404);

        $state = GameState::for($game);

        return $game->isFirstPerson()
            ? $this->explore($game, $state, $request->string('level')->toString())
            : $this->play($game, $state);
    }

    /**
     * The room the player is standing in, as geometry the browser can build.
     */
    private function explore(Game $game, GameState $state, string $wanted = ''): Response
    {
        $state->load([
            'currentLevel.sectors.edges.vertex',
            'currentLevel.things.interactions.requiredItem',
            'items',
            'flags',
        ]);

        // ?level=slug drops the player into one particular level, which is how
        // the map editor's Play button shows the level being edited. It is for
        // this visit only: the save carries on pointing where it did.
        $level = ($wanted === '' ? null : $game->levels()
            ->where('slug', $wanted)
            ->with(['sectors.edges.vertex', 'things.interactions.requiredItem'])
            ->first())
            ?? $state->currentLevel
            ?? $game->openingLevel()->load([
                'sectors.edges.vertex',
                'things.interactions.requiredItem',
            ]);

        return Inertia::render('game/explore', [
            'game' => [
                'slug' => $game->slug,
                'title' => $game->title,
            ],
            // A closure, so that a partial reload after an interaction does not
            // rebuild the geometry only to throw it away. The browser asks for
            // the inventory and the message; the level it already has stands.
            'level' => fn (): array => app(LevelPayload::class)->forEngine($level),
            'inventory' => $this->inventory($state),
            // Every flag that has been set, so the level can show what the
            // world already knows: a lamp somebody switched on stays on.
            //
            // Refreshed with the inventory after an interaction rather than
            // read once at load, because a switch you flip should light up when
            // you use it. Deliberately *not* part of the level closure above —
            // that one exists so a partial reload never rebuilds the geometry,
            // and flags are the small half that does change.
            'flags' => $this->flags($state),
            // Where they got to last time, or null to start at the level's
            // spawn. Only offered when it is this level they were standing in:
            // a position is a place in one particular level and means nothing
            // in another, and ?level= can drop somebody into a different one.
            'standingAt' => $level->is($state->currentLevel)
                ? $state->standingAt()
                : null,
            'message' => $state->last_message,
        ]);
    }

    /**
     * The flags that have been set, as names against their values.
     *
     * Only what has been set. A flag nobody has touched is absent rather than
     * empty, so "is this set" is a question about the keys and never about
     * telling an unset flag from one set to nothing.
     *
     * @return array<string, string>
     */
    private function flags(GameState $state): array
    {
        return $state->flags
            ->mapWithKeys(fn (GameFlag $flag): array => [
                $flag->key => (string) $flag->value,
            ])
            ->all();
    }

    /**
     * Render the current scene, the inventory, and the last thing that happened.
     */
    private function play(Game $game, GameState $state): Response
    {
        $state->load(['currentScene.hotspots', 'items', 'flags', 'hotspotOverrides']);

        $scene = $state->currentScene ?? $game->openingScene();

        return Inertia::render('game/play', [
            'game' => [
                'slug' => $game->slug,
                'title' => $game->title,
            ],
            'scene' => [
                'slug' => $scene->slug,
                'name' => $scene->name,
                'description' => $scene->description,
                'backgroundImage' => $scene->background_image,
                'backgroundColor' => $scene->background_color,
            ],
            'hotspots' => $state->visibleHotspots()->map(fn (Hotspot $hotspot): array => [
                'slug' => $hotspot->slug,
                'name' => $hotspot->name,
                'x' => $hotspot->x,
                'y' => $hotspot->y,
                'width' => $hotspot->width,
                'height' => $hotspot->height,
            ])->all(),
            'inventory' => $this->inventory($state),
            'verbs' => collect(Verb::cases())->map(fn (Verb $verb): array => [
                'value' => $verb->value,
                'label' => $verb->label(),
                'acceptsItem' => $verb->acceptsItem(),
            ])->all(),
            'message' => $state->last_message,
        ]);
    }

    /**
     * @return list<array{slug: string, name: string, description: string, icon: string|null}>
     */
    private function inventory(GameState $state): array
    {
        return array_values($state->items->map(fn (Item $item): array => [
            'slug' => $item->slug,
            'name' => $item->name,
            'description' => $item->description,
            'icon' => $item->icon,
        ])->all());
    }
}
