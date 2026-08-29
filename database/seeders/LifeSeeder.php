<?php

namespace Database\Seeders;

use App\Enums\ThingKind;
use Database\Seeders\Concerns\AuthorsGames;
use Illuminate\Database\Seeder;

/**
 * The first-person game. One level to start with, built to exercise everything
 * the engine can do: sectors at different heights, textures, a mirrored wall,
 * a sector open to the parallax sky, water you can wade into, and someone
 * else wandering about.
 */
class LifeSeeder extends Seeder
{
    use AuthorsGames;

    public function run(): void
    {
        $game = $this->game(
            'life',
            'Life',
            'One room, one door, and the rest of it still ahead of you.',
            sortOrder: 1,
        );

        $demo = $this->level(
            $game,
            'tech-demo',
            'Tech Demo',
            'A room, a door left open, and a garden that goes on further than it should.',
            spawnX: 2.6,
            spawnZ: 4.4,
            spawnAngle: 45,
            ceilingHeight: 3.0,
            sky: 'sky-day-1',
            backdrop: 'hills',
            backdropLayers: [1, 2, 3],
        );

        $game->update(['starting_level_id' => $demo->id]);

        // The room. Its south wall is a mirror, and the gap in the east wall
        // between z 2.4 and 3.6 is shared with the terrace, making a doorway.
        $this->sector($demo, 'hall', 'The Room', [
            [0, 0], [8, 0], [8, 2.4], [8, 3.6], [8, 6], [0, 6],
        ],
            floorHeight: 0,
            ceilingHeight: 3.0,
            floor: 'oak-floor',
            ceiling: 'cream-plaster-wall',
            walls: 'cream-plaster-wall',
            edgeTextures: [0 => 'floral-wallpaper'],
            mirrors: [4],
            solidEdges: [1, 3],
        );

        // Outside: a step down, no ceiling at all, and stone underfoot.
        $this->sector($demo, 'terrace', 'The Terrace', [
            [8, 0], [16, 0], [16, 6], [8, 6], [8, 3.6], [8, 2.4],
        ],
            floorHeight: -0.4,
            ceilingHeight: 2.4,
            floor: 'spring-grass',
            walls: 'fieldstone-wall',
            sky: true,
            edgeTextures: [1 => 'red-brick-path'],
            solidEdges: [3, 5],
        );

        // The pond runs the water animation, and you can wade into it.
        $this->sector($demo, 'pond', 'The Pond', [
            [8, 6], [16, 6], [16, 10], [8, 10],
        ],
            floorHeight: -0.9,
            ceilingHeight: 2.4,
            floor: 'pond-water',
            walls: 'fieldstone-wall',
            sky: true,
            water: true,
        );

        $this->thing($demo, 'bed', 'Bed',
            'Single, unmade, pushed against the wall the way it was when you moved in.',
            x: 0.9, z: 1.6, width: 1.4, depth: 2.0, height: 0.55,
            texture: 'cream-carpet',
        );

        $this->thing($demo, 'wardrobe', 'Wardrobe',
            'Chipboard, and one door short of closing properly.',
            x: 0.4, z: 4.6, width: 0.7, depth: 1.4, height: 2.1,
            texture: 'wood-panel-wall',
        );

        $this->thing($demo, 'desk', 'Desk',
            'Under the window, which is the only thing recommending it.',
            x: 5.6, z: 0.45, width: 1.6, depth: 0.7, height: 0.75,
            texture: 'dark-wood-floor',
        );

        $this->thing($demo, 'chair', 'Chair',
            'It swivels. That is the whole of what it does.',
            x: 5.6, z: 1.35, width: 0.5, depth: 0.5, height: 0.9,
            texture: 'dark-wood-floor',
        );

        $this->thing($demo, 'lamp', 'Desk Lamp',
            'On, because you left it on.',
            x: 6.2, z: 0.4, width: 0.25, depth: 0.25, height: 0.45,
            kind: ThingKind::Fixture, elevation: 0.75, solid: false,
        );

        $this->thing($demo, 'counter', 'Kitchen Counter',
            'A hob, a kettle, and not enough space between them.',
            x: 7.6, z: 0.9, width: 0.7, depth: 1.6, height: 0.9,
            texture: 'kitchen-tile',
        );

        $this->thing($demo, 'fridge', 'Fridge',
            'It hums at a pitch you stopped hearing weeks ago.',
            x: 7.55, z: 5.2, width: 0.7, depth: 0.7, height: 1.8,
            texture: 'white-siding',
        );

        $this->thing($demo, 'rug', 'Rug',
            'It covers the worst of the floor.',
            x: 3.6, z: 3.2, width: 2.6, depth: 2.0, height: 0.02,
            solid: false, texture: 'floral-rug',
        );

        $this->thing($demo, 'window', 'Window',
            'Rooftops, aerials, and weather coming in from the left.',
            x: 3.0, z: 0.05, width: 2.0, depth: 0.1, height: 1.4,
            kind: ThingKind::Window, elevation: 0.9, solid: false,
        );

        $this->thing($demo, 'planter', 'Planter',
            'Someone kept these alive, and it was not you.',
            x: 9.4, z: 1.2, width: 1.2, depth: 0.8, height: 0.6,
            texture: 'garden-bed',
        );

        $this->thing($demo, 'bench', 'Stone Bench',
            'Cold through your clothes within a minute.',
            x: 13.0, z: 1.6, width: 2.0, depth: 0.6, height: 0.5,
            texture: 'fieldstone-wall',
        );

        $this->thing($demo, 'dock', 'Dock',
            'Four planks out over the water, and no boat to speak of.',
            x: 11.0, z: 6.6, width: 1.6, depth: 3.0, height: 0.12,
            elevation: -0.4, solid: false, texture: 'dock-planks',
        );

        $this->thing($demo, 'blanket', 'Picnic Blanket',
            'Laid out for weather that has not arrived yet.',
            x: 13.4, z: 4.4, width: 2.0, depth: 2.0, height: 0.02,
            elevation: -0.39, solid: false, texture: 'picnic-blanket',
        );

        // Everyone but Paul, who is you, and only ever turns up in the mirror.
        // Paul is the tallest at 1.85; the rest are set against him.
        $this->actor($demo, 'krystal', 'Krystal',
            'She is walking the garden, and does not seem to be going anywhere in particular.',
            sprite: 'krystal',
            x: 12.0, z: 3.0, height: 1.70, speed: 1.1,
        );

        $this->actor($demo, 'luke', 'Luke',
            'Doing a circuit of the room like he is waiting for someone to say something.',
            sprite: 'luke',
            x: 5.0, z: 4.2, height: 1.62, speed: 1.35,
        );

        $this->actor($demo, 'luna', 'Luna',
            'Wandering the far end of the garden, stopping at nothing in particular.',
            sprite: 'luna',
            x: 14.0, z: 2.0, height: 1.66, speed: 0.95,
        );

        $this->actor($demo, 'wade', 'Wade',
            'He has been to the fridge twice already and has not opened it once.',
            sprite: 'wade',
            x: 2.4, z: 2.4, height: 1.80, speed: 1.2,
        );

        $this->actor($demo, 'william', 'William',
            'Out by the water, in no hurry about any of it.',
            sprite: 'william',
            x: 13.0, z: 7.5, height: 1.55, speed: 0.85,
        );
    }
}
