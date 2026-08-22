<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreInteractionRequest;
use App\Models\Game;
use App\Models\GameState;
use App\Models\Hotspot;
use App\Models\Item;
use App\Models\LevelThing;
use App\Services\EffectApplier;
use App\Services\InteractionResolver;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class InteractionController extends Controller
{
    public function __construct(
        private readonly InteractionResolver $resolver,
        private readonly EffectApplier $applier,
    ) {}

    /**
     * Apply a verb to a hotspot in the current scene, optionally with an inventory item.
     */
    public function store(StoreInteractionRequest $request, Game $game): RedirectResponse
    {
        abort_unless($game->is_published, 404);

        $state = GameState::for($game)->load(['currentScene.hotspots', 'items', 'flags', 'hotspotOverrides']);
        $verb = $request->verb();

        $subject = $request->isInALevel()
            ? $this->thing($request, $game)
            : $this->hotspot($request, $state);

        $item = $this->resolveItem($request, $state, $verb->acceptsItem());

        DB::transaction(function () use ($state, $subject, $verb, $item): void {
            $interaction = $this->resolver->resolve($state, $subject, $verb, $item);

            if ($interaction !== null) {
                $this->applier->apply($state, $interaction);
            }

            $state->update([
                'last_message' => $interaction === null
                    ? $verb->fallbackResponse()
                    : $interaction->response,
            ]);
        });

        // Back rather than to the game, so that a first-person player carries on
        // standing where they were and keeps whichever level they were sent to.
        // The page asks for the inventory and the message alone, so the level
        // prop is never rebuilt.
        return back(fallback: route('games.show', $game));
    }

    /**
     * The thing the crosshair was resting on. It is looked up in the level the
     * player says they are in rather than in the save file's current level,
     * since the editor's Play button can drop them into any level it likes.
     *
     * @throws ValidationException
     */
    private function thing(StoreInteractionRequest $request, Game $game): LevelThing
    {
        $thing = LevelThing::query()
            ->whereRelation('level', 'game_id', $game->id)
            ->whereRelation('level', 'slug', $request->string('level')->value())
            ->where('slug', $request->string('thing')->value())
            ->first();

        if ($thing === null) {
            throw ValidationException::withMessages([
                'thing' => 'There is nothing like that here.',
            ]);
        }

        return $thing;
    }

    /**
     * @throws ValidationException
     */
    private function hotspot(StoreInteractionRequest $request, GameState $state): Hotspot
    {
        $hotspot = $state->visibleHotspots()
            ->firstWhere('slug', $request->string('hotspot')->value());

        if ($hotspot === null) {
            throw ValidationException::withMessages([
                'hotspot' => 'There is nothing like that here.',
            ]);
        }

        return $hotspot;
    }

    /**
     * @throws ValidationException
     */
    private function resolveItem(StoreInteractionRequest $request, GameState $state, bool $verbAcceptsItem): ?Item
    {
        $slug = $request->string('item')->value();

        if ($slug === '') {
            return null;
        }

        if (! $verbAcceptsItem) {
            throw ValidationException::withMessages([
                'item' => 'That verb cannot be combined with an item.',
            ]);
        }

        if (! $state->hasItem($slug)) {
            throw ValidationException::withMessages([
                'item' => 'You are not carrying that.',
            ]);
        }

        return $state->items->firstWhere('slug', $slug);
    }
}
