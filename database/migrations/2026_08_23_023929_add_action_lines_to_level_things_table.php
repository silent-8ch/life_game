<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A thing that puts a named line on.
 *
 * Paul: *maybe we steal from redstone. Have an interactions level where things
 * trigger other things.* This is the emitting half — a lever, a pressure plate,
 * and later a torch — and `level_thing_bindings` is the responding half.
 *
 * `emits` is a line name, and **a line name is a flag name**: they share one
 * namespace deliberately, so that `alt_flag` lights a lamp while a line is on
 * without a column for it and `FlagIs` gates an interaction on one for free.
 * See `docs/plan-action-lines.md` for why that is the decision the rest hangs off.
 *
 * Null for the overwhelming majority of things, which emit nothing.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('level_things', function (Blueprint $table): void {
            $table->string('emits')
                ->nullable()
                ->after('hinge')
                ->comment('The line this thing puts on. Shares a namespace with game flags.');

            $table->string('emit_when')
                ->nullable()
                ->after('emits')
                ->comment('used for a lever, stood_on for a plate. Null when it emits nothing.');

            $table->string('triggered_by')
                ->default('player')
                ->after('emit_when')
                ->comment('Who can stand on it: player, actors or anyone. A lever is always the player.');
        });
    }

    public function down(): void
    {
        Schema::table('level_things', function (Blueprint $table): void {
            $table->dropColumn(['emits', 'emit_when', 'triggered_by']);
        });
    }
};
