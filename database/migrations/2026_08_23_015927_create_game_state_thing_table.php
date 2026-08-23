<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What a save has done to a thing: turned it, or stopped it blocking.
 *
 * The same shape the hotspot overrides already use, and for the same reason. A
 * level says how a thing was authored; a save says what has happened to it
 * since. Writing the second onto the first would mean a level could not be
 * played twice.
 *
 * Only things that have been acted on are here at all, so absent means *as
 * authored* — the same reading `game_flags` gets, and the reason neither column
 * needs a default that means nothing.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('game_state_thing', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('game_state_id')->constrained()->cascadeOnDelete();
            $table->foreignId('level_thing_id')->constrained()->cascadeOnDelete();
            $table->float('turned')
                ->nullable()
                ->comment('Degrees about its hinge. Null means it has not been turned.');
            $table->boolean('blocking')
                ->nullable()
                ->comment('Whether it stops anybody walking through. Null means as authored.');
            $table->timestamps();

            $table->unique(['game_state_id', 'level_thing_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('game_state_thing');
    }
};
