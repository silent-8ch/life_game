<?php

use App\Enums\ThingRender;
use App\Enums\ThingUvMode;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What a thing looks like, beyond being a box with a texture on it.
 *
 * Every default here is what a row already in the table was doing implicitly,
 * so nothing that exists changes appearance when this runs: a box, tiled at the
 * wall scale, one texture, one frame.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('level_things', function (Blueprint $table) {
            $table->string('render')
                ->default(ThingRender::Box->value)
                ->after('texture')
                ->comment('box, billboard or cross — how the thing is put on the screen.');

            $table->unsignedTinyInteger('plane_count')
                ->default(2)
                ->after('render')
                ->comment('Quads in the star, for cross props only. 2 or 3.');

            $table->string('uv_mode')
                ->default(ThingUvMode::Tile->value)
                ->after('plane_count')
                ->comment('tile repeats the texture at the wall scale; fit stretches it once per face.');

            $table->string('texture_alt')
                ->nullable()
                ->after('uv_mode')
                ->comment('The texture to draw instead while alt_flag is set. Null unless alt_flag is too.');

            $table->string('alt_flag')
                ->nullable()
                ->after('texture_alt')
                ->comment('The game flag that swaps in texture_alt. Null unless texture_alt is too.');

            $table->unsignedTinyInteger('animation_frames')
                ->default(1)
                ->after('alt_flag')
                ->comment('Frames across the texture strip. 1 means a still picture.');

            $table->float('animation_fps')
                ->default(8)
                ->after('animation_frames')
                ->comment('How fast those frames advance.');
        });
    }

    public function down(): void
    {
        Schema::table('level_things', function (Blueprint $table) {
            $table->dropColumn([
                'render',
                'plane_count',
                'uv_mode',
                'texture_alt',
                'alt_flag',
                'animation_frames',
                'animation_fps',
            ]);
        });
    }
};
