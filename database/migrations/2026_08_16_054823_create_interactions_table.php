<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('interactions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('hotspot_id')->constrained()->cascadeOnDelete();
            $table->string('verb');
            $table->foreignId('required_item_id')->nullable()->constrained('items')->cascadeOnDelete();
            $table->text('response');
            $table->unsignedSmallInteger('priority')->default(0)->comment('Higher priority interactions are matched first.');
            $table->timestamps();

            $table->index(['hotspot_id', 'verb']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('interactions');
    }
};
