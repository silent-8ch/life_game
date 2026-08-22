<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One side of a sector's polygon, running from its own vertex to the next one
 * in order. An edge shared with another sector is a way through rather than a
 * wall — only the step between differing floors and ceilings gets built —
 * unless it is marked as blocking, which is how two rooms share a boundary
 * that has a doorway in only part of it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('level_sector_edges', function (Blueprint $table) {
            $table->id();
            $table->foreignId('sector_id')->constrained('level_sectors')->cascadeOnDelete();
            $table->foreignId('vertex_id')->constrained('level_vertices')->cascadeOnDelete();
            $table->unsignedSmallInteger('sort_order')->comment('Position in the polygon, wound clockwise.');
            $table->string('wall_texture')->nullable();
            $table->boolean('blocks')->default(false)->comment('A shared edge that is still a wall, not a way through.');
            $table->boolean('is_mirror')->default(false);
            $table->timestamps();

            $table->unique(['sector_id', 'sort_order']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('level_sector_edges');
    }
};
