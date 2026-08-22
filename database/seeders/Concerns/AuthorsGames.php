<?php

namespace Database\Seeders\Concerns;

use App\Enums\ActorBehaviour;
use App\Enums\ConditionType;
use App\Enums\EffectType;
use App\Enums\ThingKind;
use App\Enums\Verb;
use App\Models\Game;
use App\Models\Hotspot;
use App\Models\Interaction;
use App\Models\Item;
use App\Models\Level;
use App\Models\LevelSector;
use App\Models\LevelThing;
use App\Models\LevelVertex;
use App\Models\Scene;

/**
 * A small authoring vocabulary shared by every game seeder, so adding a game
 * means writing content rather than writing inserts.
 */
trait AuthorsGames
{
    protected function game(string $slug, string $title, string $tagline, int $sortOrder = 0): Game
    {
        return Game::query()->create([
            'slug' => $slug,
            'title' => $title,
            'tagline' => $tagline,
            'sort_order' => $sortOrder,
        ]);
    }

    protected function scene(Game $game, string $slug, string $name, string $description, string $backgroundColor): Scene
    {
        return $game->scenes()->create([
            'slug' => $slug,
            'name' => $name,
            'description' => $description,
            'background_color' => $backgroundColor,
        ]);
    }

    /**
     * A first-person room. Metres, +X east, +Z south, spawn angle in degrees from -Z.
     *
     * @param  list<int>  $backdropLayers  Which layers of the backdrop theme to stack.
     */
    protected function level(
        Game $game,
        string $slug,
        string $name,
        string $description,
        float $spawnX,
        float $spawnZ,
        float $spawnAngle = 0,
        float $ceilingHeight = 3.0,
        ?string $sky = null,
        int $skyVariant = 0,
        ?string $backdrop = null,
        array $backdropLayers = [1, 2, 3],
        string $wallColor = '#7fe0c9',
        string $floorColor = '#2f6f5e',
        string $accentColor = '#fbbf24',
    ): Level {
        return $game->levels()->create([
            'slug' => $slug,
            'name' => $name,
            'description' => $description,
            'spawn_x' => $spawnX,
            'spawn_z' => $spawnZ,
            'spawn_angle' => $spawnAngle,
            'ceiling_height' => $ceilingHeight,
            'sky_image' => $sky,
            'sky_variant' => $skyVariant,
            'backdrop_theme' => $backdrop,
            'backdrop_layers' => $backdrop === null ? null : $backdropLayers,
            'wall_color' => $wallColor,
            'floor_color' => $floorColor,
            'accent_color' => $accentColor,
        ]);
    }

    /**
     * A room, drawn as a closed polygon on the floor plan. Two sectors that name
     * the same pair of corners share that edge, and the player can walk between
     * them; every other edge is a wall.
     *
     * @param  list<array{0: float, 1: float}>  $points  Corners, wound clockwise.
     * @param  array<int, string>  $edgeTextures  Texture per edge, keyed by the corner it starts at.
     * @param  list<int>  $mirrors  Edges that reflect the room, by the corner they start at.
     * @param  list<int>  $solidEdges  Shared edges that stay walls, by the corner they start at.
     * @param  array<int, string>  $portals  Portal link per edge, keyed by the corner it starts at. Two edges anywhere in the level naming the same link are the two ends of one portal.
     */
    protected function sector(
        Level $level,
        string $slug,
        string $name,
        array $points,
        float $floorHeight = 0,
        ?float $ceilingHeight = null,
        ?string $floor = null,
        ?string $ceiling = null,
        ?string $walls = null,
        bool $sky = false,
        bool $water = false,
        array $edgeTextures = [],
        array $mirrors = [],
        array $solidEdges = [],
        array $portals = [],
    ): LevelSector {
        $sector = $level->sectors()->create([
            'slug' => $slug,
            'name' => $name,
            'floor_height' => $floorHeight,
            'ceiling_height' => $ceilingHeight ?? $level->ceiling_height,
            'floor_texture' => $floor,
            'ceiling_texture' => $ceiling,
            'wall_texture' => $walls,
            'is_sky' => $sky,
            'is_water' => $water,
            'sort_order' => $level->sectors()->count(),
        ]);

        foreach ($points as $index => [$x, $z]) {
            $sector->edges()->create([
                'vertex_id' => $this->vertex($level, $x, $z)->id,
                'sort_order' => $index,
                'wall_texture' => $edgeTextures[$index] ?? null,
                'blocks' => in_array($index, $solidEdges, strict: true),
                'is_mirror' => in_array($index, $mirrors, strict: true),
                'portal_link' => $portals[$index] ?? null,
            ]);
        }

        return $sector;
    }

    /**
     * A four-walled room, with doorways punched into whichever walls need them.
     *
     * Everything that is not a doorway is marked as a wall, so two rooms that
     * touch only open into each other where they both name the same doorway —
     * which is how a house gets built out of rooms that share their walls.
     *
     * Walls are named for the side they are on: north is the smallest z.
     *
     * @param  array<string, list<array{0: float, 1: float}>>  $doors  Spans along each wall, in metres.
     * @param  list<string>  $mirrors  Walls that reflect the room.
     * @param  array<string, string>  $wallTextures  A texture for one named wall.
     * @param  array<string, string>  $portals  A portal link for one named wall, which makes that whole wall a portal mouth.
     */
    protected function boxRoom(
        Level $level,
        string $slug,
        string $name,
        float $x1,
        float $z1,
        float $x2,
        float $z2,
        array $doors = [],
        float $floorHeight = 0,
        ?float $ceilingHeight = null,
        ?string $floor = null,
        ?string $ceiling = null,
        ?string $walls = null,
        bool $sky = false,
        bool $water = false,
        array $mirrors = [],
        array $wallTextures = [],
        array $portals = [],
    ): LevelSector {
        $sides = [
            'north' => [[$x1, $z1], [$x2, $z1], 'x'],
            'east' => [[$x2, $z1], [$x2, $z2], 'z'],
            'south' => [[$x2, $z2], [$x1, $z2], 'x'],
            'west' => [[$x1, $z2], [$x1, $z1], 'z'],
        ];

        $points = [];

        foreach ($sides as $side => [$from, $to, $axis]) {
            $along = $axis === 'x' ? 0 : 1;
            $start = $from[$along];
            $forward = $to[$along] > $start ? 1 : -1;

            // Doorways in the order they are met walking this wall.
            $spans = array_map(
                fn (array $span): array => $forward > 0
                    ? [min($span), max($span)]
                    : [max($span), min($span)],
                $doors[$side] ?? [],
            );

            usort($spans, fn (array $a, array $b): int => ($a[0] <=> $b[0]) * $forward);

            $corner = function (float $at, bool $blocks) use ($from, $axis, $side, $mirrors, $wallTextures, $portals): array {
                return [
                    'x' => $axis === 'x' ? $at : $from[0],
                    'z' => $axis === 'x' ? $from[1] : $at,
                    'blocks' => $blocks,
                    'mirror' => $blocks && in_array($side, $mirrors, strict: true),
                    'texture' => $wallTextures[$side] ?? null,
                    'portal' => $portals[$side] ?? null,
                ];
            };

            $points[] = $corner($start, true);

            foreach ($spans as [$open, $shut]) {
                $points[] = $corner($open, false);
                $points[] = $corner($shut, true);
            }
        }

        return $this->shape(
            $level,
            $slug,
            $name,
            $points,
            $floorHeight,
            $ceilingHeight,
            $floor,
            $ceiling,
            $walls,
            $sky,
            $water,
        );
    }

    /**
     * A sector from corners that each carry their own edge settings. The edge
     * belongs to the corner it starts at.
     *
     * @param  list<array{x: float, z: float, blocks?: bool, mirror?: bool, texture?: string|null, portal?: string|null}>  $points
     */
    protected function shape(
        Level $level,
        string $slug,
        string $name,
        array $points,
        float $floorHeight = 0,
        ?float $ceilingHeight = null,
        ?string $floor = null,
        ?string $ceiling = null,
        ?string $walls = null,
        bool $sky = false,
        bool $water = false,
    ): LevelSector {
        $sector = $level->sectors()->create([
            'slug' => $slug,
            'name' => $name,
            'floor_height' => $floorHeight,
            'ceiling_height' => $ceilingHeight ?? $level->ceiling_height,
            'floor_texture' => $floor,
            'ceiling_texture' => $ceiling,
            'wall_texture' => $walls,
            'is_sky' => $sky,
            'is_water' => $water,
            'sort_order' => $level->sectors()->count(),
        ]);

        foreach ($points as $index => $point) {
            $sector->edges()->create([
                'vertex_id' => $this->vertex($level, $point['x'], $point['z'])->id,
                'sort_order' => $index,
                'wall_texture' => $point['texture'] ?? null,
                'blocks' => $point['blocks'] ?? false,
                'is_mirror' => $point['mirror'] ?? false,
                'portal_link' => $point['portal'] ?? null,
            ]);
        }

        return $sector;
    }

    /**
     * A corner of the floor plan, reused by every sector that meets there.
     */
    protected function vertex(Level $level, float $x, float $z): LevelVertex
    {
        return $level->vertices()->firstOrCreate(['x' => $x, 'z' => $z]);
    }

    /**
     * A person, drawn from the eight painted angles of a pair of sprite sheets.
     * Their height is how tall they stand in metres, floor to head.
     */
    protected function actor(
        Level $level,
        string $slug,
        string $name,
        string $description,
        string $sprite,
        float $x,
        float $z,
        float $height = 1.75,
        ActorBehaviour $behaviour = ActorBehaviour::Wander,
        float $speed = 1.1,
        float $angle = 0,
    ): LevelThing {
        return $level->things()->create([
            'slug' => $slug,
            'name' => $name,
            'description' => $description,
            'kind' => ThingKind::Actor,
            'sprite' => $sprite,
            'behaviour' => $behaviour,
            'speed' => $speed,
            'x' => $x,
            'z' => $z,
            'elevation' => 0,
            'width' => 0.9,
            'depth' => 0.9,
            'height' => $height,
            'angle' => $angle,
            'is_solid' => false,
            'sort_order' => $level->things()->count(),
        ]);
    }

    /**
     * A box standing in a level, positioned by its centre on the floor plan.
     */
    protected function thing(
        Level $level,
        string $slug,
        string $name,
        string $description,
        float $x,
        float $z,
        float $width,
        float $depth,
        float $height,
        ThingKind $kind = ThingKind::Prop,
        float $elevation = 0,
        float $angle = 0,
        bool $solid = true,
        ?string $texture = null,
    ): LevelThing {
        return $level->things()->create([
            'slug' => $slug,
            'name' => $name,
            'description' => $description,
            'kind' => $kind,
            'texture' => $texture,
            'x' => $x,
            'z' => $z,
            'elevation' => $elevation,
            'width' => $width,
            'depth' => $depth,
            'height' => $height,
            'angle' => $angle,
            'is_solid' => $solid,
            'sort_order' => $level->things()->count(),
        ]);
    }

    protected function item(Game $game, string $slug, string $name, string $description): Item
    {
        return $game->items()->create([
            'slug' => $slug,
            'name' => $name,
            'description' => $description,
        ]);
    }

    protected function hotspot(Scene $scene, string $slug, string $name, int $x, int $y, int $width, int $height, bool $visible = true): Hotspot
    {
        return $scene->hotspots()->create([
            'slug' => $slug,
            'name' => $name,
            'x' => $x,
            'y' => $y,
            'width' => $width,
            'height' => $height,
            'is_visible_by_default' => $visible,
            'sort_order' => $scene->hotspots()->count(),
        ]);
    }

    /**
     * What a verb does to a hotspot in a scene, or to a thing standing in a
     * first-person level. Both own their interactions the same way.
     *
     * @param  list<array{0: ConditionType, 1: string, 2?: string}>  $conditions
     * @param  list<array{0: EffectType, 1: string, 2?: string}>  $effects
     */
    protected function interaction(
        Hotspot|LevelThing $subject,
        Verb $verb,
        string $response,
        ?Item $item = null,
        int $priority = 0,
        array $conditions = [],
        array $effects = [],
    ): Interaction {
        $interaction = $subject->interactions()->create([
            'verb' => $verb,
            'required_item_id' => $item?->id,
            'response' => $response,
            'priority' => $priority,
        ]);

        foreach ($conditions as $condition) {
            $interaction->conditions()->create([
                'type' => $condition[0],
                'subject' => $condition[1],
                'value' => $condition[2] ?? null,
            ]);
        }

        foreach ($effects as $index => $effect) {
            $interaction->effects()->create([
                'type' => $effect[0],
                'subject' => $effect[1],
                'value' => $effect[2] ?? null,
                'sort_order' => $index,
            ]);
        }

        return $interaction;
    }
}
