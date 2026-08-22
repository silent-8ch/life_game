<?php

namespace App\Http\Controllers;

use App\Models\Level;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The levels you can pick up and carry on with.
 *
 * Yours by default, because a list of everybody's is the thing this exists to
 * stop. `?everyone=1` widens it — seeing is not editing, and nothing anybody
 * drew is hidden from anybody.
 *
 * Orphans — levels drawn before there were accounts — belong to nobody and are
 * only in the wide list. They are still editable: unclaimed is not protected.
 */
class LevelPickerController extends Controller
{
    public function index(Request $request): Response
    {
        /** @var User $user */
        $user = Auth::user();

        $everyone = $request->boolean('everyone');

        $levels = Level::query()
            ->with(['owner', 'game'])
            ->withCount(['sectors', 'things'])
            ->when(! $everyone, fn ($query) => $query->where('owner_id', $user->id))
            ->orderByDesc('updated_at')
            ->get()
            ->map(fn (Level $level): array => [
                'id' => $level->id,
                'slug' => $level->slug,
                'name' => $level->name,
                'game' => $level->game->slug,
                'rooms' => $level->sectors_count,
                'things' => $level->things_count,
                'owner' => $level->owner?->name,
                'mine' => $level->owner_id === $user->id,
                'orphan' => $level->owner_id === null,
                // Unclaimed counts as editable, so the button matches the rule.
                'editable' => $level->owner_id === null || $level->owner_id === $user->id,
                'updatedAt' => $level->updated_at?->toIso8601String(),
            ])
            ->values()
            ->all();

        return Inertia::render('levels/index', [
            'levels' => $levels,
            'everyone' => $everyone,
            'me' => $user->name,
        ]);
    }
}
