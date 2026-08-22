<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A first-person room. World units are metres, +X east, +Z south, +Y up.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('levels', function (Blueprint $table) {
            $table->id();
            $table->foreignId('game_id')->constrained()->cascadeOnDelete();
            $table->string('slug');
            $table->string('name');
            $table->text('description');
            $table->float('spawn_x')->comment('Where the player stands on entering.');
            $table->float('spawn_z');
            $table->float('spawn_angle')->default(0)->comment('Yaw in degrees. 0 faces -Z, 90 faces +X.');
            $table->float('ceiling_height')->default(3);
            $table->string('wall_color')->default('#3ddc84');
            $table->string('floor_color')->default('#1f6f43');
            $table->string('accent_color')->default('#f0b429')->comment('Props, doors, and anything interactive.');
            $table->timestamps();

            $table->unique(['game_id', 'slug']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('levels');
    }
};
