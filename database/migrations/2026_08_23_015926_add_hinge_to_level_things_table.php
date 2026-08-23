<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Which edge a flat thing turns about.
 *
 * Paul: *a door is just a solid sprite that has a hinge with an action.* The
 * hinge is a property of the thing rather than of the action, which is what
 * lets one `rotate` effect open a door, drop a drawbridge and lift a hatch
 * without knowing which of those it is holding.
 *
 * Null for everything that does not turn, which is nearly everything.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('level_things', function (Blueprint $table): void {
            $table->string('hinge')
                ->nullable()
                ->after('angle')
                ->comment('Which edge a flat thing turns about: left, right, top or bottom. Null for one that does not turn.');
        });
    }

    public function down(): void
    {
        Schema::table('level_things', function (Blueprint $table): void {
            $table->dropColumn('hinge');
        });
    }
};
