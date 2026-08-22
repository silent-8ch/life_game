<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A save file points at a scene or at a level, never both.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('game_states', function (Blueprint $table) {
            $table->foreignId('current_scene_id')->nullable()->change();
            $table->foreignId('current_level_id')->nullable()->after('current_scene_id')->constrained('levels')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('game_states', function (Blueprint $table) {
            $table->dropConstrainedForeignId('current_level_id');
            $table->foreignId('current_scene_id')->nullable(false)->change();
        });
    }
};
