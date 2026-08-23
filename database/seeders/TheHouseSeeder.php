<?php

namespace Database\Seeders;

use App\Enums\BindingResponse;
use App\Enums\EffectType;
use App\Enums\ThingHinge;
use App\Enums\Verb;
use App\Models\Game;
use App\Models\Level;
use Database\Seeders\Concerns\AuthorsGames;
use Illuminate\Database\Seeder;

/**
 * A two storey house with a yard: five bedrooms, two bathrooms and a cloakroom.
 *
 * The engine has no room above a room, the way Doom had none, so the upper
 * floor is laid out north of the ground floor rather than on top of it, three
 * metres up, with a run of steps climbing between the two. Walking it, it reads
 * as a staircase and a landing; on the plan the storeys sit side by side.
 */
class TheHouseSeeder extends Seeder
{
    use AuthorsGames;

    /** Rooms are this tall downstairs, and a little less upstairs. */
    private const GROUND_CEILING = 2.7;

    private const UPPER_FLOOR = 3.0;

    private const UPPER_CEILING = 5.6;

    /** The stairs climb in eight steps, each within the engine's step limit. */
    private const STEPS = 8;

    private const STEP_RUN = 0.25;

    /** The stairwell is this wide, and every step shares its full width. */
    private const STAIR_WEST = 5.4;

    private const STAIR_EAST = 6.8;

    public function run(): void
    {
        $game = Game::query()->where('slug', 'life')->sole();

        $house = $this->level(
            $game,
            'house',
            'The House',
            'Five bedrooms, two and a half bathrooms, and a back door somebody left open.',
            spawnX: 6.25,
            spawnZ: 8.4,
            spawnAngle: 0,
            ceilingHeight: self::GROUND_CEILING,
            sky: 'sky-day',
            skyVariant: 1,
            backdrop: 'suburbs',
            backdropLayers: [1, 2, 3],
        );

        $game->update(['starting_level_id' => $house->id]);

        $this->groundFloor($house);
        $this->stairs($house);
        $this->upperFloor($house);
        $this->yard($house);
        $this->doors($house);
        $this->furniture($house);
        $this->household($house);
    }

    /**
     * The ground floor: a hall down the middle with rooms either side of it, the
     * stairs off the north end and the back door off the south.
     */
    private function groundFloor(Level $house): void
    {
        $this->boxRoom($house, 'hall', 'Hall', 5, 0, 7.5, 10,
            doors: [
                'north' => [[self::STAIR_WEST, self::STAIR_EAST]],
                'south' => [[5.6, 6.6]],
                'west' => [[1.2, 2.2], [5.0, 6.0], [8.4, 9.3]],
                'east' => [[2.0, 3.0], [7.5, 8.5]],
            ],
            floor: 'oak-floor',
            ceiling: 'cream-plaster-wall',
            walls: 'cream-plaster-wall',
        );

        $this->boxRoom($house, 'kitchen', 'Kitchen', 0, 0, 5, 4,
            doors: [
                'east' => [[1.2, 2.2]],
                'south' => [[1.0, 2.0]],
            ],
            floor: 'kitchen-tile',
            ceiling: 'cream-plaster-wall',
            walls: 'subway-tile-wall',
        );

        $this->boxRoom($house, 'dining', 'Dining Room', 0, 4, 5, 7,
            doors: [
                'north' => [[1.0, 2.0]],
                'east' => [[5.0, 6.0]],
                'south' => [[0.8, 1.8]],
            ],
            floor: 'parquet-floor',
            ceiling: 'cream-plaster-wall',
            walls: 'floral-wallpaper',
        );

        $this->boxRoom($house, 'utility', 'Utility Room', 0, 7, 3, 10,
            doors: ['north' => [[0.8, 1.8]]],
            floor: 'speckled-linoleum',
            ceiling: 'cream-plaster-wall',
            walls: 'painted-brick-wall',
        );

        // The half bathroom: a lavatory and a basin, and no room to turn round.
        $this->boxRoom($house, 'cloakroom', 'Cloakroom', 3, 7, 5, 10,
            doors: ['east' => [[8.4, 9.3]]],
            floor: 'mosaic-tile',
            ceiling: 'cream-plaster-wall',
            walls: 'subway-tile-wall',
        );

        $this->boxRoom($house, 'living', 'Living Room', 7.5, 0, 12, 6,
            doors: [
                'west' => [[2.0, 3.0]],
                'south' => [[9.0, 10.0]],
            ],
            floor: 'pale-wood-floor',
            ceiling: 'cream-plaster-wall',
            walls: 'cream-plaster-wall',
        );

        $this->boxRoom($house, 'snug', 'Snug', 7.5, 6, 12, 10,
            doors: [
                'north' => [[9.0, 10.0]],
                'west' => [[7.5, 8.5]],
            ],
            floor: 'dark-wood-floor',
            ceiling: 'cream-plaster-wall',
            walls: 'wood-panel-wall',
        );
    }

    /**
     * The staircase, as a run of small sectors each a step higher than the last.
     * The bottom one shares the hall's north doorway and the top one the
     * landing's south, so the climb is a walk rather than a jump.
     */
    private function stairs(Level $house): void
    {
        $rise = self::UPPER_FLOOR / self::STEPS;
        $doorway = [[self::STAIR_WEST, self::STAIR_EAST]];

        for ($step = 1; $step <= self::STEPS; $step++) {
            $this->boxRoom(
                $house,
                "stair-{$step}",
                "Stair {$step}",
                self::STAIR_WEST,
                -self::STEP_RUN * $step,
                self::STAIR_EAST,
                -self::STEP_RUN * ($step - 1),
                doors: ['north' => $doorway, 'south' => $doorway],
                floorHeight: $rise * $step,
                ceilingHeight: $rise * $step + 2.4,
                floor: 'oak-floor',
                ceiling: 'cream-plaster-wall',
                walls: 'cream-plaster-wall',
            );
        }
    }

    /**
     * Upstairs: the landing runs the length of it, four bedrooms off the west
     * side, and the master with its own bathroom off the east.
     */
    private function upperFloor(Level $house): void
    {
        $upper = [
            'floorHeight' => self::UPPER_FLOOR,
            'ceilingHeight' => self::UPPER_CEILING,
            'ceiling' => 'cream-plaster-wall',
        ];

        $this->boxRoom($house, 'landing', 'Landing', 5, -12, 7.5, -2,
            doors: [
                'south' => [[self::STAIR_WEST, self::STAIR_EAST]],
                'west' => [[-3.7, -2.8], [-6.2, -5.3], [-8.7, -7.8], [-11.2, -10.3]],
                'east' => [[-5.0, -4.0], [-11.0, -10.1]],
            ],
            floorHeight: $upper['floorHeight'],
            ceilingHeight: $upper['ceilingHeight'],
            floor: 'oak-floor',
            ceiling: $upper['ceiling'],
            walls: 'cream-plaster-wall',
        );

        // Paul and Krystal share this one, so it has two of everything.
        $this->boxRoom($house, 'master-bedroom', 'Master Bedroom', 7.5, -7, 12, -2,
            doors: [
                'west' => [[-5.0, -4.0]],
                'north' => [[8.5, 9.4]],
            ],
            floorHeight: $upper['floorHeight'],
            ceilingHeight: $upper['ceilingHeight'],
            floor: 'cream-carpet',
            ceiling: $upper['ceiling'],
            walls: 'cream-plaster-wall',
        );

        $this->boxRoom($house, 'ensuite', 'Ensuite Bathroom', 7.5, -9.5, 12, -7,
            doors: ['south' => [[8.5, 9.4]]],
            floorHeight: $upper['floorHeight'],
            ceilingHeight: $upper['ceilingHeight'],
            floor: 'marble-floor',
            ceiling: $upper['ceiling'],
            walls: 'subway-tile-wall',
        );

        $this->boxRoom($house, 'family-bathroom', 'Family Bathroom', 7.5, -12, 12, -9.5,
            doors: ['west' => [[-11.0, -10.1]]],
            floorHeight: $upper['floorHeight'],
            ceilingHeight: $upper['ceilingHeight'],
            floor: 'mosaic-tile',
            ceiling: $upper['ceiling'],
            walls: 'subway-tile-wall',
            mirrors: ['north'],
        );

        $bedrooms = [
            ['lukes-room', "Luke's Room", -4.5, -2, [-3.7, -2.8], 'blue-carpet'],
            ['lunas-room', "Luna's Room", -7, -4.5, [-6.2, -5.3], 'rose-carpet'],
            ['wades-room', "Wade's Room", -9.5, -7, [-8.7, -7.8], 'blue-rug'],
            ['williams-room', "William's Room", -12, -9.5, [-11.2, -10.3], 'cream-carpet'],
        ];

        foreach ($bedrooms as [$slug, $name, $north, $south, $door, $carpet]) {
            $this->boxRoom($house, $slug, $name, 0, $north, 5, $south,
                doors: ['east' => [$door]],
                floorHeight: $upper['floorHeight'],
                ceilingHeight: $upper['ceilingHeight'],
                floor: $carpet,
                ceiling: $upper['ceiling'],
                walls: 'cream-plaster-wall',
            );
        }
    }

    /**
     * The yard wraps the back of the house, a step down from the hall, with a
     * fence low enough to see the neighbourhood over.
     */
    private function yard(Level $house): void
    {
        $this->boxRoom($house, 'yard', 'The Yard', -3, 10, 15, 20,
            doors: ['north' => [[5.6, 6.6]]],
            floorHeight: -0.2,
            ceilingHeight: 2.2,
            floor: 'spring-grass',
            walls: 'cedar-siding',
            sky: true,
            wallTextures: ['north' => 'white-siding'],
        );
    }

    /**
     * The two things in the house that open.
     *
     * Neither of them is a door as far as anything in the engine is concerned:
     * each is a flat sprite on a hinge with a `Use` that turns it and stops it
     * blocking. Paul's design, and the reason there is no door kind to point
     * at — the same two effects make a drawbridge or a hatch, and none of them
     * needed a case of its own.
     *
     * Both stand in gaps the walls already leave, which is how a doorway has
     * always been authored here: the runs either side and a thing in the hole.
     */
    private function doors(Level $house): void
    {
        // The back door the level's own description has been promising since
        // before anything could open. Authored already swung out of the way,
        // which is what a door somebody left open is: the thing is turned and
        // its collider is off from the start, with no save involved.
        $back = $this->hinged($house, 'back-door', 'The Back Door',
            'Standing open onto the yard, the way it always is.',
            x: 6.1, z: 10, width: 1.0, height: 2.05,
            hinge: ThingHinge::Left,
            texture: 'door-front',
        );

        $back->update(['is_solid' => false]);

        $this->interaction($back, Verb::Use, 'You push it to.', effects: [
            [EffectType::RotateThing, $back->slug, '0'],
            [EffectType::SetBlocking, $back->slug, '1'],
        ]);

        // And one that starts shut. Hung the other way round — `angle: 180`
        // puts its hinge on the other jamb — so the two of them together show
        // the hinge doing something rather than being a field nobody varies.
        $utility = $this->hinged($house, 'utility-door', 'The Utility Door',
            'Shut, and not obviously locked.',
            x: 1.3, z: 7, width: 1.0, height: 2.05,
            hinge: ThingHinge::Left,
            angle: 180,
            texture: 'door-interior',
        );

        $this->opens($utility, 'It swings open.');

        // And a plate in the hall outside opens it, joined by a line drawn
        // from one to the other. Paul's redstone idea at its smallest: an
        // invisible non-solid thing whose output goes on while somebody stands
        // on it, a line to the door, and a door that answers its own input.
        //
        // Nothing here has a name. The plate does not know what it drives and
        // the door does not know what drives it; the line is the whole of the
        // connection, and a chain would be one more of them.
        //
        // Both sides authored, which is what makes it swing closed behind you
        // rather than stay open because nobody said what off meant.
        $plate = $this->plate($house, 'utility-plate', 'A Worn Patch',
            'The lino is worn through here, in the shape of somebody waiting.',
            x: 1.3, z: 7.7, width: 1.2, depth: 1.0,
        );

        $this->wire($plate, $utility);

        $this->answers($utility, BindingResponse::Rotate, '90', '0');
        $this->answers($utility, BindingResponse::Blocking, '0', '1');
    }

    private function furniture(Level $house): void
    {
        // Kitchen and the rooms off the hall.
        $this->thing($house, 'kitchen-counter', 'Kitchen Counter',
            'Wiped down, mostly.',
            x: 2.4, z: 0.45, width: 4.4, depth: 0.7, height: 0.9,
            texture: 'kitchen-tile',
        );

        $this->thing($house, 'fridge', 'Fridge',
            'Covered in other people\'s reminders.',
            x: 4.55, z: 1.4, width: 0.8, depth: 0.8, height: 1.9,
            texture: 'white-siding',
        );

        $this->thing($house, 'kitchen-table', 'Kitchen Table',
            'Four chairs, three of them used.',
            x: 2.2, z: 2.8, width: 1.6, depth: 0.9, height: 0.75,
            texture: 'oak-floor',
        );

        $this->thing($house, 'dining-table', 'Dining Table',
            'Laid for more people than usually turn up.',
            x: 2.5, z: 5.5, width: 2.4, depth: 1.1, height: 0.76,
            texture: 'dark-wood-floor',
        );

        $this->thing($house, 'washer', 'Washing Machine',
            'Mid cycle, and nobody will admit whose it is.',
            x: 0.5, z: 9.4, width: 0.7, depth: 0.7, height: 0.85,
            texture: 'white-siding',
        );

        $this->thing($house, 'cloakroom-basin', 'Basin',
            'A bar of soap worn down to a sliver.',
            x: 4.6, z: 7.4, width: 0.6, depth: 0.4, height: 0.85,
            texture: 'marble-floor',
        );

        $this->thing($house, 'cloakroom-toilet', 'Lavatory',
            'The half in two and a half bathrooms.',
            x: 3.5, z: 9.5, width: 0.5, depth: 0.7, height: 0.75,
            texture: 'white-siding',
        );

        $this->thing($house, 'sofa', 'Sofa',
            'The good seat is the one nearest the window.',
            x: 11.2, z: 3.0, width: 1.0, depth: 2.4, height: 0.8,
            texture: 'blue-carpet',
        );

        $this->thing($house, 'television', 'Television',
            'On, with the sound down.',
            x: 8.0, z: 3.0, width: 0.2, depth: 1.4, height: 0.8,
            elevation: 0.5, texture: 'dark-wood-floor',
        );

        $this->thing($house, 'living-rug', 'Rug',
            'It has seen things.',
            x: 9.8, z: 3.0, width: 2.4, depth: 3.0, height: 0.02,
            solid: false, texture: 'red-rug',
        );

        $this->thing($house, 'desk', 'Desk',
            'Whoever works here has not tidied since they started.',
            x: 11.3, z: 7.0, width: 1.2, depth: 0.7, height: 0.75,
            texture: 'dark-wood-floor',
        );

        $this->thing($house, 'bookcase', 'Bookcase',
            'Two shelves of books and four of everything else.',
            x: 8.0, z: 9.4, width: 1.8, depth: 0.4, height: 1.9,
            texture: 'wood-panel-wall',
        );

        // Upstairs: the master pair, then a bed in each of the four rooms.
        $this->thing($house, 'pauls-bed', 'Paul\'s Bed',
            'Made, in the sense that the duvet is roughly on it.',
            x: 8.6, z: -3.4, width: 1.1, depth: 2.0, height: 0.55,
            elevation: self::UPPER_FLOOR, texture: 'cream-carpet',
        );

        $this->thing($house, 'krystals-bed', 'Krystal\'s Bed',
            'The one by the window, which was agreed early on.',
            x: 10.2, z: -3.4, width: 1.1, depth: 2.0, height: 0.55,
            elevation: self::UPPER_FLOOR, texture: 'rose-carpet',
        );

        $this->thing($house, 'wardrobe', 'Wardrobe',
            'Two doors, two halves, one very clear line between them.',
            x: 11.6, z: -6.2, width: 0.6, depth: 1.6, height: 2.1,
            elevation: self::UPPER_FLOOR, texture: 'wood-panel-wall',
        );

        $this->thing($house, 'ensuite-bath', 'Bath',
            'Deep enough to be a good idea and long enough to be a bad one.',
            x: 8.4, z: -8.9, width: 1.7, depth: 0.8, height: 0.6,
            elevation: self::UPPER_FLOOR, texture: 'marble-floor',
        );

        $this->thing($house, 'ensuite-basin', 'Basin',
            'Two toothbrushes, in the same glass.',
            x: 11.4, z: -8.0, width: 0.5, depth: 0.9, height: 0.85,
            elevation: self::UPPER_FLOOR, texture: 'marble-floor',
        );

        $this->thing($house, 'family-bath', 'Bath',
            'Shared, and negotiated over.',
            x: 8.4, z: -11.4, width: 1.7, depth: 0.8, height: 0.6,
            elevation: self::UPPER_FLOOR, texture: 'mosaic-tile',
        );

        $this->thing($house, 'family-basin', 'Basin',
            'A shelf of things nobody claims.',
            x: 11.4, z: -10.6, width: 0.5, depth: 0.9, height: 0.85,
            elevation: self::UPPER_FLOOR, texture: 'marble-floor',
        );

        $beds = [
            ['lukes-bed', "Luke's Bed", -3.4, 'blue-carpet'],
            ['lunas-bed', "Luna's Bed", -5.9, 'rose-carpet'],
            ['wades-bed', "Wade's Bed", -8.4, 'blue-rug'],
            ['williams-bed', "William's Bed", -10.9, 'cream-carpet'],
        ];

        foreach ($beds as [$slug, $name, $z, $texture]) {
            $this->thing($house, $slug, $name,
                'Single, and slept in.',
                x: 1.0, z: $z, width: 1.0, depth: 2.0, height: 0.55,
                elevation: self::UPPER_FLOOR, texture: $texture,
            );
        }

        // The yard.
        $this->thing($house, 'patio', 'Patio',
            'Slabs laid by somebody in a hurry.',
            x: 6.0, z: 11.6, width: 5.0, depth: 3.0, height: 0.02,
            elevation: -0.19, solid: false, texture: 'slate-path',
        );

        $this->thing($house, 'garden-table', 'Garden Table',
            'Wiped clean by the last rain.',
            x: 5.0, z: 11.8, width: 1.4, depth: 1.4, height: 0.72,
            elevation: -0.2, texture: 'weathered-deck',
        );

        $this->thing($house, 'shed', 'Shed',
            'Locked, and nobody is sure who has the key.',
            x: 13.0, z: 18.0, width: 2.4, depth: 2.0, height: 2.1,
            elevation: -0.2, texture: 'cedar-siding',
        );

        $this->thing($house, 'flower-bed', 'Flower Bed',
            'Somebody\'s project, still mostly soil.',
            x: -1.5, z: 15.0, width: 2.4, depth: 6.0, height: 0.35,
            elevation: -0.2, texture: 'garden-bed',
        );

        $this->thing($house, 'washing-line', 'Washing Line',
            'Two sheets and a great deal of optimism.',
            x: 9.5, z: 16.0, width: 0.1, depth: 5.0, height: 1.7,
            elevation: -0.2, solid: false, texture: 'white-siding',
        );
    }

    /**
     * Everyone except Paul, who is the one holding the camera.
     */
    private function household(Level $house): void
    {
        $this->actor($house, 'krystal', 'Krystal',
            'On her way somewhere, as usual.',
            sprite: 'krystal',
            x: 9.4, z: -4.5, height: 1.70, speed: 1.1,
        );

        $this->actor($house, 'luke', 'Luke',
            'Wearing the path between his room and the kitchen.',
            sprite: 'luke',
            x: 2.5, z: -3.4, height: 1.62, speed: 1.35,
        );

        $this->actor($house, 'luna', 'Luna',
            'Not saying where she is going, on principle.',
            sprite: 'luna',
            x: 2.5, z: -5.9, height: 1.66, speed: 0.95,
        );

        $this->actor($house, 'wade', 'Wade',
            'Doing laps of the ground floor like he lost something.',
            sprite: 'wade',
            x: 9.8, z: 2.0, height: 1.80, speed: 1.2,
        );

        $this->actor($house, 'william', 'William',
            'Out in the yard, taking his time about it.',
            sprite: 'william',
            x: 7.0, z: 13.5, height: 1.55, speed: 0.85,
        );
    }
}
