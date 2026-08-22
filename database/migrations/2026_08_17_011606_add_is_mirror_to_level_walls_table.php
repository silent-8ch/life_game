<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A mirrored run reflects the room back at the player instead of being drawn
 * as a wireframe surface. It still blocks movement like any other wall.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('level_walls', function (Blueprint $table) {
            $table->boolean('is_mirror')->default(false)->after('is_solid');
        });
    }

    public function down(): void
    {
        Schema::table('level_walls', function (Blueprint $table) {
            $table->dropColumn('is_mirror');
        });
    }
};
