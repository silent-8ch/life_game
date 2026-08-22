<?php

namespace App\Http\Controllers;

use App\Models\Game;
use App\Models\GameState;
use Illuminate\Http\RedirectResponse;

class SaveController extends Controller
{
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
