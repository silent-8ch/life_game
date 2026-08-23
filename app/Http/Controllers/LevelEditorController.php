<?php

namespace App\Http\Controllers;

use App\Http\Requests\UpdateLevelMapRequest;
use App\Models\Item;
use App\Models\Level;
use App\Services\LevelAssets;
use App\Services\LevelPayload;
use App\Services\LevelWriter;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The map editor: a floor plan, a section through it, and a live preview of
 * what the engine will make of it.
 */
class LevelEditorController extends Controller
{
    public function edit(Level $level, LevelPayload $payload, LevelAssets $assets): Response
    {
        Gate::authorize('update', $level);

        return Inertia::render('editor/level', [
            'level' => $payload->forEditor($level),
            'levelId' => $level->id,
            'game' => [
                'slug' => $level->game->slug,
                'title' => $level->game->title,
            ],
            'assets' => [
                'textures' => $assets->textures(),
                'props' => $assets->props(),
                'skies' => $assets->skies(),
                'backdrops' => $assets->backdrops(),
                'sprites' => $assets->roster(),
                'styles' => $assets->styles(),
                // The game's items, for the interaction panel's pickers: what a
                // verb may require, and what an effect may give or take away.
                'items' => $level->game->items
                    ->sortBy('name')
                    ->map(fn (Item $item): array => [
                        'slug' => $item->slug,
                        'name' => $item->name,
                    ])->values()->all(),
            ],
        ]);
    }

    public function update(
        UpdateLevelMapRequest $request,
        Level $level,
        LevelWriter $writer,
    ): RedirectResponse {
        $writer->save($level, $request->validated());

        return back()->with('saved', true);
    }
}
