<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The line becomes the thing you author.
 *
 * Paul: *I need to be able to chain items. I think we need to switch to manual
 * drawing of the redstone line.*
 *
 * The first slice connected things by **sharing a name** — a plate put
 * `utility-plate` on and a door answered `utility-plate`. That works and it is
 * invisible: nothing in the editor shows you the connection, and a chain of
 * three things means inventing two names that exist only to join them.
 *
 * A drawn line is an edge from one thing to another, and a chain is two edges
 * with nothing typed at all. So `emits` goes — a source no longer needs a name
 * to be connected to anything — and `level_action_lines` arrives.
 *
 * ## What replaces the name for the few things that still need one
 *
 * A **listener**: a thing that reads a flag into its output, or writes its
 * input to a flag. That is the only bridge between drawn wiring and the name
 * namespace, which keeps `FlagIs`, `alt_flag` and the save reachable while
 * stopping a name from existing merely to join two things.
 *
 * It is also where the flag-write guard moves to. Line names and flag names
 * share a namespace, so an endpoint that wrote what it was given would let the
 * browser set any flag — including the ones every lock is gated on. The check
 * was *something in this level emits that name*; it is now **only a listener
 * may write a flag, and only the name it declares.** Same guarantee, new place
 * to stand.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('level_action_lines', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('level_id')->constrained()->cascadeOnDelete();
            $table->foreignId('from_thing_id')->constrained('level_things')->cascadeOnDelete();
            $table->foreignId('to_thing_id')->constrained('level_things')->cascadeOnDelete();
            $table->timestamps();

            // One line between the same two things in the same direction. Two
            // would be one line drawn twice and would say nothing extra.
            $table->unique(['from_thing_id', 'to_thing_id']);
        });

        Schema::table('level_things', function (Blueprint $table): void {
            // A source no longer needs a name to be connected to anything.
            $table->dropColumn('emits');

            $table->string('logic')
                ->default('any')
                ->after('emit_when')
                ->comment('How lines drawn into it combine: any, all or none. A gate is a thing with an opinion here.');

            $table->string('reads_flag')
                ->nullable()
                ->after('logic')
                ->comment('A listener: its output is on while this flag is set.');

            $table->string('writes_flag')
                ->nullable()
                ->after('reads_flag')
                ->comment('A listener: this flag is set while its input is on. The only way a flag is written from the browser.');
        });

        // The index over `(level_thing_id, line)` has to go before the column
        // does, and the two databases disagree about how.
        //
        // **SQLite** will not drop a column an index still names, and says so
        // only once it has begun. **MySQL** will not drop that index at all —
        // the foreign key on `level_thing_id` is leaning on it, and it refuses
        // rather than silently leaving the key unindexed. It does not need to
        // be asked: dropping the column rewrites the index down to the columns
        // that are left, which is the one the foreign key wanted.
        //
        // So the drop is asked for where it is required and not where it is
        // refused. This is the shape of difference the move to MySQL was
        // expected to turn up, and it turned this one up on the first run.
        if (Schema::getConnection()->getDriverName() === 'sqlite') {
            Schema::table('level_thing_bindings', function (Blueprint $table): void {
                $table->dropIndex(['level_thing_id', 'line']);
            });
        }

        Schema::table('level_thing_bindings', function (Blueprint $table): void {
            // A binding answers the thing's own input now, so there is nothing
            // left for it to name.
            $table->dropColumn('line');
        });
    }

    public function down(): void
    {
        Schema::table('level_thing_bindings', function (Blueprint $table): void {
            $table->string('line')->default('');
            $table->index(['level_thing_id', 'line']);
        });

        Schema::table('level_things', function (Blueprint $table): void {
            $table->dropColumn(['logic', 'reads_flag', 'writes_flag']);
            $table->string('emits')->nullable()->after('hinge');
        });

        Schema::dropIfExists('level_action_lines');
    }
};
