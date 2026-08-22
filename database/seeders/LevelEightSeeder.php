<?php

namespace Database\Seeders;

use App\Enums\ActorBehaviour;
use App\Models\Game;
use App\Models\Level;
use App\Models\LevelSector;
use Database\Seeders\Concerns\AuthorsGames;
use Illuminate\Database\Seeder;
use RuntimeException;

/**
 * Level 8, exported from the level the engine was actually built against.
 *
 * Nearly every hard-won rule in `.ai/rules/engine.md` names this level and none
 * of them could be reproduced without it. The staircase portal at x 70–72 in
 * the wall z = −18, and the ten-metre doorway in that same wall which turned
 * out to be why `hug()` needed to check the eye was in the opening rather than
 * merely near its plane. The fifty-one coplanar strips that made `carriedOn`
 * necessary, some of them fifteen metres tall. The sky room whose footprint
 * sits inside another room's, which is why a lid is only shown to somebody
 * standing under it. The far plane that cut the head off somebody a hundred
 * metres tall.
 *
 * Seventy-five rooms, four hundred and thirty-four walls. Hand-authoring that
 * as PHP would be several thousand lines nobody could read or review, so the
 * plan is beside this file as JSON and this walks it. Everything else in
 * `database/seeders` is authored by hand and should stay that way — this one is
 * an export, and it says so.
 */
class LevelEightSeeder extends Seeder
{
    use AuthorsGames;

    /** Where the exported plan lives. */
    private const PLAN = __DIR__.'/data/level-8.json';

    public function run(): void
    {
        $game = Game::query()->where('slug', 'life')->sole();

        $plan = $this->plan();

        $level = $this->level(
            $game,
            slug: 'level-8',
            name: 'Level 8',
            description: 'The level the engine was built against: a staircase through a portal, a sky room inside another room, and a wall the sun sets behind.',
            spawnX: $plan['level']['spawnX'],
            spawnZ: $plan['level']['spawnZ'],
            spawnAngle: $plan['level']['spawnAngle'],
            ceilingHeight: $plan['level']['ceilingHeight'],
            sky: $plan['level']['sky'],
            skyVariant: $plan['level']['skyVariant'],
            backdrop: $plan['level']['backdrop'],
            backdropLayers: [],
            wallColor: $plan['level']['wallColor'],
            floorColor: $plan['level']['floorColor'],
            accentColor: $plan['level']['accentColor'],
        );

        $level->update(['player_sprite' => $plan['level']['playerSprite']]);

        foreach ($plan['sectors'] as $room) {
            $this->room($level, $room);
        }

        foreach ($plan['things'] as $person) {
            $this->actor(
                $level,
                slug: $person['slug'],
                name: $person['name'],
                description: $person['description'] ?? '',
                sprite: $person['sprite'],
                x: (float) $person['x'],
                z: (float) $person['z'],
                height: (float) $person['height'],
                behaviour: ActorBehaviour::from($person['behaviour']),
                speed: (float) $person['speed'],
                angle: (float) $person['angle'],
            );
        }
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

        foreach ($room['edges'] as $index => $edge) {
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
            slug: $room['slug'],
            name: $room['name'],
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
     * @return array{level: array<string, mixed>, sectors: list<array<string, mixed>>, things: list<array<string, mixed>>}
     */
    private function plan(): array
    {
        $raw = file_get_contents(self::PLAN);

        if ($raw === false) {
            throw new RuntimeException('Level 8 has no plan beside its seeder.');
        }

        /** @var array{level: array<string, mixed>, sectors: list<array<string, mixed>>, things: list<array<string, mixed>>} $plan */
        $plan = json_decode($raw, true, flags: JSON_THROW_ON_ERROR);

        return $plan;
    }
}
