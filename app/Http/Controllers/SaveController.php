<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreActionLineRequest;
use App\Http\Requests\StorePositionRequest;
use App\Models\Game;
use App\Models\GameState;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Response;

class SaveController extends Controller
{
    /**
     * Remember where the player has got to.
     *
     * Answers with no content and no redirect on purpose. This is sent while
     * somebody is walking about, and anything that re-renders the page would
     * rebuild the level under their feet — which is the same reason the
     * interaction round trip asks for two fields and not the geometry.
     */
    public function position(StorePositionRequest $request, Game $game): Response
    {
        abort_unless($game->is_published, 404);

        $state = GameState::for($game);

        $state->update([
            'position_x' => $request->float('x'),
            'position_z' => $request->float('z'),
            'facing' => $request->float('facing'),
            'pitch' => $request->float('pitch'),
        ]);

        return response()->noContent();
    }

    /**
     * A lever thrown, remembered.
     *
     * A latching line is a flag, which is the whole reason there is no second
     * store to write it to. A momentary one — a plate — never reaches here at
     * all: you are not standing on it next session, and putting it back would
     * open a door in an empty room.
     *
     * Posted on the change rather than on the frame, so a lever costs one
     * request however fast the game is drawing.
     */
    public function line(StoreActionLineRequest $request, Game $game): Response
    {
        abort_unless($game->is_published, 404);

        $state = GameState::for($game);
        $line = $request->string('line')->toString();

        if ($request->boolean('on')) {
            $state->flags()->updateOrCreate(['key' => $line], ['value' => 'on']);
        } else {
            $state->flags()->where('key', $line)->delete();
        }

        return response()->noContent();
    }

    /**
     * Wipe a game's save file and start it over.
     */
    public function destroy(Game $game): RedirectResponse
    {
        abort_unless($game->is_published, 404);

        GameState::for($game)->reset();

        return to_route('games.show', $game);
    }
}
