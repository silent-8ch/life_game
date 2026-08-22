<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A thing with a sprite is an actor rather than a box: it is drawn from the
 * eight painted angles of its sprite sheets, and may wander about.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('level_things', function (Blueprint $table) {
            $table->string('sprite')->nullable()->after('kind')->comment('Sprite sheet name, such as krystal.');
            $table->string('behaviour')->nullable()->after('sprite')->comment('How the actor moves: still, wander.');
            $table->float('speed')->default(1.1)->after('behaviour')->comment('Metres per second when moving.');
            $table->string('texture')->nullable()->after('speed')->comment('Texture for a boxed thing.');
        });
    }

    public function down(): void
    {
        Schema::table('level_things', function (Blueprint $table) {
            $table->dropColumn(['sprite', 'behaviour', 'speed', 'texture']);
        });
    }
};
