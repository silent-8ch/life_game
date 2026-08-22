<?php

use App\Enums\ThingKind;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Everything in a level that is not the shell of the room: furniture, doors,
 * windows. Each one is an axis-aligned box until it is given a model.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('level_things', function (Blueprint $table) {
            $table->id();
            $table->foreignId('level_id')->constrained()->cascadeOnDelete();
            $table->string('slug');
            $table->string('name');
            $table->text('description');
            $table->string('kind')->default(ThingKind::Prop->value);
            $table->float('x')->comment('Centre of the box on the floor plane.');
            $table->float('z');
            $table->float('elevation')->default(0)->comment('Bottom of the box above the floor.');
            $table->float('width')->comment('Size along local X before rotation.');
            $table->float('depth')->comment('Size along local Z before rotation.');
            $table->float('height');
            $table->float('angle')->default(0)->comment('Yaw in degrees. 0 faces -Z, 90 faces +X.');
            $table->boolean('is_solid')->default(true);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();

            $table->unique(['level_id', 'slug']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('level_things');
    }
};
