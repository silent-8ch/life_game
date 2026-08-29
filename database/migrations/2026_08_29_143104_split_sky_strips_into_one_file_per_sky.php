<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * A sky is one file. `sky_image` names it and there is nothing else to say.
 *
 * The panoramas used to be packed four to a 4096x512 strip and picked as a file
 * plus a cell number, which quietly assumed every sky file held exactly four of
 * them. A single-image sky dropped into the folder was therefore sliced into
 * quarters and each quarter stretched around the whole dome — which is what
 * happened to `sky-city.png`, and is why the packing is gone.
 *
 * The strips are cut into twelve files (`sky-day-1.png` and so on) and retired
 * to `public/sprites/bg/retired/strips`. `sky_variant` is folded into the name:
 * `sky-day` cell 0 becomes `sky-day-1`, one-based, because a person counting
 * skies starts at one.
 */
return new class extends Migration
{
    /**
     * The strips that were cut up. Only these are rewritten — anything else in
     * the column is a name this migration knows nothing about and is safer left
     * exactly as it is than guessed at.
     *
     * @var list<string>
     */
    private const STRIPS = ['sky-day', 'sky-night', 'sky-sunset'];

    public function up(): void
    {
        // Row by row rather than one `update` with a concatenation in it:
        // SQLite spells that `||` and MySQL spells it `CONCAT`, the tests run
        // on the first and the app on the second, and there are a few dozen
        // levels. Not worth a driver branch.
        DB::table('levels')
            ->whereIn('sky_image', self::STRIPS)
            ->orderBy('id')
            ->select(['id', 'sky_image', 'sky_variant'])
            ->each(function (object $level): void {
                DB::table('levels')
                    ->where('id', $level->id)
                    ->update(['sky_image' => $level->sky_image.'-'.($level->sky_variant + 1)]);
            });

        Schema::table('levels', function (Blueprint $table): void {
            $table->dropColumn('sky_variant');
        });
    }

    public function down(): void
    {
        Schema::table('levels', function (Blueprint $table): void {
            $table->unsignedTinyInteger('sky_variant')
                ->default(0)
                ->after('sky_image')
                ->comment('Which of the four cells in the sky strip.');
        });

        foreach (self::STRIPS as $strip) {
            for ($cell = 1; $cell <= 4; $cell++) {
                DB::table('levels')
                    ->where('sky_image', "{$strip}-{$cell}")
                    ->update(['sky_image' => $strip, 'sky_variant' => $cell - 1]);
            }
        }
    }
};
