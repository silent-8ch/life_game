<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Who drew a level.
 *
 * Nullable on purpose and it stays that way: every level that existed before
 * anybody had an account belongs to nobody, and an orphan is a real state
 * rather than a gap waiting to be filled. `nullOnDelete` so removing a person
 * orphans their levels instead of deleting the afternoon they spent on them.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('levels', function (Blueprint $table): void {
            $table->foreignId('owner_id')->nullable()->after('game_id')
                ->constrained('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('levels', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('owner_id');
        });
    }
};
