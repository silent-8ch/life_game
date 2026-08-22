<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreSupportTicketRequest;
use App\Models\Game;
use App\Models\SupportTicket;
use App\Services\SpotCapture;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * Raising a ticket from inside a game.
 *
 * A second thing beside the debug snapshot rather than a replacement for it.
 * A snapshot is scaffolding — files in a folder, refused outside local, deleted
 * when the fault it was chasing is gone. A ticket comes from somebody playing
 * and has to persist, be listed, be found again and be marked dealt with.
 */
class SupportTicketController extends Controller
{
    public function __construct(private readonly SpotCapture $capture) {}

    public function store(StoreSupportTicketRequest $request, Game $game): JsonResponse
    {
        abort_unless($game->is_published, 404);

        $slug = $request->input('level');

        $level = $slug === null
            ? null
            : $game->levels()->where('slug', $slug)->first();

        $ticket = DB::transaction(function () use ($request, $game, $level, $slug): SupportTicket {
            $ticket = SupportTicket::create([
                'game_id' => $game->id,
                'level_id' => $level?->id,
                'level_slug' => $slug,
                'source' => $request->string('source')->toString(),
                // Null unless somebody happens to be signed in. Playing needs no
                // account, so most tickets will be anonymous.
                'user_id' => $request->user()?->id,
                'note' => $request->input('note'),
                // As a set or not at all — half a position looks like
                // somewhere and is nowhere.
                'at_x' => $request->input('at.x'),
                'at_z' => $request->input('at.z'),
                'at_eye' => $request->input('at.eye'),
                'at_yaw' => $request->input('at.yaw'),
                'at_pitch' => $request->input('at.pitch'),
                // The whole room, and its slug beside it. The slug is what the
                // admin table filters and sorts by; the object is what makes
                // the ticket diagnostic rather than merely located.
                'standing_in_slug' => $request->input('standingIn.slug'),
                'standing_in' => $request->input('standingIn'),
                'looking_at' => $request->input('lookingAt'),
                'holding' => $request->input('holding'),
                'is_running' => $request->boolean('running'),
                'editor_state' => $request->input('editorState'),
                'screen' => $request->input('screen'),
                'nearby' => $request->input('nearby'),
                // Kept with the pictures rather than beside them. Without it
                // the colour-coded view decodes to nothing.
                'legend' => $request->input('legend'),
            ]);

            // A folder per ticket, so tidying one up is deleting a directory
            // and the same writer serves a snapshot that is a folder and not a
            // row.
            /** @var array<string, UploadedFile> $shots */
            $shots = $request->file('shots', []);

            foreach ($this->capture->write(
                $ticket->folder(),
                $shots,
                $request->input('legend'),
            ) as $shot) {
                $ticket->shots()->create($shot);
            }

            return $ticket;
        });

        return response()->json([
            'ticket' => $ticket->id,
            'shots' => $ticket->shots()->count(),
        ], 201);
    }

    /**
     * One of a ticket's pictures, for the admin panel.
     *
     * Served through here rather than from `public/`, because these are bytes
     * posted by whoever was playing and nobody signed in to do it. The panel's
     * own login is the gate.
     */
    public function shot(SupportTicket $ticket, string $kind): mixed
    {
        $shot = $ticket->shots()->where('kind', $kind)->firstOrFail();

        abort_unless(Storage::disk('local')->exists($shot->path), 404);

        return Storage::disk('local')->response($shot->path);
    }
}
