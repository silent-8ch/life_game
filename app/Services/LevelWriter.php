<?php

namespace App\Services;

use App\Enums\DoorSwing;
use App\Enums\ThingRender;
use App\Enums\ThingUvMode;
use App\Models\Item;
use App\Models\Level;
use App\Models\LevelThing;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Saves a map drawn in the editor. The shape that arrives replaces the shape
 * that was stored: corners are rebuilt from scratch so that two sectors sharing
 * a corner go on sharing it, which is what makes a doorway a doorway.
 */
class LevelWriter
{
    /**
     * @param  array<string, mixed>  $map  A validated map, as UpdateLevelMapRequest defines it.
     */
    public function save(Level $level, array $map): Level
    {
        DB::transaction(function () use ($level, $map): void {
            $sky = $map['sky'] ?? null;

            $level->update([
                'name' => $map['name'],
                'description' => $map['description'],
                'player_sprite' => $map['playerSprite'],
                'spawn_x' => $map['spawn']['x'],
                'spawn_z' => $map['spawn']['z'],
                'spawn_angle' => $map['spawn']['angle'],
                'ceiling_height' => $map['ceilingHeight'],
                'sky_image' => $sky['image'] ?? null,
                'sky_variant' => $sky['variant'] ?? 0,
                'backdrop_theme' => $sky === null ? null : ($sky['theme'] ?? null),
                'backdrop_layers' => $sky === null ? null : ($sky['layers'] ?? []),
            ]);

            $this->writeThings($level, $map['things'] ?? []);

            // Edges point at both, so the shape goes before the corners do.
            $level->sectors()->delete();
            $level->vertices()->delete();

            $corners = [];

            foreach ($map['sectors'] as $order => $drawn) {
                $sector = $level->sectors()->create([
                    'slug' => $drawn['slug'],
                    'name' => $drawn['name'],
                    'floor_height' => $drawn['floorHeight'],
                    'ceiling_height' => $drawn['ceilingHeight'],
                    'floor_slope' => $drawn['floorSlope'] ?? 0,
                    'floor_slope_edge' => $drawn['floorSlopeEdge'] ?? null,
                    'ceiling_slope' => $drawn['ceilingSlope'] ?? 0,
                    'ceiling_slope_edge' => $drawn['ceilingSlopeEdge'] ?? null,
                    'floor_texture' => $drawn['floorTexture'] ?? null,
                    'ceiling_texture' => $drawn['ceilingTexture'] ?? null,
                    'wall_texture' => $drawn['wallTexture'] ?? null,
                    'is_sky' => $drawn['isSky'],
                    'is_water' => $drawn['isWater'],
                    'sort_order' => $order,
                ]);

                foreach ($drawn['points'] as $index => $point) {
                    $key = sprintf('%.3f,%.3f', $point['x'], $point['z']);

                    $corners[$key] ??= $level->vertices()->create([
                        'x' => $point['x'],
                        'z' => $point['z'],
                    ]);

                    $sector->edges()->create([
                        'vertex_id' => $corners[$key]->id,
                        'sort_order' => $index,
                        'wall_texture' => $point['wallTexture'] ?? null,
                        'blocks' => $point['blocks'],
                        'is_mirror' => $point['isMirror'],
                        'is_sky' => $point['isSky'] ?? false,
                        'portal_link' => $point['portalLink'] ?? null,
                    ]);
                }
            }
        });

        return $level->fresh(['sectors.edges.vertex', 'things.interactions']);
    }

    /**
     * The people and the furniture, replaced wholesale like the shape is. What
     * a thing is called stays its own; nothing outside the level points at one
     * by id, so rebuilding the rows loses nothing.
     *
     * @param  array<int, array<string, mixed>>  $things
     */
    private function writeThings(Level $level, array $things): void
    {
        $level->things()->delete();

        $items = Item::query()
            ->where('game_id', $level->game_id)
            ->pluck('id', 'slug');

        foreach ($things as $order => $thing) {
            $written = $level->things()->create([
                'slug' => $thing['slug'],
                'name' => $thing['name'],
                'description' => $thing['description'],
                'kind' => $thing['kind'],
                'sprite' => $thing['sprite'] ?? null,
                'behaviour' => $thing['behaviour'] ?? null,
                'stats' => $thing['stats'] ?? null,
                'speed' => $thing['speed'],
                'texture' => $thing['texture'] ?? null,
                'render' => $thing['render'] ?? ThingRender::Box->value,
                'plane_count' => $thing['planeCount'] ?? 2,
                'uv_mode' => $thing['uvMode'] ?? ThingUvMode::Tile->value,
                'texture_alt' => $thing['textureAlt'] ?? null,
                'alt_flag' => $thing['altFlag'] ?? null,
                'animation_frames' => $thing['animationFrames'] ?? 1,
                'animation_fps' => $thing['animationFps'] ?? 8,
                'x' => $thing['x'],
                'z' => $thing['z'],
                'elevation' => $thing['elevation'],
                'width' => $thing['width'],
                'depth' => $thing['depth'],
                'height' => $thing['height'],
                'angle' => $thing['angle'],
                'is_solid' => $thing['isSolid'],
                'is_door' => $thing['isDoor'] ?? false,
                'swing' => $thing['swing'] ?? DoorSwing::Swing->value,
                'open_angle' => $thing['openAngle'] ?? 90,
                'open_seconds' => $thing['openSeconds'] ?? 0.4,
                'is_open' => $thing['isOpen'] ?? false,
                'opens_flag' => $thing['opensFlag'] ?? null,
                'sort_order' => $order,
            ]);

            $this->writeInteractions($written, $thing['interactions'] ?? [], $items);
        }
    }

    /**
     * What the player can do to a thing, rebuilt with the thing itself.
     *
     * @param  array<int, array<string, mixed>>  $interactions
     * @param  Collection<string, int>  $items  Item ids by slug.
     */
    private function writeInteractions(LevelThing $thing, array $interactions, Collection $items): void
    {
        foreach ($interactions as $drawn) {
            $required = $drawn['requiredItem'] ?? null;

            $interaction = $thing->interactions()->create([
                'verb' => $drawn['verb'],
                'response' => $drawn['response'],
                'priority' => $drawn['priority'] ?? 0,
                'required_item_id' => $required === null || $required === ''
                    ? null
                    : $items[$required],
            ]);

            foreach ($drawn['conditions'] ?? [] as $condition) {
                $interaction->conditions()->create([
                    'type' => $condition['type'],
                    'subject' => $condition['subject'],
                    'value' => $condition['value'] ?? null,
                ]);
            }

            foreach ($drawn['effects'] ?? [] as $order => $effect) {
                $interaction->effects()->create([
                    'type' => $effect['type'],
                    'subject' => $effect['subject'],
                    'value' => $effect['value'] ?? null,
                    'sort_order' => $order,
                ]);
            }
        }
    }
}
