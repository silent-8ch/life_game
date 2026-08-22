<?php

namespace App\Http\Controllers;

use App\Enums\Verb;
use App\Models\Game;
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
            'message' => $state->last_message,
        ]);
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
