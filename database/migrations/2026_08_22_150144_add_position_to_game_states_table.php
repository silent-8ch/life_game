<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Where the player was standing, so shutting the game does not send them back
 * to the front step.
 *
 * `current_level_id` already remembered which level; nothing remembered where
 * in it. That mattered little when a level was a room to look at and matters
 * more now that there are doors to have opened and stairs to have climbed.
 *
 * **The angle here is the player's own yaw, in degrees.** It is deliberately
 * *not* `levels.spawn_angle`, which is the negative of it — the engine reads a
 * spawn as `yaw = -degToRad(spawn.angle)`. Two encodings of the same idea that
 * disagree by a sign is exactly how a saved game ends up facing a wall, and it
 * has already cost this project an evening of chasing a bug that was really a
 * mis-aimed reproduction. This matches `?at=` and what a debug snapshot writes,
 * which are the two places a position is already spelled out.
 *
 * Separate columns rather than one packed string, because the player is about
 * to gain a height that is state rather than something derived from the floor
 * under them. When that lands it is one more column here and nothing already
 * written has to be read differently.
 *
 * Null everywhere means "has not been anywhere yet", and the level's spawn
 * stands — which is every save that exists today.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('game_states', function (Blueprint $table) {
            $table->float('position_x')
                ->nullable()
                ->after('current_level_id')
                ->comment('Where the player stood, in metres. Null means they have not been anywhere yet.');

            $table->float('position_z')
                ->nullable()
                ->after('position_x')
                ->comment('Where the player stood, in metres.');

            $table->float('facing')
                ->nullable()
                ->after('position_z')
                ->comment("The player's own yaw in degrees — NOT levels.spawn_angle, which is its negative.");

            $table->float('pitch')
                ->nullable()
                ->after('facing')
                ->comment('Degrees up or down, so looking at something is still being looked at.');
        });
    }

    public function down(): void
    {
        Schema::table('game_states', function (Blueprint $table) {
            $table->dropColumn(['position_x', 'position_z', 'facing', 'pitch']);
        });
    }
};
