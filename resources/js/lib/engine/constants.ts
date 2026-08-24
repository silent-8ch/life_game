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
 * About how many pane passes a frame should cost, across the whole level.
 *
 * **This is a target, not a cut-off, and that changed what the number means.**
 * It used to gate the recursion directly — a running count checked at every
 * node, so a branch stopped the moment the frame's money ran out. Depth-first,
 * that is an ordering rather than a budget: the corridor is walked first and
 * drills to `PORTAL_BOUNCES`, and by the time the recursion unwinds to the
 * branches beside it there is nothing left, so they get no depth at all. A pane
 * with no depth draws a room with no mirrors in it. Paul, in a room with four
 * mirrored walls: *I can see many mirrors straight ahead, but reflections to
 * the side are showing as walls.* Measured at his spot: **8 of the 12 passes at
 * the first bounce rendered bare walls**, and 125 of 230 over the frame.
 *
 * `reflections.ts` now holds one depth for the whole frame and moves it a level
 * at a time between frames to keep the count near this number. So the room gets
 * shallower everywhere at once, which is the only way a symmetric room can
 * degrade without looking broken, and this number decides *how deep* rather
 * than *who goes without*.
 *
 * ## Why 640
 *
 * It was 96 while it was still a cut-off, and 40 and 16 before that. As a target
 * it can be much larger, because what actually bounds the tree is the opening
 * test in `aperture.ts` — a branch ends where its reflection stops overlapping
 * the one showing it, which is a property of the room rather than of the purse.
 * Uncapped, a four-mirror room asks 662 passes for the full sixteen levels and
 * an octagon 980.
 *
 * 640 is above the first and below the second on purpose. It is not a number
 * anybody should trust further than the two rooms it was measured in; what
 * makes it safe is that being wrong costs levels rather than fairness, and
 * `PANE_MILLISECONDS` catches the case where a machine cannot afford what the
 * count allows.
 *
 * Most of those passes are cheap. `scaleAt` in portal-surface.ts halves a
 * target every couple of levels down to an eighth, so a pass at depth seven
 * costs a sixty-fourth of a pass at depth zero. Only the first three levels are
 * worth counting for pixels, and there are seventeen of them in a room of four
 * mirrors — but every pass costs a scene traversal whatever its size, which is
 * why there is a clock as well.
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
export const PORTAL_RENDER_BUDGET = 640;

/**
 * How long a frame should be willing to spend drawing panes, in milliseconds.
 *
 * The count above bounds draw calls and memory and is predictable. This bounds
 * the thing that actually decides whether the game is playable, and it is not
 * the same thing: `scaleAt` shrinks a deep target to an eighth, so a pass at
 * depth ten costs almost no pixels — but it costs a whole scene traversal and a
 * full set of draw calls like every other pass, and that part does not shrink.
 * Six hundred passes is a few milliseconds of GPU and most of a frame of CPU.
 *
 * Holding the depth against a clock rather than only a count is what makes the
 * illusion fit the machine it is running on. A fast one gets more levels of
 * mirror; a slow one gets fewer, which is the right way to be slow.
 *
 * Six of a sixteen-millisecond frame, leaving the rest for the pass the player
 * actually looks at, the physics and everything else. Measured on the frame's
 * own wall clock, which for this workload is mostly honest: the cost being
 * bounded is CPU-side scene traversal, and that is exactly what wall clock
 * catches. It undercounts GPU work, which is the smaller half here.
 */
export const PANE_MILLISECONDS = 6;

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
