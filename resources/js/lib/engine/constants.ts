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

/**
 * How many times a portal may be seen through another portal. Each bounce is a
 * render target per pane and a pass per frame, so a portal hung to face itself
 * opens into a corridor this many rooms deep before the far end goes flat.
 *
 * **Sixteen rather than eight, and it costs less than eight did.** What kept
 * this number down was a render target the size of the screen per level per
 * pane. A reflection nine rooms away is a few pixels across, so those levels are
 * drawn smaller now — halving every couple of levels down to an eighth — and
 * seventeen levels come to about 30 MB a pane against 71 MB for the nine that
 * were there before.
 *
 * Paul, on a room of mirrors: *I see more but still not enough.* This is the
 * constant that decides how many there are.
 */
export const PORTAL_BOUNCES = 16;

/**
 * The most offscreen portal passes a frame may spend. A level thick with portals
 * would otherwise cost more than the frame it is in, so depth is given up before
 * frame rate is.
 *
 * ## Why 96
 *
 * It was 40, then 16, and 16 was too mean by a long way.
 *
 * The cut to 16 was measured — and measured in the wrong rooms. Level 8 and the
 * portal demo have two panes in view at the spots that were swept, and with the
 * share divided among the panes in view two is the case where a small budget
 * still buys a long chain. **A room of mirrors is the case that pays**: four in
 * view at 16 is four draws each, and Paul saw exactly that as *one reflection
 * per mirror*. He also noticed the corridors had lost levels, which is the same
 * arithmetic on portals — ten draws down to eight.
 *
 * At 96 a pane in a room of four gets twenty-four, which is a full chain of
 * `PORTAL_BOUNCES` with enough left for the branches beside it, and a corridor
 * gets more than it ever had. The floor below means the division can never
 * starve a pane whatever the budget is; this decides how much *more* than the
 * bare chain each one gets.
 *
 * The sweep that produced 16 is still worth reading, because what it got wrong
 * was where it looked rather than how it measured. At level 8's spawn — five
 * panes, two in view — over the scan's thirty fixed frames: 40 gave 793 passes
 * at 22.9 ms a frame, 24 gave 530 at 18.0, 16 gave 520 at 18.0, 8 gave 514 at
 * 19.6. The curve really does flatten there, and all eighteen scan spots really
 * were identical at 16 and 40.
 *
 * **None of those spots is a room of mirrors.** Eighteen identical pictures
 * said nothing about the one case that pays for depth, and the man playing it
 * found the difference in a morning. A sweep is only ever evidence about the
 * places it was swept.
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
export const PORTAL_RENDER_BUDGET = 96;

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
