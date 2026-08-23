<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What a thing does while an action line is on, and while it is off.
 *
 * A table rather than columns on the thing, because a thing may answer several
 * action lines and one line may drive several things — which is the whole point of
 * an action line, and columns cannot hold it.
 *
 * ## Both values, always, and no sense column
 *
 * `value_on` and `value_off` each say what happens, and Paul settled that:
 * *on and off each say what they do.* The design this replaced had a `sense`
 * column so a binding could describe one side and imply the other, which is a
 * resting default with a worse name — and it makes *what happens when it goes
 * off* something an author can forget to say.
 *
 * Dropping it also made inversion cheaper rather than dearer. A NOT is the two
 * values written the other way round, which needs no column and no concept.
 *
 * ## Why there is no subject
 *
 * A binding lives on the thing it moves. The door carries the binding and the
 * plate carries the line, so the subject is always the row's own thing and a
 * column for it would hold one value for ever. It becomes necessary the day a
 * response targets the player instead — teleport — and that is the day to add
 * it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('level_thing_bindings', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('level_thing_id')->constrained()->cascadeOnDelete();
            $table->string('line')
                ->comment('The line this thing answers. A flag name; they share a namespace.');
            $table->string('response')
                ->comment('rotate or blocking.');
            $table->string('value_on')
                ->comment('What it does while the line is on.');
            $table->string('value_off')
                ->comment('What it does while the line is off. Swap the two for a NOT.');
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();

            $table->index(['level_thing_id', 'line']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('level_thing_bindings');
    }
};
