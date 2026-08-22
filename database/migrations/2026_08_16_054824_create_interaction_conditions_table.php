<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('interaction_conditions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('interaction_id')->constrained()->cascadeOnDelete();
            $table->string('type');
            $table->string('subject')->comment('Item slug or flag key, depending on the condition type.');
            $table->string('value')->nullable();
            $table->timestamps();

            $table->index('interaction_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('interaction_conditions');
    }
};
