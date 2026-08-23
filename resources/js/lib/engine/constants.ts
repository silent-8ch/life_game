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

/** Radius of the sky drum. Far enough to read as distance, near enough to draw. */
export const SKY_RADIUS = 90;

/**
 * How far each horizon layer trails behind the player as they walk, as a
 * fraction of the distance travelled. Nearer layers trail further, which is
 * what makes them read as nearer.
 */
export const BACKDROP_LAG = 0.12;

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

/** Mirrors give back less light than they take. */
export const MIRROR_TINT = '#8a9aa4';

/**
 * How many times a portal may be seen through another portal. Each bounce is a
 * render target per pane and a pass per frame, so a portal hung to face itself
 * opens into a corridor this many rooms deep before the far end goes flat.
 */
export const PORTAL_BOUNCES = 8;

/**
 * The most offscreen portal passes a frame may spend. A level thick with portals
 * would otherwise cost more than the frame it is in, so depth is given up before
 * frame rate is.
 *
 * ## Why 16
 *
 * It was 40, set when a pane was rendered at a ninth of its current size. Panes
 * are full resolution now, so every pass costs nine times what it did and the
 * old number was nine times too generous.
 *
 * Measured at level 8's spawn — five panes, two of them in view — over the
 * scan's thirty fixed frames:
 *
 * | budget | passes | ms/frame | targets held |
 * | ------ | ------ | -------- | ------------ |
 * | 40     | 793    | 22.9     | 33           |
 * | 24     | 530    | 18.0     | 29           |
 * | 16     | 520    | 18.0     | 26           |
 * | 8      | 514    | 19.6     | 24           |
 *
 * **16 is where the curve flattens.** Going below it buys six passes out of
 * five hundred and spends the margin a level with more panes in view would
 * need; going above it buys nothing anybody can see. 22.9 ms a frame was most
 * of a frame's budget spent on views of rooms nobody was looking at.
 *
 * **The picture does not change.** All eighteen scan spots are identical at 16
 * and at 40, run either side of the change; so is level 8's spawn, which no
 * spot covers; and so is the loop portal's corridor at `portals-loop-wide`,
 * down to the deepest `twist` walls at the far end. The passes this removes
 * were drawing levels that nothing sampled.
 *
 * ## What this does not fix
 *
 * **Memory has no hard bound here and this does not give it one.** A target is
 * made per pane per depth the first time that depth is drawn, and is kept until
 * the level is torn down — so the ceiling is `panes * (PORTAL_BOUNCES + 1)`
 * targets whatever the budget is, and the budget only decides how long it takes
 * to get there. Level 8 reaches 26 of its 45 from a standing start. At 1080p
 * that is around 215 MB of colour buffers, and the ceiling is 373 MB.
 *
 * Lowering `PORTAL_BOUNCES` is what would bound that, and it is the wrong
 * trade: bounces are the length of a corridor of portals, which is the one
 * thing in here made entirely of depth. Freeing targets nobody has sampled for
 * a while is the fix, and it is a task rather than a constant.
 */
export const PORTAL_RENDER_BUDGET = 16;

/**
 * How much the view is pulled in from the edges at the end of a tunnel of
 * portals, standing in for the level that was never drawn. Bigger reads as
 * further off. It is a fudge and cannot be otherwise: the honest figure depends
 * on how far apart the two mouths are and where the player is stood, and the
 * whole point is that it is seen from the far end of a corridor, where nobody
 * can tell.
 */
export const TUNNEL_SHRINK = 1.45;

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
