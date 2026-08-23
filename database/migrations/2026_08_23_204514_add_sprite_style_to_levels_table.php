<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Which art style a level's people are drawn from, level by level. The default
 * is the one style there has always been, so every existing level is unchanged;
 * a level can opt into another set of sheets — a stylised Paul and Krystal, say
 * — without disturbing the rest.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('levels', function (Blueprint $table) {
            $table->string('sprite_style')
                ->default('realistic')
                ->after('player_sprite')
                ->comment('Which sprites/<style> folder the people are drawn from.');
        });
    }

    public function down(): void
    {
        Schema::table('levels', function (Blueprint $table) {
            $table->dropColumn('sprite_style');
        });
    }
};
