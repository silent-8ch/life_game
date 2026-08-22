<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('scenes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('game_id')->constrained()->cascadeOnDelete();
            $table->string('slug');
            $table->string('name');
            $table->text('description');
            $table->string('background_image')->nullable();
            $table->string('background_color')->default('#1b1b18');
            $table->timestamps();

            $table->unique(['game_id', 'slug']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('scenes');
    }
};
