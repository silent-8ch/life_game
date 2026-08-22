<?php

use App\Http\Controllers\DebugSnapshotController;
use App\Http\Controllers\GameController;
use App\Http\Controllers\InteractionController;
use App\Http\Controllers\LevelEditorController;
use App\Http\Controllers\SaveController;
use Illuminate\Support\Facades\Route;

Route::get('/', [GameController::class, 'index'])->name('games.index');

// The map editor sits behind the same login as the admin panel it is reached from.
Route::middleware('auth')->group(function () {
    Route::get('editor/{level}', [LevelEditorController::class, 'edit'])->name('levels.editor');
    Route::put('editor/{level}', [LevelEditorController::class, 'update'])->name('levels.editor.update');
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
});
