/**
 * The numbers the level builder works to. They are here rather than in
 * engine/constants.ts because nothing outside the build has any use for them:
 * they describe how a surface is drawn, not how the game plays.
 */

/** How much of its line colour an untextured surface keeps. */
export const SOLID_TINT = 0.11;

export const POLYGON_OFFSET = 1;

/** How far a wall is nudged into its own sector, in metres. */
export const WALL_INSET = 0.01;

/**
 * Where the lids on open-to-sky rooms come in the draw order: after the sky,
 * which is at -1 and lays down no depth of its own, and before the rooms, which
 * are at 0 and are what the lids are there to hide.
 */
export const SKY_CEILING_ORDER = -0.5;

/**
 * A portal pane sits exactly in the mouth it fills, unlike an ordinary wall,
 * which is nudged a hair into its own room. Set even slightly forward and its
 * rim reads the far view outside the opening; set back, and walking past it at
 * an angle shows daylight through the gap it leaves. The rim is dealt with in
 * the shader instead, by reading a hair inside the pane rather than at its edge.
 */
export const PORTAL_RECESS = 0;

export const HIGHLIGHT_COLOR = '#ffffff';
