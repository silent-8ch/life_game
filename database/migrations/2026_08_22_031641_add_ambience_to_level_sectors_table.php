<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The sound a room makes when nobody is doing anything in it: rain in the yard,
 * a hum indoors. It sits beside the textures because it is the same kind of
 * thing — a named file in a folder under public, chosen per room, and nothing
 * at all when it is null.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('level_sectors', function (Blueprint $table) {
            $table->string('ambience')->nullable()->after('wall_texture')
                ->comment('Looping room tone, from public/audio/ambience. Silence when null.');
        });
    }

    public function down(): void
    {
        Schema::table('level_sectors', function (Blueprint $table) {
            $table->dropColumn('ambience');
        });
    }
};
