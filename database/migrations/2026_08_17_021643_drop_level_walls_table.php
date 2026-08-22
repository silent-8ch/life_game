<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Walls are no longer authored on their own: they are the edges between
 * sectors, and are built from the sector polygons instead.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::dropIfExists('level_walls');
    }

    public function down(): void
    {
        Schema::create('level_walls', function (Blueprint $table) {
            $table->id();
            $table->foreignId('level_id')->constrained()->cascadeOnDelete();
            $table->float('x1');
            $table->float('z1');
            $table->float('x2');
            $table->float('z2');
            $table->float('elevation')->default(0);
            $table->float('height');
            $table->boolean('is_solid')->default(true);
            $table->boolean('is_mirror')->default(false);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();
        });
    }
};
