<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('game_state_hotspot', function (Blueprint $table) {
            $table->id();
            $table->foreignId('game_state_id')->constrained()->cascadeOnDelete();
            $table->foreignId('hotspot_id')->constrained()->cascadeOnDelete();
            $table->boolean('is_visible')->comment('Overrides the hotspot default visibility.');
            $table->timestamps();

            $table->unique(['game_state_id', 'hotspot_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('game_state_hotspot');
    }
};
