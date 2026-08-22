<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('hotspots', function (Blueprint $table) {
            $table->id();
            $table->foreignId('scene_id')->constrained()->cascadeOnDelete();
            $table->string('slug');
            $table->string('name');
            $table->unsignedTinyInteger('x')->comment('Left edge as a percentage of scene width.');
            $table->unsignedTinyInteger('y')->comment('Top edge as a percentage of scene height.');
            $table->unsignedTinyInteger('width')->comment('Width as a percentage of scene width.');
            $table->unsignedTinyInteger('height')->comment('Height as a percentage of scene height.');
            $table->boolean('is_visible_by_default')->default(true);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();

            $table->unique(['scene_id', 'slug']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('hotspots');
    }
};
