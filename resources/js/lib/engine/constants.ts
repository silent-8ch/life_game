/**
 * How far up a person their eyes are, as a fraction of how tall they stand.
 *
 * A ratio rather than a height, because the camera belongs to whoever the level
 * says the player is, and the six of them are not the same size. It was one
 * number — 1.62 — which is exactly Luke's stature, so everybody stood at Luke's
 * height and William, at 1.55, floated seven centimetres above his own head and
 * looked down on his own reflection. Paul noticed it in a mirror, which is the
 * only place the camera and the body are both on screen at once.
 *
 * And it is a fraction of stature rather than stature itself, because eyes are
 * not on top of a skull. Standing eye height is about 93% of standing height in
 * the anthropometric tables, and the remaining 7% is the top of the head. Using
 * the stature would have fixed the ordering — William below Paul — while
 * leaving everybody a little too tall, which is a subtler wrong than the one
 * being fixed and would have been much harder to notice.
 *
 * Heights are in `sprite-actor.ts`, mirrored from `LevelAssets::HEIGHTS`.
 */
export const EYE_OF_STATURE = 0.93;

/** The player is a circle on the floor plan, never a box. */
export const PLAYER_RADIUS = 0.34;

export const WALK_SPEED = 3.2;
export const RUN_SPEED = 5.4;

export const MOUSE_SENSITIVITY = 0.0022;

/** Stop just short of straight up and straight down, so the horizon never flips. */
export const MAX_PITCH = Math.PI / 2 - 0.05;

export const FIELD_OF_VIEW = 75;
export const NEAR_PLANE = 0.05;

/**
 * How far in front of the eye a portal pane is held once the player is nearly
 * touching it. Comfortably past NEAR_PLANE, or the near plane clips the pane
 * and the last few centimetres of walking through a portal show the sky.
 */
export const PANE_CLEARANCE = 0.12;
export const FAR_PLANE = 100;

/** How far away you can still look at something, in metres. */
export const REACH = 2.4;

/** Spacing of the lines drawn across untextured walls and floors, in metres. */
export const GRID_SPACING = 1;

/** How much wall or floor one tile of a texture covers, in metres. */
export const TEXTURE_METRES = 2;

/** The tallest step the player can walk up or down without being stopped. */
export const MAX_STEP = 0.55;

/** Headroom needed to fit through a gap between two sectors. */
export const MIN_HEADROOM = 1.2;

/** How far the player sinks when standing in water. */
export const WADE_DEPTH = 0.35;

/** How fast the eye catches up with a change of floor height. */
export const STEP_SMOOTHING = 12;

/**
 * How fast a fall gains speed, in metres per second per second.
 *
 * Earth's, because the game is a house. Most shooters run two or three times
 * this so a jump feels crisp, and that choice belongs to the task that adds a
 * jump — until then there is nothing to tune against, and a made-up number
 * would be a decision made while building something else.
 */
export const GRAVITY = 9.81;

/**
 * The fastest a fall gets, in metres per second.
 *
 * Roughly what a person reaches falling belly-down through air. Nothing in any
 * level is tall enough to approach it; it is here so that a bug which lets
 * somebody fall out of the world does so at a speed the arithmetic below still
 * holds for, rather than accelerating without limit until a single frame's step
 * overflows into something that stops behaving like a number.
 */
export const TERMINAL_FALL = 55;

/**
 * How fast the feet leave the ground on a jump, in metres per second.
 *
 * Chosen from the height rather than by feel: a jump rises `v² / 2g`, so 4.2
 * against `GRAVITY` reaches **0.899 m** and comes back down 0.86 s later. The
 * height is what was asked for — a 0.8 m ledge should become somewhere you can
 * get to — and the 10 cm over it is what makes landing on one possible rather
 * than exactly, barely, theoretically possible.
 *
 * It is a speed rather than a height because that is what the integrator takes,
 * and because a jump that set its own apex would have to know about gravity
 * twice.
 */
export const JUMP_SPEED = 4.2;

/** Reflections render into a buffer this size, kept small to stay coarse. */
export const MIRROR_TEXTURE_WIDTH = 512;
export const MIRROR_TEXTURE_HEIGHT = 288;

/**
 * The largest a pane's buffer may grow to when it is matched to the screen.
 *
 * Panes are sized to the surface they are drawn on rather than fixed, because a
 * portal walked up to covers the whole view and is then the picture itself. The
 * cap is here because that is a buffer per pane per bounce, several megabytes
 * apiece, and a very large display would otherwise ask for all of them at once.
 * Above this the pane is magnified again, but from a size where the stretch is
 * small enough not to crawl.
 */
export const PANE_TEXELS_ACROSS = 2048;
export const PANE_TEXELS_DOWN = 1152;

/**
 * The most times a pane may be seen through another pane.
 *
 * **A ceiling now, not a setting.** With `aperture.ts` measuring how much of the
 * screen is left at each bounce, a chain ends where its reflection stops
 * overlapping the one showing it — which in a room of mirrors happens on its
 * own, well before this. Measured in `hall-of-mirrors` at floor 0.01: the room
 * reaches 23 bounces and stops, and raising this to 48 or 64 changes nothing at
 * all. The mirrored octagon reaches 33.
 *
 * That is the whole point of the change. Before, this number *was* how deep a
 * mirror went, and there was no depth that was right for every room: too small
 * and a corridor ended in mid-air, too large and the cost went to branches
 * nobody could see. Now it only has to be beyond where any room's geometry runs
 * out, and thirty-two is.
 *
 * Paul, on sixteen: *I am still seeing walls far off in the reflections.* Those
 * were real and they were this constant — the last bounce, where the mirror
 * comes out and the wall it hangs on is drawn instead. A corridor of mirrors
 * does not shrink to nothing quickly: an 8m room seen at sixteen bounces is
 * still about 2% of the screen tall. At twenty-three it is under one, which is
 * where the openings close, and that is as far as the illusion can be seen.
 *
 * It costs a render target per pane per depth, so it is not free — see
 * `scaleAt` in portal-surface.ts, which drops a target to a sixteenth beyond
 * the first few levels and is what makes this many affordable.
 */
export const PORTAL_BOUNCES = 32;

/**
 * **There is no draw budget any more, and this records why rather than being
 * one.**
 *
 * There were two, in turn, and each was a source of the faults it was meant to
 * prevent. A running count of passes, spent depth-first, is an *ordering*: the
 * corridor is walked first and drills to the bottom, the branches beside it
 * meet an empty purse and draw a room with no mirrors in it. One depth for the
 * whole frame, moved between frames to hold the cost near a target, fixes that
 * and buys a swing instead — every chain ends at that depth, so moving it moves
 * every ending at once, and a wall blinks on and off at the back of every
 * reflection in the room.
 *
 * Paul, deciding it: *what happens when we remove the budget? safety for the
 * engine should be the level designer's job.* So what bounds a frame now is
 * `aperture.ts` — a branch ends where its reflection stops overlapping the one
 * showing it — and `PORTAL_BOUNCES` as a backstop the geometry reaches first.
 *
 * What that costs, measured in his four-mirror room: 636 passes a frame against
 * 520 under the controller, for depth 38 against 20 and slightly less bare wall.
 * Full depth on the **first** frame, with no ramp, and nothing left that can
 * vary between frames while the room does not — which is the whole of why the
 * flicker cannot come back.
 *
 * If a level ever does cost too much, the answer is fewer mirrors facing each
 * other in it, and that is a thing a person can see and decide. It is not a
 * number in here choosing which of somebody's walls to ruin.
 */

/**
 * How many times a frame will let action lines drive action lines before it stops.
 *
 * A thing that answers a line may put another line on, so a chain has to reach
 * the end of itself inside one frame or it reads as lag. Eight is deeper than
 * any arrangement anybody has drawn and shallow enough to cost nothing.
 *
 * Bounded rather than run to a resting state, because some arrangements do not
 * have one: a ring of things driving each other is a redstone clock, and
 * somebody will build one on purpose. `RESOLVE_PASSES` bounds itself the same
 * way and for the same reason.
 */
export const ACTION_LINE_PASSES = 8;

export const BACKGROUND_COLOR = '#05070a';

/**
 * Longest frame the simulation will accept, so a stalled tab cannot teleport you.
 * Walls are infinitely thin, so collision only holds while a single step stays
 * shorter than the player's diameter: RUN_SPEED * MAX_FRAME_SECONDS < 2 * PLAYER_RADIUS.
 */
export const MAX_FRAME_SECONDS = 0.05;
