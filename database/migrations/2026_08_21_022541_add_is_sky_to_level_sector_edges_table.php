<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A wall you can see the sky through. It is still a wall — it stops the player
 * the way any other does — but nothing is drawn on it, so what shows there is
 * the sky, and whatever stands beyond it is hidden. The same trick as the lid
 * over a room open to the sky, stood upright.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('level_sector_edges', function (Blueprint $table) {
            $table->boolean('is_sky')
                ->default(false)
                ->after('is_mirror')
                ->comment('Shows the sky instead of a surface, and hides what is behind it.');
        });
    }

    public function down(): void
    {
        Schema::table('level_sector_edges', function (Blueprint $table) {
            $table->dropColumn('is_sky');
        });
    }
};
