<?php

namespace App\Http\Controllers;

use App\Http\Requests\UpdateWallRequest;
use App\Models\Level;
use App\Models\LevelSectorEdge;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;

/**
 * Changing one wall while standing in front of it.
 *
 * **`LevelWriter` is not involved, and does not need to be.** A save from the
 * map editor deletes every sector, vertex and edge and rebuilds them, because
 * the shape that arrives replaces the shape that was stored and two rooms
 * sharing a corner have to go on sharing it. None of that applies here: a
 * texture and the three per-side flags all live on one `level_sector_edges`
 * row, no geometry moves, no corner is shared or unshared, and nothing else in
 * the level can be affected by the change. So this updates a row.
 *
 * That is worth stating rather than leaving implied. The full rewrite is heavy
 * — level 8 is 434 walls and 164 corners — and it would be easy to reach for
 * the existing writer because it is there. It would also throw away every id in
 * the level to set one boolean.
 */
class LevelWallController extends Controller
{
    public function update(UpdateWallRequest $request, Level $level): JsonResponse
    {
        Gate::authorize('update', $level);

        $sector = $level->sectors()
            ->where('slug', $request->string('sector'))
            ->first();

        if ($sector === null) {
            return response()->json([
                'message' => 'That room is not in this level any more.',
            ], 404);
        }

        $edge = $sector->edges()
            ->where('sort_order', $request->integer('index'))
            ->with('vertex')
            ->first();

        if ($edge === null) {
            return response()->json([
                'message' => 'That room has no such wall any more.',
            ], 404);
        }

        if (! $this->isTheWallTheyMeant($sector->edges()->with('vertex')->get(), $edge, $request)) {
            // Somebody has redrawn this room since the client last looked at
            // it. Refusing beats changing whichever wall inherited the number.
            return response()->json([
                'message' => 'That wall has moved since you looked at it. Reload the level.',
            ], 409);
        }

        $edge->update($request->changes());

        return response()->json([
            'sector' => $sector->slug,
            'index' => $edge->sort_order,
            'wallTexture' => $edge->wall_texture,
            'blocks' => $edge->blocks,
            'isMirror' => $edge->is_mirror,
            'isSky' => $edge->is_sky,
        ]);
    }

    /**
     * Whether the wall at that index still runs between the corners the client
     * last saw it between.
     *
     * A wall is named by its room and its position in that room's point list,
     * and every editor operation that rewrites the list moves the index —
     * splitting a wall, welding a corner a neighbour landed on, carving. The
     * pair survives a save and not an edit, so the client sends the corners it
     * had and this refuses if they no longer match.
     *
     * @param  Collection<int, LevelSectorEdge>  $edges
     */
    private function isTheWallTheyMeant(
        Collection $edges,
        LevelSectorEdge $edge,
        UpdateWallRequest $request,
    ): bool {
        $expected = $request->input('expect');

        if (! is_array($expected)) {
            return true;
        }

        $ordered = $edges->sortBy('sort_order')->values();
        $at = $ordered->search(fn (LevelSectorEdge $each): bool => $each->is($edge));

        if ($at === false) {
            return false;
        }

        // The wall starting at this corner runs to the next one round.
        $next = $ordered[($at + 1) % $ordered->count()];

        return $this->sameSpot($edge->vertex->x, $expected['from']['x'] ?? null)
            && $this->sameSpot($edge->vertex->z, $expected['from']['z'] ?? null)
            && $this->sameSpot($next->vertex->x, $expected['to']['x'] ?? null)
            && $this->sameSpot($next->vertex->z, $expected['to']['z'] ?? null);
    }

    private function sameSpot(float $stored, mixed $claimed): bool
    {
        return is_numeric($claimed) && abs($stored - (float) $claimed) < 0.001;
    }
}
