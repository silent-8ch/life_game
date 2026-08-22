<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What a sky sector shows: one sky gradient, and a stack of horizon layers that
 * drift at their own speeds as the player turns and walks.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('levels', function (Blueprint $table) {
            $table->string('sky_image')->nullable()->after('ceiling_height')->comment('sky-day, sky-night, sky-sunset.');
            $table->unsignedTinyInteger('sky_variant')->default(0)->after('sky_image')->comment('Which of the four cells in the sky strip.');
            $table->string('backdrop_theme')->nullable()->after('sky_variant')->comment('hills, skyline, forest, and so on.');
            $table->json('backdrop_layers')->nullable()->after('backdrop_theme')->comment('Which numbered layers of the theme to stack, furthest first.');
        });
    }

    public function down(): void
    {
        Schema::table('levels', function (Blueprint $table) {
            $table->dropColumn(['sky_image', 'sky_variant', 'backdrop_theme', 'backdrop_layers']);
        });
    }
};
