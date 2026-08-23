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
     * A listener's flag, remembered.
     *
     * The one place a flag is written from the browser, and the request refuses
     * any name no listener in that level declares — because line names and flag
     * names share a namespace and the alternative is letting anybody set the
     * flags every lock is gated on.
     *
     * Posted on the change rather than on the frame, so a lever costs one
     * request however fast the game is drawing.
     */
    public function line(StoreActionLineRequest $request, Game $game): Response
    {
        abort_unless($game->is_published, 404);

        $state = GameState::for($game);
        $flag = $request->string('flag')->toString();

        if ($request->boolean('on')) {
            $state->flags()->updateOrCreate(['key' => $flag], ['value' => 'on']);
        } else {
            $state->flags()->where('key', $flag)->delete();
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
