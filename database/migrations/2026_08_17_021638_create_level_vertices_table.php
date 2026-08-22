<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A corner on the floor plan. Sectors are drawn by joining these up, and two
 * sectors sharing a pair of them share a wall.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('level_vertices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('level_id')->constrained()->cascadeOnDelete();
            $table->float('x');
            $table->float('z');
            $table->timestamps();

            $table->index(['level_id', 'x', 'z']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('level_vertices');
    }
};
