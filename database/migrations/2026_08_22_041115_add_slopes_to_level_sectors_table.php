<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Floors and ceilings that are not level.
 *
 * A slope is a base height along a chosen wall — the hinge — plus a rise per
 * metre measured straight into the room. So `floor_height` stops meaning "the
 * height of this floor" and starts meaning "the height of this floor along its
 * hinge wall", which is the same convention Build uses and is what makes two
 * rooms line up for free: hinge them on the wall they share, give them the same
 * base height, and they meet flush there while each rises into its own room,
 * because the inward normal points opposite ways for the two sides.
 *
 * A slope of zero with no hinge is a flat surface, which is every row already
 * in the table.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('level_sectors', function (Blueprint $table) {
            $table->float('floor_slope')
                ->default(0)
                ->after('ceiling_height')
                ->comment('Rise in metres per metre, measured into the room from the hinge wall.');

            $table->unsignedSmallInteger('floor_slope_edge')
                ->nullable()
                ->after('floor_slope')
                ->comment("The hinge wall, as an edge's sort_order. Null means flat.");

            $table->float('ceiling_slope')
                ->default(0)
                ->after('floor_slope_edge')
                ->comment('Rise in metres per metre, measured into the room from the hinge wall.');

            $table->unsignedSmallInteger('ceiling_slope_edge')
                ->nullable()
                ->after('ceiling_slope')
                ->comment("The hinge wall, as an edge's sort_order. Null means flat.");
        });
    }

    public function down(): void
    {
        Schema::table('level_sectors', function (Blueprint $table) {
            $table->dropColumn([
                'floor_slope',
                'floor_slope_edge',
                'ceiling_slope',
                'ceiling_slope_edge',
            ]);
        });
    }
};
