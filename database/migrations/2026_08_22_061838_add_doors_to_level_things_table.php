<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Doors that open.
 *
 * A doorway today is a gap between two wall runs with a thing standing in it,
 * and the thing does not move. These columns are what turns that thing into a
 * door: which way it gets out of the way, how far, how fast, and whether it
 * starts open.
 *
 * `is_open` is the **starting** state, not the current one. Where a door
 * actually stands while somebody is playing is the engine's business, because
 * you walk through a door in the same frame it opens and nothing that involves
 * the server can keep up with that. `opens_flag` is how a door that has been
 * opened can still be open the next time you load — persistence, not truth.
 *
 * Every default is a thing that does not move, so nothing already placed
 * becomes a door by surprise.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('level_things', function (Blueprint $table) {
            $table->boolean('is_door')
                ->default(false)
                ->after('is_solid')
                ->comment('Whether this opens. A door drops its collider while it is open.');

            $table->string('swing')
                ->default('swing')
                ->after('is_door')
                ->comment('swing, slide or fold — how it gets out of the way.');

            $table->float('open_angle')
                ->default(90)
                ->after('swing')
                ->comment('Degrees a swing door turns through, or the fraction of its width a slider moves, times 90.');

            $table->float('open_seconds')
                ->default(0.4)
                ->after('open_angle')
                ->comment('How long it takes to open or shut.');

            $table->boolean('is_open')
                ->default(false)
                ->after('open_seconds')
                ->comment('Whether it starts open. Not where it is now — that is the engine\'s.');

            $table->string('opens_flag')
                ->nullable()
                ->after('is_open')
                ->comment('A game flag remembering it was opened, so it is still open next time. Null forgets.');
        });
    }

    public function down(): void
    {
        Schema::table('level_things', function (Blueprint $table) {
            $table->dropColumn([
                'is_door',
                'swing',
                'open_angle',
                'open_seconds',
                'is_open',
                'opens_flag',
            ]);
        });
    }
};
