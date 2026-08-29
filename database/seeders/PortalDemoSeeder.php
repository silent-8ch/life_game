<?php

namespace Database\Seeders;

use App\Models\Game;
use App\Models\Level;
use Database\Seeders\Concerns\AuthorsGames;
use Illuminate\Database\Seeder;

/**
 * A level built to show what portals do. Two pairs of linked walls:
 *
 * - `long-hall` joins the end of one corridor to the start of another that
 *   stands somewhere else entirely on the plan, both facing the same way. Walk
 *   north and you keep walking north, down a corridor longer than the level is,
 *   with nothing to tell you where the join was. That is the Doom trick.
 * - `turn` joins two walls at right angles, so walking east out of the alcove
 *   puts the player out heading south. Nothing about the level is Euclidean
 *   after that, which is the point.
 *
 * The far room also has a mirror on each of its facing walls, which is the
 * hardest thing the renderer is asked to draw.
 */
class PortalDemoSeeder extends Seeder
{
    use AuthorsGames;

    public function run(): void
    {
        $game = Game::query()->where('slug', 'life')->sole();

        $level = $this->level(
            $game,
            slug: 'portals',
            name: 'Portal Demo',
            description: 'Two pairs of linked walls, and a corridor longer than the room it is in.',
            spawnX: 4,
            spawnZ: 6,
            spawnAngle: 0,
            ceilingHeight: 3.0,
            sky: 'sky-day-1',
            backdrop: 'hills',
        );

        $this->hub($level);
        $this->longHall($level);
        $this->rightAngle($level);
        $this->chamber($level);
    }

    /**
     * Where the player starts: a doorway north into the first corridor and one
     * east into the alcove.
     */
    private function hub(Level $level): void
    {
        $this->boxRoom(
            $level,
            slug: 'hub',
            name: 'Hub',
            x1: 0,
            z1: 0,
            x2: 8,
            z2: 8,
            doors: [
                'north' => [[3, 5]],
                'east' => [[3, 5]],
                'south' => [[3, 5]],
            ],
            floor: 'checker-floor',
            ceiling: 'cream-plaster-wall',
            walls: 'concrete-wall',
        );
    }

    /**
     * The corridor that carries on somewhere else. Both mouths face north, so
     * the turn through the portal is nothing at all and the join is invisible.
     */
    private function longHall(Level $level): void
    {
        $this->boxRoom(
            $level,
            slug: 'near-hall',
            name: 'Near Hall',
            x1: 3,
            z1: -10,
            x2: 5,
            z2: 0,
            doors: ['south' => [[3, 5]]],
            floor: 'dark-wood-floor',
            ceiling: 'cream-plaster-wall',
            walls: 'wood-panel-wall',
            portals: ['north' => 'long-hall'],
        );

        $this->boxRoom(
            $level,
            slug: 'far-hall',
            name: 'Far Hall',
            x1: 20,
            z1: -10,
            x2: 22,
            z2: 0,
            doors: ['north' => [[20, 22]]],
            floor: 'dark-wood-floor',
            ceiling: 'cream-plaster-wall',
            walls: 'wood-panel-wall',
            portals: ['south' => 'long-hall'],
        );

        // What the corridor arrives at, so there is somewhere to come out into.
        $this->boxRoom(
            $level,
            slug: 'gallery',
            name: 'Gallery',
            x1: 16,
            z1: -20,
            x2: 26,
            z2: -10,
            doors: ['south' => [[20, 22]]],
            floor: 'marble-floor',
            ceiling: 'cream-plaster-wall',
            walls: 'painted-brick-wall',
            mirrors: ['east', 'west'],
        );
    }

    /**
     * A room whose two facing walls are the one portal. Walk into the east wall
     * and you come out of the west wall of the same room, so looking east shows
     * the room from beyond its own west wall — with the player standing in it,
     * back turned, and the east wall beyond them showing it again. There is no
     * end to the room in that direction.
     */
    private function chamber(Level $level): void
    {
        $this->boxRoom(
            $level,
            slug: 'chamber',
            name: 'Chamber',
            x1: 0,
            z1: 8,
            x2: 8,
            z2: 16,
            doors: ['north' => [[3, 5]]],
            floor: 'mosaic-tile',
            ceiling: 'cream-plaster-wall',
            walls: 'fieldstone-wall',
            portals: ['east' => 'loop', 'west' => 'loop'],
        );
    }

    /**
     * The pair that turns a corner: out of the alcove heading east, into the
     * second corridor heading south.
     */
    private function rightAngle(Level $level): void
    {
        $this->boxRoom(
            $level,
            slug: 'alcove',
            name: 'Alcove',
            x1: 8,
            z1: 3,
            x2: 10,
            z2: 5,
            doors: ['west' => [[3, 5]]],
            floor: 'kitchen-tile',
            ceiling: 'cream-plaster-wall',
            walls: 'subway-tile-wall',
            portals: ['east' => 'turn'],
        );

        $this->boxRoom(
            $level,
            slug: 'turn-hall',
            name: 'Turn Hall',
            x1: 14,
            z1: 6,
            x2: 16,
            z2: 16,
            doors: ['south' => [[14, 16]]],
            floor: 'kitchen-tile',
            ceiling: 'cream-plaster-wall',
            walls: 'subway-tile-wall',
            portals: ['north' => 'turn'],
        );

        $this->boxRoom(
            $level,
            slug: 'twist',
            name: 'Twist Room',
            x1: 10,
            z1: 16,
            x2: 20,
            z2: 26,
            doors: ['north' => [[14, 16]]],
            floor: 'mosaic-tile',
            ceiling: 'cream-plaster-wall',
            walls: 'stucco-wall',
        );
    }
}
