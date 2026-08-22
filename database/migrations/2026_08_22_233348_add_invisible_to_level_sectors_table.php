<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A room the camera sees straight through.
 *
 * Paul: *"make invisible rooms. If this is set, the camera acts as if it sees
 * through this room. The floor should still be visible. The player and
 * characters can walk into this area, they become invisible."*
 *
 * Deliberately not the same idea as `is_sky`. A sky room has no ceiling drawn
 * and gets an invisible lid instead, so that sight-lines cannot run out of the
 * level. An invisible room is the opposite ruling: whatever lies beyond it just
 * shows, and where nothing lies beyond you see the backdrop. It is meant to be
 * the hole in the world a lid exists to prevent.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('level_sectors', function (Blueprint $table): void {
            $table->boolean('is_invisible')
                ->default(false)
                ->after('is_water')
                ->comment('The camera sees through it. Only the floor draws, and anybody standing in it is not drawn at all.');
        });
    }

    public function down(): void
    {
        Schema::table('level_sectors', function (Blueprint $table): void {
            $table->dropColumn('is_invisible');
        });
    }
};
