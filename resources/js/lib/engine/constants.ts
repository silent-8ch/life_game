/** Where the camera sits above the floor, in metres. */
export const EYE_HEIGHT = 1.62;

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

/** The render buffer is this many times smaller than the canvas, then upscaled. */
export const PIXEL_SCALE = 3;

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
 */
export const PORTAL_RENDER_BUDGET = 40;

/**
 * How much the view is pulled in from the edges at the end of a tunnel of
 * portals, standing in for the level that was never drawn. Bigger reads as
 * further off. It is a fudge and cannot be otherwise: the honest figure depends
 * on how far apart the two mouths are and where the player is stood, and the
 * whole point is that it is seen from the far end of a corridor, where nobody
 * can tell.
 */
export const TUNNEL_SHRINK = 1.45;

export const BACKGROUND_COLOR = '#05070a';

/**
 * Longest frame the simulation will accept, so a stalled tab cannot teleport you.
 * Walls are infinitely thin, so collision only holds while a single step stays
 * shorter than the player's diameter: RUN_SPEED * MAX_FRAME_SECONDS < 2 * PLAYER_RADIUS.
 */
export const MAX_FRAME_SECONDS = 0.05;
