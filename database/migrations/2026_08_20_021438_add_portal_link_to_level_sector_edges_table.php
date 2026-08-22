<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A portal is a pair of walls that share a link name. Walking into one puts the
 * player out of the other, carrying their position along the wall, their
 * heading and their speed through the turn between the two — so a pair laid out
 * to match cannot be told from an ordinary doorway, which is the trick Doom
 * levels use to stack one room over another.
 *
 * Pairing is by name rather than by id because a map save rebuilds every edge
 * row, so ids do not survive from one save to the next.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('level_sector_edges', function (Blueprint $table) {
            $table->string('portal_link', 64)
                ->nullable()
                ->index()
                ->comment('Two edges naming the same link are the two ends of one portal.');
        });
    }

    public function down(): void
    {
        Schema::table('level_sector_edges', function (Blueprint $table) {
            $table->dropIndex(['portal_link']);
            $table->dropColumn('portal_link');
        });
    }
};
