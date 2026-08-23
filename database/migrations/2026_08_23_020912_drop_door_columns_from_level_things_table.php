<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A door stops being a kind of thing.
 *
 * Paul: *a door is just a solid sprite that has a hinge with an action.* So
 * `is_door`, `swing`, `open_angle`, `open_seconds`, `is_open` and `opens_flag`
 * go, and what replaces them between them is one `hinge` column and two
 * effects that never have to know what they are moving.
 *
 * Six columns that could make exactly one thing, against a hinge and a rotate
 * that make a door, a drawbridge, a hatch, a shutter and a window that swings
 * out — and that is not a trade of features for generality, because the six
 * could not make any of the others either.
 *
 * Nothing is lost by dropping them. Nothing anybody has drawn uses them: the
 * two doors in the House seeder are the only things in the repo that ever did,
 * and they are re-authored in the same commit as a flat thing with a hinge and
 * a Use. On the demo, where the levels nobody can reseed live, the count of
 * things with `is_door` set has been zero all along.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('level_things', function (Blueprint $table): void {
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

    public function down(): void
    {
        Schema::table('level_things', function (Blueprint $table): void {
            $table->boolean('is_door')->default(false)->after('is_solid');
            $table->string('swing')->default('swing')->after('is_door');
            $table->float('open_angle')->default(90)->after('swing');
            $table->float('open_seconds')->default(0.35)->after('open_angle');
            $table->boolean('is_open')->default(false)->after('open_seconds');
            $table->string('opens_flag')->nullable()->after('is_open');
        });
    }
};
