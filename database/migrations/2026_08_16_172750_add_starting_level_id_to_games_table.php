<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A game is played in the first person when it has a starting level, and as a
 * point-and-click adventure when it has a starting scene.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('games', function (Blueprint $table) {
            $table->foreignId('starting_level_id')->nullable()->after('starting_scene_id')->constrained('levels')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('games', function (Blueprint $table) {
            $table->dropConstrainedForeignId('starting_level_id');
        });
    }
};
