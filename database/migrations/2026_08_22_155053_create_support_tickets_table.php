<?php

use App\Enums\TicketStatus;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * "This is wrong", said from where the player was standing.
 *
 * Very nearly the payload a debug snapshot already carries, and deliberately a
 * second thing beside it rather than a replacement. A snapshot is scaffolding —
 * files in a folder that can be deleted when the fault it was chasing is gone,
 * and refused outside local, and that reasoning is still right for what it is.
 * A ticket is the opposite on both counts: it comes from somebody playing, and
 * it has to persist, be listed, be found again and be marked dealt with. Rows.
 *
 * The pictures live on the disk with their paths in `support_ticket_shots`, one
 * row each, rather than in a column here. Three of them is what the client
 * sends today — the ordinary frame, a wireframe, and the colour-coded walls —
 * but which pictures a ticket carries is a thing that will change, and a row
 * apiece makes that a question about data rather than a migration.
 *
 * The spot is spelled out as the same numbers `?at=` takes and a snapshot
 * writes: x, z, eye, and the player's own yaw and pitch in degrees. Not the
 * level's spawn angle, which is the negative of yaw.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('support_tickets', function (Blueprint $table) {
            $table->id();

            $table->foreignId('game_id')->constrained()->cascadeOnDelete();

            // Nullable and severed rather than cascaded: a ticket about a level
            // outlives the level, and "the room this happened in has since been
            // deleted" is worth knowing rather than losing the report over.
            $table->foreignId('level_id')->nullable()->constrained()->nullOnDelete();

            // Kept beside the id for that reason — the slug still reads as a
            // place after the row it pointed at is gone.
            $table->string('level_slug');

            // Nullable because playing does not require an account. Only the
            // editor and the admin panel sit behind a login; anybody may walk
            // around a published game, so anybody may report one.
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();

            $table->text('note')->nullable();

            $table->float('at_x');
            $table->float('at_z');
            $table->float('at_eye');
            $table->float('at_yaw')->comment("The player's own yaw in degrees — not a level's spawn angle, which is its negative.");
            $table->float('at_pitch');

            $table->string('standing_in')->nullable()->comment('Sector slug, or null if they were outside every room.');
            $table->string('looking_at')->nullable();
            $table->string('holding')->nullable();
            $table->boolean('is_running')->default(false);

            // Whole shapes rather than columns each. Nothing queries inside
            // them; they are read by a person looking at one ticket.
            $table->json('screen');
            $table->json('nearby')->comment('The boundaries within reach, nearest first — where almost every reported fault turns out to be.');

            // Without this the colour-coded picture is a file that looks like
            // evidence and decodes to nothing. `paintWalls` hands out colours
            // by walking the scene graph with a running counter, so which
            // colour is which wall belongs to *that build of that level* and
            // cannot be recovered from the pixels. `scanRow` takes the legend
            // as an argument for the same reason.
            //
            // It is also the half another agent can compute on. The pictures
            // are what a person looks at; this is what a machine reads.
            $table->json('legend')->nullable()->comment('Colour to wall, for the walls picture. Useless separated from it, and it from nothing without this.');

            $table->string('status')->default(TicketStatus::Open->value);
            $table->timestamp('resolved_at')->nullable();

            $table->timestamps();

            $table->index(['status', 'created_at']);
            $table->index('level_slug');
        });

        Schema::create('support_ticket_shots', function (Blueprint $table) {
            $table->id();

            $table->foreignId('support_ticket_id')->constrained()->cascadeOnDelete();

            $table->string('kind')->comment('Which view this is: normal, wireframe, walls.');
            $table->string('path')->comment('On the local disk, never the public one — these are bytes from whoever was playing.');
            $table->unsignedInteger('bytes');

            $table->timestamps();

            $table->unique(['support_ticket_id', 'kind']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('support_ticket_shots');
        Schema::dropIfExists('support_tickets');
    }
};
