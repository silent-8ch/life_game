import * as THREE from 'three';
import { moveWithCollisions } from '@/lib/engine/collision';
import type { Collider } from '@/lib/engine/collision';
import {
    EYE_OF_STATURE,
    GRAVITY,
    JUMP_SPEED,
    MAX_PITCH,
    MAX_STEP,
    PLAYER_RADIUS,
    RUN_SPEED,
    STEP_SMOOTHING,
    TERMINAL_FALL,
    WADE_DEPTH,
    WALK_SPEED,
} from '@/lib/engine/constants';
import { crossPortal } from '@/lib/engine/portals';
import type { Portal } from '@/lib/engine/portals';
import type { ForcedSpot } from '@/lib/engine/probe-backdrop';
import { ceilingAt, floorAt, sectorAt } from '@/lib/engine/sectors';
import { DEFAULT_PLAYER_HEIGHT, HEIGHTS } from '@/lib/engine/sprite-actor';
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
    /**
     * Where the feet are.
     *
     * State, integrated by `fallPlayer`, rather than a reading of the floor
     * underneath. The difference is the whole of A-11a: while height was
     * derived, there was no such thing as being off the ground, so there was
     * nothing to fall and nothing a ceiling could be in the way of.
     */
    y: number;
    /**
     * How fast the feet are moving up or down, in metres per second. Negative
     * is falling. Zero whenever there is something underneath.
     */
    fall: number;
    /** Whether the feet are on something. */
    footing: boolean;
    /** Height of the eye, which catches up with the floor rather than jumping. */
    eye: number;
    /**
     * How far this person's eye sits above the floor they are standing on.
     *
     * Carried on the player rather than looked up each frame: the level cannot
     * change who you are while you are walking around in it.
     */
    eyeAbove: number;
    /**
     * How tall this person is, to the top of the head.
     *
     * The eye is not the top of somebody, and a ceiling is hit by the part of
     * them that is highest. Kept beside `eyeAbove` rather than derived back out
     * of it, because dividing an eye height by `EYE_OF_STATURE` to recover a
     * stature reads as arithmetic when what it really is, is the same fact
     * written down twice.
     */
    stature: number;
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
    /**
     * Whether the player asked to jump *since the last frame was read*, rather
     * than whether the key is down.
     *
     * Every other field here is a state — how hard something is being pushed
     * right now — and this one alone is an event, because holding the key down
     * has to be one jump and not a bounce for as long as the finger is there.
     * `read()` hands it over once and forgets it.
     */
    jumping: boolean;
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
    // Whoever the level says you are, at your own eye height rather than at one
    // height for all six of them.
    const stature = statureOf(level.playerSprite);
    const eyeAbove = stature * EYE_OF_STATURE;

    const standingOn =
        forced === null
            ? sectorAt(level.sectors, level.spawn.x, level.spawn.z)
            : sectorAt(level.sectors, forced.x, forced.z);

    const x = forced?.x ?? level.spawn.x;
    const z = forced?.z ?? level.spawn.z;
    const y = standingOn === null ? 0 : floorAt(standingOn, x, z);

    return {
        x,
        z,
        yaw:
            forced === null
                ? -THREE.MathUtils.degToRad(level.spawn.angle)
                : THREE.MathUtils.degToRad(forced.yaw),
        pitch: forced === null ? 0 : THREE.MathUtils.degToRad(forced.pitch),
        y,
        // Standing, not dropped in. A spawn point is a place somebody is
        // already at, and starting every level with a frame of falling would
        // show as a flinch on the first frame of every game.
        fall: 0,
        footing: true,
        eye: y + eyeAbove,
        eyeAbove,
        stature,
        walked: 0,
    };
}

/**
 * How far above the floor a person's eye sits: their own height, less the part
 * of them that is above their eyes.
 */
export function eyeHeightOf(sprite: string): number {
    return statureOf(sprite) * EYE_OF_STATURE;
}

/**
 * How tall a person stands.
 *
 * Somebody the table has never heard of gets the default stature, which is a
 * guess about how tall they are rather than a guess about their anatomy.
 */
export function statureOf(sprite: string): number {
    return HEIGHTS[sprite] ?? DEFAULT_PLAYER_HEIGHT;
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

        // Lifted so the two floors meet, which is what makes a portal between
        // rooms at different heights read as one continuous space rather than
        // a hole into a room three metres beneath you.
        //
        // The eye is left to catch up rather than moved with it. `settleEye`
        // smooths a change of floor by design and a crossing is a change of
        // floor — a stride's worth arriving over a tenth of a second, which is
        // what a step up looks like. Moving it here as well would double the
        // change and put the camera through the ceiling for a frame.
        player.y += through.rise;
    }
}

/**
 * The height of the floor under the player's feet, right now.
 *
 * Outside the floor plan there is nothing to stand on and nothing to fall to
 * either, so the feet are left where they are. `walkPlayer` refuses any step
 * that would land there, so in play this only happens to a spawn point somebody
 * put outside every room — and dropping them for ever is a worse answer to that
 * than leaving them standing on nothing.
 */
function groundUnder(player: Player, standingIn: Sector | null): number {
    return standingIn === null
        ? player.y
        : floorAt(standingIn, player.x, player.z);
}

/**
 * The highest the feet can go before the head is in the ceiling.
 *
 * A sky room counts, and deliberately. It has no ceiling drawn, but it has a
 * lid at `ceilingHeight` that writes depth and occludes, and `build/boundaries`
 * has always measured headroom against that number whether or not anything was
 * painted on it. A ceiling you can walk under but not jump through is what the
 * rest of the engine already believes is there; this is the first thing to ask
 * it out loud.
 *
 * Outside the floor plan there is no ceiling either, which matters because it
 * is the only case where a jump has nothing over it at all.
 */
function headroomUnder(player: Player, standingIn: Sector | null): number {
    return standingIn === null
        ? Infinity
        : ceilingAt(standingIn, player.x, player.z) - player.stature;
}

/**
 * Leaves the ground, if there is any ground to leave.
 *
 * Refused in mid-air rather than counted and spent later. A jump queued while
 * falling and released on landing is a real design — it is how a lot of games
 * make stairs feel forgiving — but it is a feel decision, and the task this
 * belongs to was scoped to *a* jump. Adding a buffer nobody asked for would be
 * the thing this board keeps calling a decision made while building something
 * else.
 */
export function jumpPlayer(player: Player): void {
    if (!player.footing) {
        return;
    }

    player.fall = JUMP_SPEED;
    player.footing = false;
}

/**
 * Gravity, one frame of it: where the feet are, and how fast they are moving.
 *
 * ## Why this needs no sub-stepping, and horizontal movement does
 *
 * A fall gets fast. At `MAX_FRAME_SECONDS` it passes 0.68 m in a single frame
 * after 1.39 s and 9.4 m, and level 8 has 15 m ceilings and a staircase, so
 * anything that falls in it goes well past the 0.68 m that horizontal
 * collision would need chopping up to survive.
 *
 * It does not need chopping up, and the reason is worth writing down because
 * it is not the reason it holds sideways. A wall is an **infinitely thin
 * segment**, so a step long enough to land on the far side of one passes
 * through it without ever being inside it — which is why `RUN_SPEED *
 * MAX_FRAME_SECONDS` has to stay under `2 * PLAYER_RADIUS`. A floor is not
 * thin: it is a **plane under the whole room**, and so is a ceiling. There is
 * no far side of either to arrive on. Both tests below are written against the
 * interval the feet travel through, and an interval cannot straddle a plane
 * without ending past it. Exact at any speed, evaluated where the feet are
 * going rather than where they were, and it costs one comparison each.
 *
 * So the danger a fast fall brings is not these lines. It is that a falling
 * player also moves sideways, and the sideways half is still the unswept test
 * it always was.
 *
 * ## Why a short drop is not a fall
 *
 * A stair tread is `MAX_STEP` or less, and `build/boundaries.ts` has already
 * decided that means walkable. Falling down it would be correct and would feel
 * wrong: descending a flight would become a run of little drops, each with the
 * eye catching up after it, and the whole staircase would stutter. So a drop no
 * longer than a step, taken by somebody already on their feet, puts the feet
 * down rather than starting a fall. Anything further is a fall.
 *
 * The gate on that is `footing` rather than "not moving vertically", and the
 * difference is a head bump: stopping dead under a ceiling leaves the speed at
 * zero half a metre up, and a player whose feet are off the ground has not
 * arrived anywhere just because they have stopped rising.
 */
export function fallPlayer(
    player: Player,
    standingIn: Sector | null,
    seconds: number,
): void {
    const ground = groundUnder(player, standingIn);

    if (player.footing && player.y - ground <= MAX_STEP) {
        player.y = ground;
        player.fall = 0;

        return;
    }

    const was = player.fall;

    player.fall = Math.max(-TERMINAL_FALL, was - GRAVITY * seconds);

    // The distance covered is the *average* of the speed at both ends of the
    // frame, not the speed at the end of it.
    //
    // `y += v * seconds` after updating `v` is the obvious form and it is
    // wrong by half a frame of acceleration, every frame. That is not a
    // rounding error: at a twentieth of a second it takes a 0.899 m jump down
    // to 0.797 m, which is under the 0.8 m ledge the height was chosen to
    // clear — and it takes it somewhere *else* at a different frame rate, so
    // how high somebody can jump would depend on how fast their machine is.
    //
    // Averaging the two ends is exact rather than merely closer, because
    // gravity is constant over the frame and the area under a straight line is
    // its mean times its width. It costs one addition.
    const step = ((was + player.fall) / 2) * seconds;

    if (step > 0) {
        const highest = headroomUnder(player, standingIn);

        if (player.y + step >= highest) {
            // Stopped dead, not bounced. A head hitting a ceiling loses the
            // speed it had; what happens next is gravity's, the same as the top
            // of any jump.
            //
            // The floor wins a room too short to stand up in. Otherwise a
            // ceiling below the top of somebody's head would push their feet
            // through the floor to make room, which is a worse answer to bad
            // authoring than leaving them standing in it.
            player.y = Math.max(ground, highest);
            player.fall = 0;
            player.footing = highest <= ground;

            return;
        }

        player.y += step;
        player.footing = false;

        return;
    }

    if (player.y + step <= ground) {
        player.y = ground;
        player.fall = 0;
        player.footing = true;

        return;
    }

    player.y += step;
    player.footing = false;
}

/**
 * Brings the eye towards where the head is.
 *
 * On the ground it catches up rather than jumping, so a step up reads as a step
 * rather than as a cut. In the air it goes exactly where the head is: the
 * smoothing is there to soften a change of floor, and a fall is not a change of
 * floor. Smoothed, the camera would trail behind the body all the way down and
 * the landing would arrive before the picture of it did.
 *
 * Wading takes the eye down into the water by the same amount the body sinks,
 * and does it here rather than in the fall, so that standing in water is a
 * thing the camera does and not a thing gravity does.
 */
export function settleEye(
    player: Player,
    standingIn: Sector | null,
    seconds: number,
): void {
    const wading = standingIn?.isWater === true ? WADE_DEPTH : 0;
    const want = player.y + player.eyeAbove - wading;

    if (!player.footing) {
        player.eye = want;

        return;
    }

    player.eye += (want - player.eye) * Math.min(1, STEP_SMOOTHING * seconds);
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
