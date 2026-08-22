<?php

namespace App\Services;

use App\Enums\ThingKind;
use App\Models\Interaction;
use App\Models\InteractionCondition;
use App\Models\InteractionEffect;
use App\Models\Level;
use App\Models\LevelSector;
use App\Models\LevelSectorEdge;
use App\Models\LevelThing;

/**
 * A level as the browser engine wants it. The game and the map editor both
 * render from this, so what you draw in the editor is what you walk around in.
 */
class LevelPayload
{
    /**
     * @return array<string, mixed>
     */
    public function forEngine(Level $level): array
    {
        $stats = app(PersonStats::class);

        $level->loadMissing([
            'sectors.edges.vertex',
            'things.interactions.requiredItem',
        ]);

        return [
            'slug' => $level->slug,
            'name' => $level->name,
            'description' => $level->description,
            'spawn' => [
                'x' => $level->spawn_x,
                'z' => $level->spawn_z,
                'angle' => $level->spawn_angle,
            ],
            'ceilingHeight' => $level->ceiling_height,
            'spriteStyle' => LevelAssets::STYLE,
            'playerSprite' => $level->player_sprite,
            'playerStats' => $stats->for($level->player_sprite),
            'wallColor' => $level->wall_color,
            'floorColor' => $level->floor_color,
            'accentColor' => $level->accent_color,
            'sky' => $level->sky_image === null ? null : [
                'image' => $level->sky_image,
                'variant' => $level->sky_variant,
                'theme' => $level->backdrop_theme,
                'layers' => $level->backdrop_layers ?? [],
            ],
            'sectors' => $level->sectors->map(fn (LevelSector $sector): array => [
                'slug' => $sector->slug,
                'name' => $sector->name,
                'floorHeight' => $sector->floor_height,
                'ceilingHeight' => $sector->ceiling_height,
                'floorTexture' => $sector->floor_texture,
                'ceilingTexture' => $sector->ceiling_texture,
                'wallTexture' => $sector->wall_texture,
                'isSky' => $sector->is_sky,
                'isWater' => $sector->is_water,
                'points' => $sector->edges->map(fn (LevelSectorEdge $edge): array => [
                    'x' => $edge->vertex->x,
                    'z' => $edge->vertex->z,
                    'wallTexture' => $edge->wall_texture,
                    'blocks' => $edge->blocks,
                    'isMirror' => $edge->is_mirror,
                    'isSky' => $edge->is_sky,
                    'portalLink' => $edge->portal_link,
                ])->all(),
            ])->all(),
            'things' => $level->things->map(fn (LevelThing $thing): array => [
                'slug' => $thing->slug,
                'name' => $thing->name,
                'description' => $thing->description,
                'kind' => $thing->kind->value,
                'sprite' => $thing->sprite,
                'behaviour' => $thing->behaviour,
                'stats' => $thing->kind === ThingKind::Actor ? $thing->stats() : null,
                'speed' => $thing->speed,
                'texture' => $thing->texture,
                'render' => $thing->render->value,
                'planeCount' => $thing->plane_count,
                'uvMode' => $thing->uv_mode->value,
                'textureAlt' => $thing->texture_alt,
                'altFlag' => $thing->alt_flag,
                'animationFrames' => $thing->animation_frames,
                'animationFps' => $thing->animation_fps,
                'x' => $thing->x,
                'z' => $thing->z,
                'elevation' => $thing->elevation,
                'width' => $thing->width,
                'depth' => $thing->depth,
                'height' => $thing->height,
                'angle' => $thing->angle,
                'isSolid' => $thing->is_solid,
                'verbs' => $this->verbsFor($thing),
            ])->all(),
        ];
    }

    /**
     * The same level, plus everything the map editor needs to author with: each
     * thing's interactions in full, conditions and effects and all.
     *
     * The playing payload deliberately leaves those out. Whoever is drawing the
     * level is allowed to see how the locks work; whoever is walking around it
     * is not.
     *
     * @return array<string, mixed>
     */
    public function forEditor(Level $level): array
    {
        $level->loadMissing([
            'things.interactions.conditions',
            'things.interactions.effects',
            'things.interactions.requiredItem',
        ]);

        $payload = $this->forEngine($level);
        $written = $level->things->keyBy('slug');
        $stats = app(PersonStats::class);

        $payload['things'] = array_map(
            fn (array $thing): array => [
                ...$thing,
                // The raw override, so the Inspector can tell a person who has
                // been given their own numbers from one who is simply their
                // sprite, and offer to hand them back.
                'stats' => $written[$thing['slug']]->stats,
                'inheritedStats' => $stats->for($written[$thing['slug']]->sprite),
                'interactions' => $this->interactionsFor($written[$thing['slug']]),
            ],
            $payload['things'],
        );

        return $payload;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function interactionsFor(LevelThing $thing): array
    {
        return $thing->interactions
            ->sortBy([['priority', 'desc'], ['id', 'asc']])
            ->map(fn (Interaction $interaction): array => [
                'verb' => $interaction->verb->value,
                'response' => $interaction->response,
                'priority' => $interaction->priority,
                'requiredItem' => $interaction->requiredItem?->slug,
                'conditions' => $interaction->conditions
                    ->map(fn (InteractionCondition $condition): array => [
                        'type' => $condition->type->value,
                        'subject' => $condition->subject,
                        'value' => $condition->value,
                    ])->values()->all(),
                'effects' => $interaction->effects
                    ->map(fn (InteractionEffect $effect): array => [
                        'type' => $effect->type->value,
                        'subject' => $effect->subject,
                        'value' => $effect->value,
                    ])->values()->all(),
            ])
            ->values()
            ->all();
    }

    /**
     * What the player may try on a thing. Only the shape of the offer travels —
     * which verb, and what has to be in hand for it — never the conditions or
     * the effects, which the browser has no business knowing and no way to
     * enforce. Whether an interaction's conditions actually hold is settled on
     * the server when the verb is sent.
     *
     * @return array<int, array{verb: string, item: string|null}>
     */
    private function verbsFor(LevelThing $thing): array
    {
        return $thing->interactions
            ->map(fn (Interaction $interaction): array => [
                'verb' => $interaction->verb->value,
                'item' => $interaction->requiredItem?->slug,
            ])
            ->unique(fn (array $offer): string => $offer['verb'].'|'.($offer['item'] ?? ''))
            ->sortBy([['verb', 'asc'], ['item', 'asc']])
            ->values()
            ->all();
    }
}
