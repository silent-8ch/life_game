<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One flat wall segment, drawn from (x1, z1) to (x2, z2) and extruded upwards.
 * A segment that stops short of the ceiling leaves a hole for a window or a door.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('level_walls', function (Blueprint $table) {
            $table->id();
            $table->foreignId('level_id')->constrained()->cascadeOnDelete();
            $table->float('x1');
            $table->float('z1');
            $table->float('x2');
            $table->float('z2');
            $table->float('elevation')->default(0)->comment('Height of the bottom edge above the floor.');
            $table->float('height')->comment('How far the segment rises from its elevation.');
            $table->boolean('is_solid')->default(true)->comment('Whether the player collides with it.');
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('level_walls');
    }
};
