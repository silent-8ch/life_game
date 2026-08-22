<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Who the player is, level by level. They are drawn from the same sheets as
 * everybody else and only ever seen in a mirror or through a portal, so this is
 * a sprite name like any other.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('levels', function (Blueprint $table) {
            $table->string('player_sprite')
                ->default('paul')
                ->after('description')
                ->comment('Sprite sheet the player themselves is drawn from.');
        });
    }

    public function down(): void
    {
        Schema::table('levels', function (Blueprint $table) {
            $table->dropColumn('player_sprite');
        });
    }
};
