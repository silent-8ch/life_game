import * as THREE from 'three';
import { moveWithCollisions } from '@/lib/engine/collision';
import type { Collider } from '@/lib/engine/collision';
import {
    EYE_HEIGHT,
    MAX_PITCH,
    PLAYER_RADIUS,
    RUN_SPEED,
    STEP_SMOOTHING,
    WADE_DEPTH,
    WALK_SPEED,
} from '@/lib/engine/constants';
import { crossPortal } from '@/lib/engine/portals';
import type { Portal } from '@/lib/engine/portals';
import type { ForcedSpot } from '@/lib/engine/probe-backdrop';
import { floorAt, sectorAt } from '@/lib/engine/sectors';
import type { Level, Sector } from '@/types';

/**
 * Where the player is, which way they are looking, and how far they have come.
 *
 * Everything here is metres and radians. `walked` is the tally the sprite's
 * walk frame and the swing of the hands are both picked off. It counts the
 * distance *asked for*, not the distance covered: pushing into a wall still
 * swings the arms, which is what walking on the spot looks like and is
 * deliberate. The one thing that does not count is a step refused for leaving
 * the floor plan.
 */
export type Player = {
    x: number;
    z: number;
    yaw: number;
    pitch: number;
    /** Height of the eye, which catches up with the floor rather than jumping. */
    eye: number;
    walked: number;
};

/** The parts of the level a step has to ask about. */
export type Walkable = {
    sectors: Sector[];
    colliders: Collider[];
    portals: Portal[];
};

/** How hard the player is pushing, this frame. */
export type Push = {
    /** -1 to 1, forward positive. */
    forward: number;
    /** -1 to 1, right positive. */
    strafe: number;
    running: boolean;
};

/**
 * Where the player starts.
 *
 * A spot named in the address wins over the level's own spawn, so a reported
 * snapshot can be stood on again exactly. The level's angle runs the other way
 * to the player's yaw, which is exactly the trap `?at=` exists to avoid: a
 * snapshot's yaw goes in as it was written, and only the level's own spawn is
 * negated.
 */
export function spawnPlayer(level: Level, forced: ForcedSpot | null): Player {
    const standingOn =
        forced === null
            ? sectorAt(level.sectors, level.spawn.x, level.spawn.z)
            : sectorAt(level.sectors, forced.x, forced.z);

    return {
        x: forced?.x ?? level.spawn.x,
        z: forced?.z ?? level.spawn.z,
        yaw:
            forced === null
                ? -THREE.MathUtils.degToRad(level.spawn.angle)
                : THREE.MathUtils.degToRad(forced.yaw),
        pitch: forced === null ? 0 : THREE.MathUtils.degToRad(forced.pitch),
        eye:
            (standingOn === null
                ? 0
                : floorAt(
                      standingOn,
                      forced?.x ?? level.spawn.x,
                      forced?.z ?? level.spawn.z,
                  )) + EYE_HEIGHT,
        walked: 0,
    };
}

/** Turns the head, keeping the pitch inside what a neck does. */
export function turnPlayer(
    player: Player,
    turned: { yaw: number; pitch: number },
): void {
    if (turned.yaw === 0 && turned.pitch === 0) {
        return;
    }

    player.yaw += turned.yaw;
    player.pitch = THREE.MathUtils.clamp(
        player.pitch + turned.pitch,
        -MAX_PITCH,
        MAX_PITCH,
    );
}

/**
 * One step: collide, cross a portal, and refuse to leave the floor plan.
 *
 * The order of those three is load-bearing.
 *
 * A portal is asked about **before** the floor plan is, because walking into
 * one leaves the room by design: the step that crosses a mouth lands outside
 * every sector until it is carried through to the far one. Ask the floor plan
 * first and the crossing is refused as walking into nothing.
 *
 * And the floor plan is asked at all because collision alone is not enough to
 * keep the player inside the level — a corner solved two ways at once can put
 * them a hair outside, and outside is where there is no floor.
 */
export function walkPlayer(
    player: Player,
    push: Push,
    world: Walkable,
    seconds: number,
): void {
    if (push.forward === 0 && push.strafe === 0) {
        return;
    }

    const speed = push.running ? RUN_SPEED : WALK_SPEED;

    const sin = Math.sin(player.yaw);
    const cos = Math.cos(player.yaw);

    let moveX = push.forward * -sin + push.strafe * cos;
    let moveZ = push.forward * -cos + push.strafe * -sin;

    // A stick half over is a walk half as fast. A key is all the way over or
    // not at all, so this changes nothing for one.
    const throttle = Math.min(1, Math.hypot(push.forward, push.strafe));

    const length = Math.hypot(moveX, moveZ);
    moveX = (moveX / length) * speed * throttle * seconds;
    moveZ = (moveZ / length) * speed * throttle * seconds;

    const moved = moveWithCollisions(
        player,
        moveX,
        moveZ,
        world.colliders,
        PLAYER_RADIUS,
    );

    const through = crossPortal(
        world.portals,
        player.x,
        player.z,
        moved.x,
        moved.z,
        player.yaw,
    );

    const next =
        through !== null &&
        sectorAt(world.sectors, through.x, through.z) !== null
            ? through
            : moved;

    if (sectorAt(world.sectors, next.x, next.z) === null) {
        return;
    }

    player.walked += Math.hypot(moveX, moveZ);
    player.x = next.x;
    player.z = next.z;

    if (next === through) {
        player.yaw = through.yaw;
    }
}

/**
 * Brings the eye towards where it belongs over the floor underneath.
 *
 * It catches up rather than jumping, so a step up reads as a step rather than
 * as a cut. Wading takes the eye down into the water by the same amount the
 * body sinks.
 */
export function settleEye(
    player: Player,
    standingIn: Sector | null,
    seconds: number,
): void {
    // The floor under the player rather than the room's base height, so the
    // eye follows a ramp up it rather than floating along at the height of its
    // hinge wall.
    const floor =
        standingIn === null ? 0 : floorAt(standingIn, player.x, player.z);
    const wading = standingIn?.isWater === true ? WADE_DEPTH : 0;

    player.eye +=
        (floor + EYE_HEIGHT - wading - player.eye) *
        Math.min(1, STEP_SMOOTHING * seconds);
}

/**
 * Puts the camera where the player is.
 *
 * The world matrix is worked out here rather than left to the renderer, because
 * mirrors and portal panes derive their cameras from it and they run before
 * `renderer.render`, which is where three would otherwise get round to it.
 * Without this the portal camera lands at the portal's own translation instead
 * of at the player carried through it, and the pane shows the sky.
 */
export function aimCamera(
    camera: THREE.PerspectiveCamera,
    player: Player,
): void {
    camera.position.set(player.x, player.eye, player.z);
    camera.rotation.y = player.yaw;
    camera.rotation.x = player.pitch;
    camera.updateMatrixWorld(true);
}
