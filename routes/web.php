<?php

use App\Http\Controllers\DebugSnapshotController;
use App\Http\Controllers\GameController;
use App\Http\Controllers\InteractionController;
use App\Http\Controllers\LevelEditorController;
use App\Http\Controllers\LevelWallController;
use App\Http\Controllers\SaveController;
use App\Http\Controllers\SupportTicketController;
use Illuminate\Support\Facades\Route;

Route::get('/', [GameController::class, 'index'])->name('games.index');

// The map editor sits behind the same login as the admin panel it is reached from.
Route::middleware('auth')->group(function () {
    Route::get('editor/{level}', [LevelEditorController::class, 'edit'])->name('levels.editor');
    // A ticket's pictures are bytes somebody posted without signing in, so they
    // are never served from public/. The panel's own login is the gate.
    Route::get('tickets/{ticket}/{kind}', [SupportTicketController::class, 'shot'])
        ->name('tickets.shot');
    Route::put('editor/{level}', [LevelEditorController::class, 'update'])->name('levels.editor.update');

    // Changing one wall from inside the level. Same authority as the editor,
    // because it writes to the level everybody sees — playing stays read-only.
    Route::patch('editor/{level}/wall', [LevelWallController::class, 'update'])
        ->name('levels.wall.update');
});

// Somewhere to put "this spot looks wrong" while playing. Local only; the
// controller refuses anywhere else.
Route::post('debug/snapshot', [DebugSnapshotController::class, 'store'])
    ->name('debug.snapshot');

Route::prefix('games/{game}')->group(function () {
    Route::get('/', [GameController::class, 'show'])->name('games.show');
    Route::post('interactions', [InteractionController::class, 'store'])->name('games.interactions.store');
    Route::post('position', [SaveController::class, 'position'])->name('games.position.store');
    Route::delete('save', [SaveController::class, 'destroy'])->name('games.save.destroy');

    // Anybody may play a published game, so anybody may report one. Throttled
    // because this is the only endpoint here that takes bytes from the public,
    // and three pictures a time adds up quickly.
    Route::post('tickets', [SupportTicketController::class, 'store'])
        ->middleware('throttle:6,1')
        ->name('games.tickets.store');
});
