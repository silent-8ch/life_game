<?php

namespace Database\Seeders;

use App\Models\Game;
use App\Models\Level;
use Database\Seeders\Concerns\AuthorsGames;
use Illuminate\Database\Seeder;

/**
 * A square room with a mirror on every wall.
 *
 * Paul's son drew one on the demo and reported it in five words: *some mirrors
 * are black, some are super stretched*. It is the first fault anybody here
 * found by drawing something perfectly reasonable — four mirrored walls in a
 * square room is the obvious thing to try the moment you learn a wall can be a
 * mirror, and level 8's three mirrors, none of them facing each other, had
 * never come near it.
 *
 * So it is a level rather than a note. Four mutually visible panes is the worst
 * case the pane budget has, and it is worth being able to walk into whenever
 * anything about reflections changes. `public/scan.html` reads two spots in
 * here, which is what makes the picture a thing that can be compared rather
 * than a thing that has to be remembered.
 *
 * Eight metres square, open to the sky, and deliberately empty otherwise: the
 * only things in the picture are the four mirrors and whatever they make of
 * each other.
 */
class HallOfMirrorsSeeder extends Seeder
{
    use AuthorsGames;

    public function run(): void
    {
        $game = Game::query()->where('slug', 'life')->first();

        if ($game === null) {
            return;
        }

        $hall = $this->level($game, 'hall-of-mirrors', 'Hall of Mirrors',
            'Four walls, every one of them a mirror.',
            spawnX: 0,
            spawnZ: 2,
            spawnAngle: 0,
            sky: 'sky-day-1',
        );

        // Every side, which is what makes it the hard case: each pane can see
        // the other three, so the recursion branches three ways at every level
        // and asks for far more draws than any budget will give it.
        $this->boxRoom($hall, 'room', 'Room', -4, -4, 4, 4,
            floor: 'checker-floor',
            walls: 'concrete-wall',
            sky: true,
            mirrors: ['north', 'east', 'south', 'west'],
        );
    }
}
