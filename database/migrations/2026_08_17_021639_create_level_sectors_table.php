<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A room: a closed polygon of vertices with a floor and a ceiling at their own
 * heights. A sector open to the sky has no ceiling drawn at all.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('level_sectors', function (Blueprint $table) {
            $table->id();
            $table->foreignId('level_id')->constrained()->cascadeOnDelete();
            $table->string('slug');
            $table->string('name');
            $table->float('floor_height')->default(0);
            $table->float('ceiling_height')->default(3);
            $table->string('floor_texture')->nullable();
            $table->string('ceiling_texture')->nullable();
            $table->string('wall_texture')->nullable()->comment('Default for edges that do not name their own.');
            $table->boolean('is_sky')->default(false)->comment('Open to the sky instead of having a ceiling.');
            $table->boolean('is_water')->default(false)->comment('Floor runs the water animation and you wade in it.');
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();

            $table->unique(['level_id', 'slug']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('level_sectors');
    }
};
