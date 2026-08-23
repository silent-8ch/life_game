<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreDebugSnapshotRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;

/**
 * Somewhere to put "this spot looks wrong" while playing.
 *
 * Files rather than rows: this is scaffolding for chasing a fault, and a folder
 * that can be deleted when the fault is gone beats a table that stays in the
 * schema for ever. They land in storage/app/debug, one JSON file each, newest
 * name last when sorted.
 *
 * ## And the pictures beside it
 *
 * A snapshot used to carry the position and nothing else, which made it half a
 * report: somebody reading it had to go and stand on the spot themselves before
 * they could see what was being complained about. That is a whole round trip
 * per fault, and worse than it sounds when the two people are looking at
 * different machines — one describes *black* and *sky*, the other renders the
 * same coordinates and sees neither.
 *
 * The pictures are read back offscreen into a render target rather than off the
 * canvas, so they need no `preserveDrawingBuffer` and work in ordinary play.
 * That matters: the debug view they would otherwise have to come from builds no
 * sky dome and strips every texture, so it is blind to exactly the two words
 * faults get reported in.
 *
 * They are named for the snapshot they belong to rather than filed under a
 * folder of their own, so that one `ls` still shows the notes in the order they
 * were taken with their pictures beside them.
 */
class DebugSnapshotController extends Controller
{
    public function store(StoreDebugSnapshotRequest $request): JsonResponse
    {
        $snapshot = $request->validated();

        // A form cannot carry an empty array, so a spot with no edge nearby
        // arrives with the key missing rather than empty. Put it back, because
        // a reader telling the two apart would be reading a transport detail.
        $snapshot['edgesNearby'] ??= [];

        // The pictures are files, not fields, and belong beside the note rather
        // than inside it.
        unset($snapshot['shots']);

        $where = config('debug-snapshots.path') ?? storage_path('app/debug');
        File::ensureDirectoryExists($where);

        $stem = sprintf(
            '%s-%s',
            now()->format('Y-m-d-His'),
            Str::slug($snapshot['level']['slug'] ?? 'level'),
        );

        File::put(
            "{$where}/{$stem}.json",
            json_encode($snapshot, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) ?: '{}',
        );

        return response()->json([
            'saved' => "{$stem}.json",
            'shots' => $this->writeShots($request, $where, $stem),
        ]);
    }

    /**
     * Puts each view down beside the note, named for it.
     *
     * The extension comes from what the file actually is rather than from what
     * it was called: the browser names these, and a name is not evidence.
     *
     * @return array<int, string>
     */
    private function writeShots(
        StoreDebugSnapshotRequest $request,
        string $where,
        string $stem,
    ): array {
        /** @var array<string, UploadedFile> $shots */
        $shots = $request->file('shots') ?? [];
        $written = [];

        foreach ($shots as $kind => $file) {
            $name = sprintf('%s-%s.%s', $stem, Str::slug($kind), $file->extension());

            $file->move($where, $name);

            $written[] = $name;
        }

        return $written;
    }
}
