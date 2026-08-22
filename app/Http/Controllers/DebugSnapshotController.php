<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreDebugSnapshotRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;

/**
 * Somewhere to put "this spot looks wrong" while playing.
 *
 * Files rather than rows: this is scaffolding for chasing a fault, and a folder
 * that can be deleted when the fault is gone beats a table that stays in the
 * schema for ever. They land in storage/app/debug, one JSON file each, newest
 * name last when sorted.
 */
class DebugSnapshotController extends Controller
{
    public function store(StoreDebugSnapshotRequest $request): JsonResponse
    {
        $snapshot = $request->validated();

        $where = config('debug-snapshots.path') ?? storage_path('app/debug');
        File::ensureDirectoryExists($where);

        $name = sprintf(
            '%s-%s.json',
            now()->format('Y-m-d-His'),
            Str::slug($snapshot['level']['slug'] ?? 'level'),
        );

        File::put(
            "{$where}/{$name}",
            json_encode($snapshot, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) ?: '{}',
        );

        return response()->json(['saved' => $name]);
    }
}
