<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A ticket records what the room *is*, not only what it is called — and gives
 * an editor ticket somewhere to say what was being edited.
 *
 * `standing_in` was a slug. That is enough to say where somebody was and not
 * enough to say what they were looking at, and the difference is not
 * hypothetical: an evening went into chasing a green grid across three sessions
 * when `floorTexture: null` for the room the reporter stood in would have
 * answered it in a line. The engine already assembles all of this —
 * `describeSpot()` in snapshot.ts returns the room's textures, its floor and
 * ceiling *under that spot* rather than its base heights, and its sky and water
 * flags — and the endpoint was throwing it away to fit a string column.
 *
 * The slug stays beside it in its own column. Widening should not cost the one
 * thing the old column could do: the admin table filters and sorts by room, and
 * a JSON column cannot be indexed for that here.
 *
 * `editor_state` is the other half. Every context column on this table —
 * standing in, looking at, holding, running — is a play concept, so a ticket
 * raised in the editor had a note, a level and two pictures and no way to say
 * what was happening. What is happening in the editor is the editing: which
 * tool, what was selected, how many rooms, how deep the history is, and above
 * all whether there were unsaved changes at that moment.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('support_tickets', function (Blueprint $table): void {
            $table->renameColumn('standing_in', 'standing_in_slug');
        });

        Schema::table('support_tickets', function (Blueprint $table): void {
            // The whole room as the engine described it, beside the slug.
            $table->json('standing_in')->nullable()->after('standing_in_slug')
                ->comment('The room as describeSpot() reported it: textures, the floor and ceiling under that exact spot, sky and water.');

            // Bounded by the request rules rather than by the column, the same
            // way `nearby` and `screen` are. Nothing queries inside it.
            $table->json('editor_state')->nullable()->after('is_running')
                ->comment('What was being edited when an editor ticket was raised: tool, selection, room count, history depth, unsaved changes.');
        });
    }

    public function down(): void
    {
        Schema::table('support_tickets', function (Blueprint $table): void {
            $table->dropColumn(['standing_in', 'editor_state']);
        });

        Schema::table('support_tickets', function (Blueprint $table): void {
            $table->renameColumn('standing_in_slug', 'standing_in');
        });
    }
};
