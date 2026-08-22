<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * An interaction used to belong to a hotspot, which only the scene games have.
 * The first-person levels want the same thing — a verb, some conditions, and
 * what it does — hung on a thing standing in a room instead.
 *
 * Two nullable keys rather than one polymorphic pair, so that deleting either
 * owner still takes its interactions with it. A map save rebuilds a level's
 * things from scratch, and that has to leave nothing behind.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('interactions', function (Blueprint $table): void {
            $table->foreignId('hotspot_id')->nullable()->change();

            $table->foreignId('level_thing_id')
                ->nullable()
                ->after('hotspot_id')
                ->constrained()
                ->cascadeOnDelete();

            $table->index(['level_thing_id', 'verb']);
        });
    }

    public function down(): void
    {
        Schema::table('interactions', function (Blueprint $table): void {
            $table->dropIndex(['level_thing_id', 'verb']);
            $table->dropConstrainedForeignId('level_thing_id');
        });
    }
};
