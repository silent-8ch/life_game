<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A person's stat block, when this particular person is not the one the sprite
 * says they are. Null — which is what every row already there gets — means
 * "whatever PersonStats says about their sprite"; a value is a complete block
 * of all seven, never a partial merge.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('level_things', function (Blueprint $table) {
            $table->json('stats')
                ->nullable()
                ->after('behaviour')
                ->comment('A complete SPECIAL block overriding the sprite\'s starting one, or null to inherit it.');
        });
    }

    public function down(): void
    {
        Schema::table('level_things', function (Blueprint $table) {
            $table->dropColumn('stats');
        });
    }
};
