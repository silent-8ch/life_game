<?php

namespace Database\Seeders;

use App\Enums\ActorBehaviour;
use App\Enums\ThingKind;
use App\Models\Game;
use App\Models\Level;
use App\Models\LevelSector;
use Database\Seeders\Concerns\AuthorsGames;
use Illuminate\Database\Seeder;
use RuntimeException;

/**
 * The levels drawn in the editor on the original instance, brought over so the
 * demo has them.
 *
 * These are exports, and they say so: each one is a plan in `data` beside this
 * file and this walks it, the same arrangement `LevelEightSeeder` uses and for
 * the same reason — a hundred and eighteen rooms and six hundred walls as
 * literal PHP is not something anybody could review a change to. Everything
 * else in `database/seeders` is authored by hand with the helpers and should
 * stay that way. Nothing new belongs in here: a level that does not already
 * exist somewhere gets authored, not exported.
 */
class ImportedLevelsSeeder extends Seeder
{
    use AuthorsGames;

    /** Where the exported plans live, in the order they were drawn. */
    private const PLANS = [
        'new-level',
        'new-level-for-children',
        'william-level',
        'wade-wade-wade',
        'will-world',
        'will',
    ];

    public function run(): void
    {
        $game = Game::query()->where('slug', 'life')->sole();

        foreach (self::PLANS as $name) {
            $this->import($game, $this->plan($name));
        }
    }

    /**
     * @param  array{level: array<string, mixed>, sectors: list<array<string, mixed>>, things: list<array<string, mixed>>}  $plan
     */
    private function import(Game $game, array $plan): Level
    {
        /** @var array<string, mixed> $meta */
        $meta = $plan['level'];

        /** @var list<int> $backdropLayers */
        $backdropLayers = $meta['backdropLayers'];

        $level = $this->level(
            $game,
            slug: (string) $meta['slug'],
            name: (string) $meta['name'],
            description: (string) $meta['description'],
            spawnX: (float) $meta['spawnX'],
            spawnZ: (float) $meta['spawnZ'],
            spawnAngle: (float) $meta['spawnAngle'],
            ceilingHeight: (float) $meta['ceilingHeight'],
            // As above: the export still names a strip and a cell.
            sky: $meta['sky'] === null
                ? null
                : $meta['sky'].'-'.((int) $meta['skyVariant'] + 1),
            backdrop: $meta['backdrop'],
            backdropLayers: $backdropLayers,
            wallColor: (string) $meta['wallColor'],
            floorColor: (string) $meta['floorColor'],
            accentColor: (string) $meta['accentColor'],
        );

        $level->update(['player_sprite' => $meta['playerSprite']]);

        foreach ($plan['sectors'] as $room) {
            $this->room($level, $room);
        }

        foreach ($plan['things'] as $thing) {
            $this->place($level, $thing);
        }

        return $level;
    }

    /**
     * One room and its walls.
     *
     * @param  array<string, mixed>  $room
     */
    private function room(Level $level, array $room): LevelSector
    {
        /** @var list<array{0: float, 1: float}> $points */
        $points = [];
        $textures = [];
        $mirrors = [];
        $solid = [];
        $portals = [];
        $skyWalls = [];

        /** @var list<array<string, mixed>> $edges */
        $edges = $room['edges'];

        foreach ($edges as $index => $edge) {
            $points[] = [(float) $edge['x'], (float) $edge['z']];

            if ($edge['wallTexture'] !== null) {
                $textures[$index] = $edge['wallTexture'];
            }

            if ($edge['isMirror'] === 1) {
                $mirrors[] = $index;
            }

            if ($edge['blocks'] === 1) {
                $solid[] = $index;
            }

            if ($edge['portalLink'] !== null) {
                $portals[$index] = $edge['portalLink'];
            }

            if ($edge['isSky'] === 1) {
                $skyWalls[] = $index;
            }
        }

        return $this->sector(
            $level,
            slug: (string) $room['slug'],
            name: (string) $room['name'],
            points: $points,
            floorHeight: (float) $room['floorHeight'],
            ceilingHeight: (float) $room['ceilingHeight'],
            floor: $room['floor'],
            ceiling: $room['ceiling'],
            walls: $room['walls'],
            sky: $room['sky'] === 1,
            water: $room['water'] === 1,
            edgeTextures: $textures,
            mirrors: $mirrors,
            solidEdges: $solid,
            portals: $portals,
            skyEdges: $skyWalls,
        );
    }

    /**
     * A person, or a box.
     *
     * @param  array<string, mixed>  $thing
     */
    private function place(Level $level, array $thing): void
    {
        $kind = ThingKind::from((string) $thing['kind']);

        if ($kind === ThingKind::Actor) {
            $this->actor(
                $level,
                slug: (string) $thing['slug'],
                name: (string) $thing['name'],
                description: (string) $thing['description'],
                sprite: (string) $thing['sprite'],
                x: (float) $thing['x'],
                z: (float) $thing['z'],
                height: (float) $thing['height'],
                behaviour: ActorBehaviour::from((string) $thing['behaviour']),
                speed: (float) $thing['speed'],
                angle: (float) $thing['angle'],
            );

            return;
        }

        $this->thing(
            $level,
            slug: (string) $thing['slug'],
            name: (string) $thing['name'],
            description: (string) $thing['description'],
            x: (float) $thing['x'],
            z: (float) $thing['z'],
            width: (float) $thing['width'],
            depth: (float) $thing['depth'],
            height: (float) $thing['height'],
            kind: $kind,
            elevation: (float) $thing['elevation'],
            angle: (float) $thing['angle'],
            solid: $thing['isSolid'] === 1,
            texture: $thing['texture'],
        );
    }

    /**
     * @return array{level: array<string, mixed>, sectors: list<array<string, mixed>>, things: list<array<string, mixed>>}
     */
    private function plan(string $name): array
    {
        $raw = file_get_contents(__DIR__."/data/$name.json");

        if ($raw === false) {
            throw new RuntimeException("Level $name has no plan beside its seeder.");
        }

        /** @var array{level: array<string, mixed>, sectors: list<array<string, mixed>>, things: list<array<string, mixed>>} $plan */
        $plan = json_decode($raw, true, flags: JSON_THROW_ON_ERROR);

        return $plan;
    }
}
