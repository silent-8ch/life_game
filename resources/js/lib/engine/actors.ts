import * as THREE from 'three';
import { moveWithCollisions } from '@/lib/engine/collision';
import type { Collider, Point } from '@/lib/engine/collision';
import { PLAYER_RADIUS } from '@/lib/engine/constants';
import { boundsOf, floorAt, sectorAt } from '@/lib/engine/sectors';
import { createSpriteActor } from '@/lib/engine/sprite-actor';
import type { SpriteActor } from '@/lib/engine/sprite-actor';
import type { Level, LevelThing, Sector } from '@/types';

/**
 * The people in a level other than the player. A wanderer walks to somewhere it
 * picked at random, and picks somewhere else when it arrives, gives up, or
 * finds itself pressed against the furniture.
 */

/** How close counts as having arrived. */
const ARRIVED = 0.4;

/** Give up on a spot that is taking too long to reach. */
const PATIENCE_SECONDS = 14;

/** A wanderer that has barely moved for this long picks somewhere else. */
const STUCK_SECONDS = 0.7;

/** How quickly a wanderer turns to face where it is going. */
const TURN_RATE = 6;

/** Tries at finding a spot inside the level before settling for standing still. */
const AIM_ATTEMPTS = 24;

type Wanderer = {
    thing: LevelThing;
    sprite: SpriteActor;
    x: number;
    z: number;
    yaw: number;
    walked: number;
    target: Point;
    waited: number;
    stillFor: number;
};

export type Actors = {
    objects: THREE.Object3D[];
    /** Walk everyone on by a frame. */
    update: (seconds: number, colliders: Collider[]) => void;
    /** Square every body up to a viewer, real or reflected. */
    faceViewer: (x: number, z: number, yaw: number) => void;
    /** Where an actor is standing, for the look-at ray to test against. */
    positionOf: (slug: string) => THREE.Vector3 | null;
    dispose: () => void;
};

function floorUnder(sectors: Sector[], x: number, z: number): number | null {
    const standingOn = sectorAt(sectors, x, z);

    // The floor under a spot, not the room's base height: on a ramp those are
    // the same number only along the hinge wall.
    return standingOn === null ? null : floorAt(standingOn, x, z);
}

/** Somewhere inside the level's sectors, chosen at random. */
function aimSomewhere(sectors: Sector[]): Point {
    const bounds = boundsOf(sectors);

    for (let attempt = 0; attempt < AIM_ATTEMPTS; attempt++) {
        const x = bounds.minX + Math.random() * (bounds.maxX - bounds.minX);
        const z = bounds.minZ + Math.random() * (bounds.maxZ - bounds.minZ);

        if (sectorAt(sectors, x, z) !== null) {
            return { x, z };
        }
    }

    return {
        x: (bounds.minX + bounds.maxX) / 2,
        z: (bounds.minZ + bounds.maxZ) / 2,
    };
}

export function createActors(level: Level): Actors {
    const sectors = level.sectors;

    const wanderers: Wanderer[] = level.things
        .filter((thing) => thing.kind === 'actor' && thing.sprite !== null)
        .map((thing) => {
            const sprite = createSpriteActor(
                thing.sprite ?? '',
                thing.height,
                level.spriteStyle,
            );

            sprite.place(
                thing.x,
                floorUnder(sectors, thing.x, thing.z) ?? 0,
                thing.z,
                THREE.MathUtils.degToRad(thing.angle),
                0,
            );

            return {
                thing,
                sprite,
                x: thing.x,
                z: thing.z,
                yaw: -THREE.MathUtils.degToRad(thing.angle),
                walked: 0,
                target: { x: thing.x, z: thing.z },
                waited: PATIENCE_SECONDS,
                stillFor: 0,
            };
        });

    for (const wanderer of wanderers) {
        wanderer.sprite.object.userData.thingSlug = wanderer.thing.slug;
    }

    return {
        objects: wanderers.map((wanderer) => wanderer.sprite.object),

        update: (seconds, colliders) => {
            for (const wanderer of wanderers) {
                const roaming = wanderer.thing.behaviour === 'wander';

                wanderer.waited += seconds;

                const reach = Math.hypot(
                    wanderer.target.x - wanderer.x,
                    wanderer.target.z - wanderer.z,
                );

                if (
                    roaming &&
                    (reach < ARRIVED ||
                        wanderer.waited > PATIENCE_SECONDS ||
                        wanderer.stillFor > STUCK_SECONDS)
                ) {
                    wanderer.target = aimSomewhere(sectors);
                    wanderer.waited = 0;
                    wanderer.stillFor = 0;
                }

                if (roaming && reach > ARRIVED) {
                    const stepX =
                        ((wanderer.target.x - wanderer.x) / reach) *
                        wanderer.thing.speed *
                        seconds;
                    const stepZ =
                        ((wanderer.target.z - wanderer.z) / reach) *
                        wanderer.thing.speed *
                        seconds;

                    const moved = moveWithCollisions(
                        wanderer,
                        stepX,
                        stepZ,
                        colliders,
                        PLAYER_RADIUS,
                    );

                    const travelled = Math.hypot(
                        moved.x - wanderer.x,
                        moved.z - wanderer.z,
                    );

                    // Refuse to walk off the floor plan entirely.
                    if (floorUnder(sectors, moved.x, moved.z) !== null) {
                        wanderer.x = moved.x;
                        wanderer.z = moved.z;
                        wanderer.walked += travelled;
                    }

                    wanderer.stillFor =
                        travelled < wanderer.thing.speed * seconds * 0.25
                            ? wanderer.stillFor + seconds
                            : 0;

                    // Face the way it is walking, turning rather than snapping.
                    const heading = Math.atan2(
                        -(wanderer.target.x - wanderer.x),
                        -(wanderer.target.z - wanderer.z),
                    );
                    const difference =
                        ((heading - wanderer.yaw + Math.PI * 3) %
                            (Math.PI * 2)) -
                        Math.PI;

                    wanderer.yaw +=
                        difference * Math.min(1, TURN_RATE * seconds);
                }

                wanderer.sprite.place(
                    wanderer.x,
                    floorUnder(sectors, wanderer.x, wanderer.z) ?? 0,
                    wanderer.z,
                    wanderer.yaw,
                    wanderer.walked,
                );
            }
        },

        faceViewer: (x, z, yaw) => {
            for (const wanderer of wanderers) {
                wanderer.sprite.faceViewer(x, z, yaw);
            }
        },

        positionOf: (slug) =>
            wanderers.find((wanderer) => wanderer.thing.slug === slug)?.sprite
                .object.position ?? null,

        dispose: () => {
            for (const wanderer of wanderers) {
                wanderer.sprite.dispose();
            }
        },
    };
}
