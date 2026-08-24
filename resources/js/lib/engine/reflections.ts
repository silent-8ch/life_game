import * as THREE from 'three';
import type { Actors } from '@/lib/engine/actors';
import {
    anAperture,
    APERTURE_FLOOR,
    APERTURE_HOLD,
    apertureOf,
    copyAperture,
    flipAcross,
    narrow,
    WHOLE_SCREEN,
    worthDrawing,
} from '@/lib/engine/aperture';
import type { Aperture } from '@/lib/engine/aperture';
import type { PropSet } from '@/lib/engine/build/things';
import {
    PANE_CLEARANCE,
    PANE_MILLISECONDS,
    PORTAL_BOUNCES,
    PORTAL_RENDER_BUDGET,
} from '@/lib/engine/constants';
import type { PortalSurface } from '@/lib/engine/portal-surface';
import type { SkyDome } from '@/lib/engine/sky';
import type { SpriteActor } from '@/lib/engine/sprite-actor';

/**
 * Every pane in the level, drawn before the main render begins.
 *
 * A mirror and a portal are the same thing here: a surface showing the room as
 * some other camera sees it. A mirror's camera is the player reflected in the
 * wall; a portal's is the player carried through to the far mouth. Both are the
 * only passes that draw the player's own body — you cannot see yourself, only
 * what a mirror or a portal makes of you.
 *
 * These have to happen before the main render rather than during it. A render
 * nested inside another loses the rest of the outer pass, and everything
 * transparent that was still to be drawn — the people — drops out of the frame.
 *
 * @returns a function that refreshes every pane for the coming frame.
 */

/** Nothing was drawn one level in, and no array had to be made to say so. */
const NONE: readonly PortalSurface[] = [];

/**
 * The panes to recurse into, with a tunnel's own continuation at the front.
 *
 * A pane that can see itself is a **tunnel** — the demo's loop portal is a
 * corridor with no end, and level 8's stairs are the same shape. A tunnel is
 * the one case where depth *is* the picture rather than a refinement of it:
 * every bounce is another length of corridor, and the last one that is not
 * drawn is a hole at the end showing the sky.
 *
 * The draw budget is finite, so the order it is spent in decides what runs out.
 * In array order a mirror three rooms away that happens to fall inside the cone
 * takes bounces the corridor needed. Putting the pane's own continuation first
 * spends the depth on the thing made of depth, and the incidental panes get
 * what is left — which is what they were getting anyway, one level shallower.
 *
 * **Nothing is skipped and no budget is added.** The same panes are visited;
 * only the order changes. Measured at `portals-loop-wide`: 72 px of backdrop
 * where the tunnel ran out, none after.
 *
 * Returns the array unchanged when there is nothing to move, so the common case
 * — a pane that cannot see itself — allocates nothing.
 */
export function tunnelFirst<T>(panes: readonly T[], pane: T): readonly T[] {
    const at = panes.indexOf(pane);

    if (at <= 0) {
        return panes;
    }

    return [pane, ...panes.slice(0, at), ...panes.slice(at + 1)];
}

export function prepareReflections(
    mirrors: PortalSurface[],
    portals: PortalSurface[],
    playerSprite: SpriteActor,
    actors: Actors,
    props: PropSet,
    camera: THREE.PerspectiveCamera,
    sky: SkyDome | null,
    /**
     * Whether the player's own body should be drawn at all this frame.
     *
     * Asked rather than assumed because of invisible rooms: standing in one,
     * you are not drawn, and a mirror or a portal is the only place that could
     * possibly show it. Everywhere else the answer is always yes.
     */
    playerSeen: () => boolean = () => true,
): (renderer: THREE.WebGLRenderer, scene: THREE.Scene) => void {
    // A mirror is a pane like any other; only the camera that draws it differs.
    const panes = [...portals, ...mirrors];

    /**
     * Draws one pane's view. The player's own body is shown as well: a mirror
     * has them in plain sight, and so does a pair of portals hung so that one
     * mouth looks back towards the other.
     */
    const drawPane = (
        pane: PortalSurface,
        renderer: THREE.WebGLRenderer,
        scene: THREE.Scene,
        from: THREE.PerspectiveCamera,
        depth: number,
    ): void => {
        const at = pane.viewerAt(from);

        playerSprite.faceViewer(at.x, at.z, at.yaw);
        actors.faceViewer(at.x, at.z, at.yaw);
        // The same trap as the sky, and for the same reason: this pass is
        // looked at from somewhere else entirely, and a billboard left facing
        // the player is edge-on or backwards in every pane that holds it.
        props.faceViewer(at.x, at.z);
        playerSprite.object.visible = playerSeen();

        // The sky is drawn around whoever is looking, and this pass is looked
        // at from somewhere else entirely. Left where the player is, it hangs
        // in the far room as slabs of hillside a few metres across, in front of
        // everything — which is what a portal full of grass was.
        sky?.follow(at.x, from.position.y, at.z);

        pane.render(renderer, scene, from, depth);

        playerSprite.object.visible = false;
    };

    const frustum = new THREE.Frustum();
    const seen = new THREE.Matrix4();

    /** Whether a pane is anywhere in what a camera can see. */
    const inViewOf = (
        pane: PortalSurface,
        from: THREE.PerspectiveCamera,
    ): boolean => {
        seen.multiplyMatrices(from.projectionMatrix, from.matrixWorldInverse);
        frustum.setFromProjectionMatrix(seen);

        return frustum.intersectsSphere(pane.bounds);
    };

    /**
     * Scratch rectangles, one per level of nesting.
     *
     * A rectangle per depth rather than one per call because the recursion is
     * depth-first and never has two live openings at the same level: the
     * measurement for a pane at depth `d` is finished with by the time its
     * sibling asks for one. Allocating in the middle of a frame is what this
     * avoids, and there are seventeen of them.
     */
    const measured: Aperture[] = Array.from(
        { length: PORTAL_BOUNCES + 2 },
        anAperture,
    );
    const kept: Aperture[] = Array.from(
        { length: PORTAL_BOUNCES + 2 },
        anAperture,
    );
    const inside: Aperture[] = Array.from(
        { length: PORTAL_BOUNCES + 2 },
        anAperture,
    );
    const own: Aperture[] = Array.from(
        { length: PORTAL_BOUNCES + 2 },
        anAperture,
    );
    const overlap: Aperture[] = Array.from(
        { length: PORTAL_BOUNCES + 2 },
        anAperture,
    );

    /**
     * Somewhere to keep each candidate's own opening while its siblings are
     * measured: one rectangle per level of nesting per pane in the level.
     *
     * Made once. A frame in a room of mirrors runs this a few hundred times and
     * an object allocated in there is an object the collector has to take back
     * during the frame it was made in, which is a stutter rather than a cost.
     */
    const held: Aperture[][] = Array.from({ length: PORTAL_BOUNCES + 2 }, () =>
        Array.from({ length: panes.length + 1 }, anAperture),
    );

    /** The rectangle a level of nesting works in, clamped to what exists. */
    const roomFor = (store: Aperture[], depth: number): Aperture =>
        store[Math.min(depth, store.length - 1)];

    /** Where a pane sits in the list, for indexing the arrays below. */
    const numbered = new Map<PortalSurface, number>(
        panes.map((pane, at) => [pane, at]),
    );

    const levels = PORTAL_BOUNCES + 2;

    /**
     * The frame each pane was last followed into at each depth.
     *
     * This is what gives the opening test hysteresis, and hysteresis is the
     * only thing that makes it steady for a viewer who is moving. A chain
     * followed on the previous frame is allowed to carry on at
     * `APERTURE_HOLD` of the usual floor; one that was not has to reach the
     * full floor to start. Without the memory there is a single line, and an
     * opening drifting across it blinks a wall on and off at the back of a
     * reflection — which is what Paul reported while running through the middle
     * of his four-mirror room.
     *
     * Flat and typed rather than a map of maps: it is read once per candidate
     * per level per frame, which in a room of mirrors is a few thousand times.
     */
    const followed = new Int32Array(panes.length * levels).fill(-1);

    /** Which frame this is, only ever compared with the line above. */
    let frames = 0;

    /**
     * How many levels deep every pane in the frame is allowed to go.
     *
     * One number for the whole frame, carried between frames and moved a level
     * at a time to hold the cost near what a frame can afford. That it is
     * **one** number is the point: a room too expensive for sixteen levels gets
     * shallower everywhere at once, rather than keeping whichever branch the
     * recursion happened to walk first and walling the rest.
     *
     * It starts low and climbs. The alternative — starting at `PORTAL_BOUNCES`
     * and falling — spends the first frame in a level nobody has measured
     * drawing the full tree, and the first frame is the one where every texture
     * and every render target is also being made.
     */
    let reach = 2;

    /**
     * How long the passes took last time, smoothed.
     *
     * Counting passes alone is not enough, and the reason is which part of a
     * pass is expensive. A level deep down is drawn into a target an eighth of
     * the size, so it costs almost no *pixels* — but it costs a whole scene
     * traversal and a full set of draw calls like any other, and that part does
     * not shrink at all. Six hundred passes is a few milliseconds of GPU and
     * most of a frame of CPU.
     *
     * So the depth is held against the clock as well as the count, and the
     * clock is what makes this fit a machine rather than a guess. A fast one
     * gets more levels; a slow one gets fewer, instead of a slideshow.
     *
     * Smoothed because a single frame is noisy — a texture upload or a
     * collection lands in one and would otherwise drop a level for no reason.
     */
    let took = 0;

    /** Frames running that the cost has been comfortably under, or over. */
    let patience = 0;
    let impatience = 0;

    /**
     * Whether this level has ever cost more than it was allowed.
     *
     * Until it has, nothing is known about what the room can afford and the
     * depth climbs a level a frame. After it has, the ceiling is known and
     * moving is what shows, so it slows right down.
     */
    let hasBeenOver = false;

    /**
     * How many frames running the cost has to stay on one side before the
     * depth moves. Deeper is slow and shallower is quick, because being a level
     * too deep costs frame rate and being a level too shallow costs a little
     * distance at the back of a reflection.
     *
     * At sixty a second these are a tenth and half a second.
     */
    const IMPATIENCE = 6;
    const PATIENCE = 30;

    /**
     * A brake, not a budget.
     *
     * Nothing should reach this: `reach` settles within two or three frames and
     * holds the count near the budget. It is here so that one frame in a level
     * nobody has tried cannot hang the tab while that happens. Generous on
     * purpose — a brake that is really a budget is the thing this replaced.
     */
    const PANIC = PORTAL_RENDER_BUDGET * 4;

    return (renderer, scene) => {
        // Whatever was pulled in front of the player last frame goes back where
        // it belongs before anything is drawn, or every other pane's camera
        // finds a wall-sized sheet hanging in the middle of the room.
        for (const portal of portals) {
            portal.release();
        }

        frames++;

        /** The panes the player can see for themselves this frame. */
        const inView = panes.filter((pane) => inViewOf(pane, camera));

        /** Passes drawn so far this frame, for the depth controller below. */
        let drawn = 0;

        const began = performance.now();

        /**
         * Draws a pane as seen from a viewpoint. Going deeper draws whatever
         * panes this one's own camera can see one level further in first, and
         * only then this one — by which time those panes are showing the view
         * from here rather than the view from the player. That is what puts one
         * mirror inside another, and one portal inside the last.
         */
        const deepen = (
            pane: PortalSurface,
            from: THREE.PerspectiveCamera,
            depth: number,
            allowed: number,
            /**
             * How much of the screen this pane is still showing, measured in
             * the NDC of the pass that is going to display it.
             *
             * The whole screen at the top level, and whatever survives the
             * intersection at every level below. It is what makes the tree
             * finite: a chain that wanders off to the side runs out of opening
             * within a couple of bounces and stops itself, so the depth goes
             * where the picture is instead of where the array order sent it.
             */
            aperture: Aperture,
        ): void => {
            // **A branch ends because of its own depth, never because another
            // branch spent the money first.**
            //
            // This used to also ask `spent < share` — a running count of passes
            // for the whole frame — and that one clause is what Paul saw as *I
            // can see many mirrors straight ahead, but reflections to the side
            // are showing as walls*. Depth-first, the corridor is drawn first
            // and drills to sixteen; by the time the recursion unwinds to the
            // side branches at depth one or two the purse is empty, so they get
            // no kids, and a pane with no kids draws a room with no mirrors in
            // it. Measured in `hall-of-mirrors`: **8 of the 12 passes at depth
            // one rendered bare walls**, 125 of 230 over the whole frame.
            //
            // A budget spent depth-first cannot be fair, because depth-first is
            // exactly an ordering. What bounds the cost now is `allowed`, which
            // is the same number for every branch in the frame, and the opening
            // test below, which is a property of the geometry rather than of
            // the order it was walked in. `reach` moves that number up and down
            // between frames to hold the pass count near
            // `PORTAL_RENDER_BUDGET`, so the room gets shallower **all at
            // once** or not at all.
            //
            // `PANIC` is not a budget, it is a brake: one frame in a level
            // nobody has tried yet must not be able to hang the tab while
            // `reach` finds its level. It should never fire twice in a row.
            const goesDeeper = depth < allowed && drawn < PANIC;

            /**
             * The panes this pass actually drew one level in.
             *
             * Kept, because it is not the same set as "every pane in the
             * level" and the difference is what a room of mirrors looked
             * like. See the showing loop below.
             */
            let drew: readonly PortalSurface[] = NONE;

            if (goesDeeper) {
                const inner = pane.aim(from);

                // This pane's own continuation first — see `tunnelFirst`.
                // Every branch gets the same purse, and that is the point.
                //
                // This used to spend one counter shared across the whole tree,
                // in array order. In a room of four mirrors the first sibling
                // in the array took the depth and the other three met an
                // exhausted budget and got nothing — so in a **perfectly
                // symmetric room one mirror looked right and three did not**,
                // decided by nothing but position in an array. Paul found that
                // with four captures ninety degrees apart from one spot, and no
                // geometry can produce it.
                //
                // Splitting what is left evenly means the room degrades
                // symmetrically: every mirror gets the same depth, and a
                // shallower one is shallower everywhere at once rather than
                // being the unlucky one.
                // Down the corridor before sideways.
                //
                // `tunnelFirst` puts a pane that can see itself at the front,
                // which is what a loop portal is. **A mirror never can** — it
                // is taken out of its own pass — so for a room of mirrors that
                // ordering does nothing and the depth went to whichever wall
                // happened to be first in the array. That is one wall with a
                // corridor in it and three without, which is what Paul saw when
                // he took four captures ninety degrees apart.
                //
                // What continues a mirror is the wall **facing** it. So after
                // the self case, the most opposed normal goes first: two panes
                // looking at each other are a corridor, and the depth belongs
                // to them rather than to a wall off to one side.
                // What is left of this pane's own opening, as its target sees
                // it. A mirror's camera draws the room left-for-right — that
                // is the turn keeping its basis right-handed — so the
                // rectangle has to be flipped to match, or every chain hunts
                // for its reflections down the wrong side of the picture.
                const shown = pane.mirrored
                    ? flipAcross(aperture, roomFor(inside, depth))
                    : copyAperture(aperture, roomFor(inside, depth));

                // **And clipped to the pane's own outline, which is the part
                // that was missing.**
                //
                // A chain is bounded by every opening along it including the
                // first, and the first was never applied: the top-level call
                // starts from `WHOLE_SCREEN` because that is what the player
                // can see, not what this mirror can. So at the first bounce
                // any pane anywhere on screen was accepted, whether or not it
                // was inside the mirror being looked into, and it went down
                // the chain carrying an opening that was in the wrong place.
                //
                // What that looks like: a pane a level in with a large opening
                // and no candidates overlapping it at all, so it draws a room
                // with no mirrors in it. In Paul's mirrored **octagon**, one
                // of those covered a quarter of the screen at the first
                // bounce, and bare wall came to 16 to 43 per cent of the view
                // depending on where he stood. A square room hid it: every
                // wall is in view from the middle and the openings are
                // symmetric about the screen, so clipping to them changed
                // almost nothing.
                //
                // `partner` is the right mesh to measure rather than `mesh`. A
                // mirror's camera stands behind its own glass, so its outline
                // in its own target is itself — and `buildMirrorPane` sets
                // `partner` to its own mesh, so this reads correctly for both.
                // A portal's camera stands at the **far** mouth, and it is
                // that mouth the view is bounded by.
                const mouth = pane.partner ?? pane.mesh;
                const outline = apertureOf(mouth, inner, roomFor(own, depth));

                const through =
                    outline === null
                        ? null
                        : narrow(shown, outline, roomFor(kept, depth));

                // Where each candidate's own opening is kept while the rest are
                // measured. One slot per pane at this level of nesting, handed
                // out in order, so nothing is allocated in the middle of a
                // frame and no sibling can overwrite another's rectangle.
                const slots = held[Math.min(depth, held.length - 1)];
                let taken = 0;

                const kids = (
                    through === null ? NONE : tunnelFirst(panes, pane)
                )
                    .slice()
                    .sort(
                        (a, b) =>
                            a.facing.dot(pane.facing) -
                            b.facing.dot(pane.facing),
                    )
                    .filter((other) => {
                        if (
                            other.mesh === pane.partner ||
                            !pane.onto.includes(other.home)
                        ) {
                            return false;
                        }

                        // Where the candidate lands on the screen this pane's
                        // camera draws, and then what survives being seen
                        // through this pane.
                        //
                        // This replaces a frustum test, and the difference is
                        // the whole of why a room of four mirrors could not be
                        // made deep. A frustum is the entire screen, so every
                        // pane saw every other one at every level and the tree
                        // branched by three per bounce — 43 million passes at
                        // sixteen deep, asked of a budget of ninety-six. The
                        // budget then decided *which* branch got the depth
                        // rather than how deep the room went, and it decided it
                        // by array order, which is the one thing a symmetric
                        // room cannot survive.
                        //
                        // An opening is not a screen. Two mirrors facing each
                        // other keep nearly all of it and run deep; a mirror
                        // off to one side is a sliver through the first bounce
                        // and nothing through the second, so that chain ends
                        // itself. Same panes, same budget, and the depth lands
                        // where the picture is.
                        const rect = apertureOf(
                            other.mesh,
                            inner,
                            roomFor(measured, depth),
                        );

                        if (rect === null) {
                            return false;
                        }

                        const left = narrow(
                            through as Aperture,
                            rect,
                            roomFor(overlap, depth),
                        );

                        if (left === null) {
                            return false;
                        }

                        // Two lines, not one — see `APERTURE_HOLD`. A chain
                        // already being followed carries on until it is well
                        // under; a new one has to reach the full floor.
                        const slot =
                            (numbered.get(other) as number) * levels +
                            Math.min(depth + 1, levels - 1);
                        const carryOn = followed[slot] === frames - 1;

                        if (
                            !worthDrawing(
                                left,
                                carryOn
                                    ? APERTURE_FLOOR * APERTURE_HOLD
                                    : APERTURE_FLOOR,
                            )
                        ) {
                            return false;
                        }

                        followed[slot] = frames;

                        copyAperture(left, slots[taken]);
                        taken++;

                        return true;
                    });

                // Deepest branch first, and spent from one counter.
                //
                // Dividing the purse evenly down the levels was tried and is
                // what Paul saw as **exactly one reflection per mirror**: eight
                // draws halve to four, then two, then one, and the corridor is
                // over by the third level. Depth cannot be rationed per level,
                // because depth is the one thing a tunnel is made of.
                //
                // So one counter, spent depth-first, with `tunnelFirst` putting
                // the branch that continues this pane at the front — it takes
                // the depth it needs and the siblings get what is left. The
                // fairness that matters is between the panes the player can
                // see, and that is kept by resetting the counter for each of
                // them below, so no mirror is shallow because of where it sits
                // in an array.
                kids.forEach((other, at) => {
                    deepen(other, inner, depth + 1, allowed, slots[at]);
                });

                drew = kids;
            }

            // Running out of `allowed` is the end of the tunnel; running out
            // of budget is not, and they must not share this branch. Tried, and
            // it looked worse: the tunnel-end fallback hands a pane the view
            // from one level out, which down a corridor of portals is very
            // nearly the same picture and reads as distance. Between two
            // mirrors at right angles it is a completely different view of the
            // room, stretched across a surface it was never taken for — which
            // is the smear this commit exists to remove, put back by the fix
            // for the black.
            //
            // So a pane the budget could not reach keeps an undrawn target and
            // shows black. Black is confusing; smeared is worse, because it
            // looks like a fault in the geometry rather than a missing draw.
            // The end of the tunnel is wherever this branch actually stops,
            // which is not only where it was allowed to.
            //
            // These were deliberately kept apart, and the reason was sound at
            // the time: the old ending handed a pane the view from one level
            // out, which between two mirrors at right angles is a different
            // room smeared across a surface it was never taken for. Better to
            // leave that branch alone than to paint it wrong.
            //
            // The ending is a **wall** now, and a wall is right wherever the
            // tunnel stops. Keeping them apart is what left every
            // budget-stopped branch ending on nothing at all: the mirrors were
            // only ever taken out of the picture at exactly `allowed`, so a
            // branch that ran out of purse first never terminated on a surface
            // and settled on black. That is also why asking for more bounces
            // made it worse rather than better — measured, at 32: the chain
            // stopped reaching the one level that would have ended it, and the
            // corridor went from two levels of content to none.
            const deepest = !goesDeeper;

            for (const other of panes) {
                if (!deepest) {
                    // **Only what this pass drew, and this is the whole of
                    // "many walls" the other way round.**
                    //
                    // Every pane in the level used to be shown one level in,
                    // whether or not this pass had drawn it there. A pane it
                    // had not drawn still held a picture at that depth — from
                    // some other chain, some other viewpoint — and a pane
                    // samples by screen position, so that picture was pasted
                    // onto this wall registered to a camera it was never taken
                    // from. Down a corridor of portals two adjacent viewpoints
                    // are nearly the same picture and it passes; between two
                    // mirrors at right angles it is a different room entirely,
                    // smeared across a surface at the wrong angle. That is
                    // *super stretched*, and it was 45 of every 290 showings in
                    // a four-mirror room, measured.
                    //
                    // A mirror that was not drawn comes out of the picture and
                    // the wall behind it is drawn instead — `buildWall` puts
                    // one a hair behind every mirror for exactly this. It costs
                    // nothing on screen, because the aperture test above only
                    // drops a pane whose reflection does not overlap this
                    // opening at all: it was not visible here to begin with.
                    //
                    // A portal is the opposite case and stays in. There is no
                    // wall behind a mouth — taking it out leaves a hole to the
                    // sky at the end of the corridor, which is the one thing
                    // this engine has already learned reads worse than
                    // anything else.
                    const wasDrawn = drew.includes(other);

                    other.mesh.visible = wasDrawn || !other.mirrored;

                    if (other.mesh.visible) {
                        other.show(depth + 1);
                    }

                    continue;
                }

                // The tunnel has run out of levels, and the picture it ends on
                // is now the level above it **at its own size**.
                //
                // Paul's design, and it is how a real infinity mirror works:
                // the deepest reflection contains the one above it, which
                // contains the one above that, so the image tiles into itself
                // and the eye cannot find the last bounce. What it costs is one
                // frame of lag per level for anything moving, which at eight
                // levels deep nobody can see.
                //
                // **What this replaces, and why it was both of his symptoms.**
                // The old fallback pulled that picture in from the edges by
                // `TUNNEL_SHRINK`, so it would read as a room further away.
                // Down a corridor of portals it does. Between two mirrors it
                // does not: the level above is the same room at the same size,
                // and shrinking it paints a picture across a surface it was
                // never taken for, which is his *super stretched*. Unscaled, it
                // lines up with the reflection it sits inside and the tiling
                // closes.
                //
                // Still the level above rather than this one, and still only
                // from the second level down. A mirror is taken out of its own
                // pass, but a **portal is not** — one hung to look back at
                // itself has to appear in its own view, which is what puts one
                // opening inside the last. So a pane fed its own current depth
                // would be sampling the target being written into, and
                // `ReflectionsTest` guards exactly that. The level above is a
                // different texture, so there is no such conflict.
                //
                // What has gone is the shrink, and the shrink was the smear.
                // A mirror comes out of the picture at the last bounce, and
                // the wall it hangs on is drawn in its place.
                //
                // That wall is real geometry — `buildWall` puts one a hair
                // behind every mirror — so the corridor ends on plaster the way
                // it ends in a real room of mirrors. Without it the panes at
                // the last level close into a loop showing each other, and a
                // loop with nothing to seed it settles on black and stays
                // there however many frames run. Measured at 86 to 100 per cent
                // of every pane's middle row, in a room whose floor and sky
                // reflected perfectly a few rows either side.
                //
                // **A portal is the opposite case and must not do this.** There
                // is no wall behind a mouth; there is the room it opens onto.
                // Taking the pane out would show whatever the tilt failed to
                // clip, and the tunnel of portals that this whole branch exists
                // for would end in a hole. So a mouth stays in and reads a
                // level further out, exactly as before.
                // **A mirror ends on the wall it hangs on, at every depth.**
                //
                // Not only at the last of a deep chain: the cheap pass at depth
                // zero — the one run for a pane the player cannot see — used to
                // show every *other* mirror at level zero, which is the
                // player's own view of them, pasted into a pass being drawn
                // from somewhere else entirely. In a square room that never
                // showed, because every wall was in view and there were no
                // cheap passes. In Paul's mirrored **octagon** half the walls
                // are behind you at any moment, and it was 24 wrong showings a
                // frame.
                //
                // The reason this is safe now and was not before is the
                // `readable` fallback it used to feed. A pane out of view is
                // drawn at level zero and no deeper, so a pass asking it for
                // level one got the nearest level that had ever been drawn —
                // level zero, the bare-walled one — and the first reflection
                // inside every mirror was a room with no mirrors in it. The
                // showing loop above no longer asks: a pane is shown only at a
                // level **this pass drew it at**, so nothing reads the cheap
                // pass except the player, who cannot see it.
                if (other.mirrored) {
                    other.mesh.visible = false;

                    continue;
                }

                if (depth >= 1) {
                    // **A mirror ends on the wall it hangs on.**
                    //
                    // It used to be handed the level above instead, so that the
                    // picture folded into itself and the eye could not find the
                    // last bounce. That is a lovely idea and it is not what it
                    // does: the level above was drawn from a camera one
                    // reflection further out, and a pane samples by *screen
                    // position*, so what got pasted on the wall was a different
                    // view of the room registered to a camera it was never
                    // taken from. Down a corridor of portals two adjacent
                    // viewpoints are nearly the same picture and it passes.
                    // Between two mirrors at right angles it is a different
                    // room at the wrong angle — which is *super stretched*, and
                    // it was 325 showings out of 462 in a four-mirror room.
                    //
                    // Hiding them was also tried, before this commit, and Paul
                    // said *i am not seeing a seamless infinite room, i see
                    // many walls*. He was right, and the walls were not the
                    // fault: the tree was starved by the draw budget and ended
                    // at the first or second bounce, so the walls landed where
                    // he was looking. With the opening test above deciding
                    // where a chain stops, a corridor now runs the full
                    // `PORTAL_BOUNCES` and the wall lands sixteen reflections
                    // back, a few pixels across — which is where the last
                    // bounce of a real infinity mirror is too.
                    //
                    // So: **no pane ever shows a picture taken from a camera
                    // other than the one looking at it.** A mirror that has run
                    // out of levels comes out — handled above, for every
                    // depth — and `buildWall` puts real plaster a hair behind
                    // it. What is left here is portals.
                    other.mesh.visible = true;

                    // **Paul's cheat, and the end of the tunnel.**
                    //
                    // At the last level a pane is fed *its own* picture from
                    // this same level, which already contains the level above
                    // it, which contains the one above that. The image tiles
                    // into itself and there is no last bounce to find. Each
                    // frame it gains a level and it converges on endless — the
                    // way a real infinity mirror does, which is also a feedback
                    // loop and also one frame behind.
                    //
                    // Safe wherever the pane being drawn is not the pane being
                    // shown, because those are different textures. Where they
                    // are the same it is safe only if that pane is taken out of
                    // its own pass — **a mirror is, a portal is not**, since a
                    // portal hung to look back at itself has to appear in its
                    // own view. That case reads the level above instead, which
                    // is what the whole tunnel end used to do and what
                    // `ReflectionsTest` guards.
                    const readsItself =
                        other === pane && other.mesh !== other.partner;

                    other.show(readsItself ? depth - 1 : depth);
                } else {
                    // The cheap pass, for a portal mouth the player cannot see.
                    //
                    // Mirrors have already come out above. A mouth cannot: it
                    // is an opening, and taking it out leaves a hole to the sky
                    // where the next room should be.
                    other.mesh.visible = other !== pane;

                    if (other !== pane) {
                        other.show(depth);
                    }
                }
            }

            drawn++;

            drawPane(pane, renderer, scene, from, depth);

            for (const other of panes) {
                other.mesh.visible = true;
            }
        };

        for (const pane of panes) {
            // A pane the player cannot see still needs its own view drawn, in
            // case another pane is looking at it, but it is not worth any depth.
            // Nothing reads that pass except the player, who cannot see it: a
            // pane that turns up in some other pane's reflection is drawn by
            // that chain, at that chain's depth, from that chain's camera.
            const seen = inView.includes(pane);

            deepen(pane, camera, 0, seen ? reach : 0, WHOLE_SCREEN);
        }

        const spentMs = performance.now() - began;

        // **How deep the next frame goes, decided by what this one cost.**
        //
        // The same number for every branch, which is the whole point: a room
        // that cannot afford sixteen levels gets shallower everywhere at once
        // rather than keeping one corridor and walling the sides. Paul drew the
        // room that proves it and reported exactly that failure twice.
        //
        // Held against two things, because one is not enough. The **count**
        // bounds memory and draw calls and is predictable. The **clock** is
        // what actually fits the machine: a pass deep down draws into a target
        // an eighth of the size and costs almost no pixels, but it costs a
        // whole scene traversal like any other, and that is the part that adds
        // up. Either being over is enough to give a level back.
        //
        // It moves one level at a time so the depth cannot flicker as the
        // player turns, and it grows only when the frame came in comfortably
        // under both — a controller that grows at its own threshold oscillates
        // across it for ever, and this one did, between nine levels and ten,
        // every frame. Three quarters is enough room for the next level, which
        // costs about a fifth more than the one before it. Two or three frames
        // to settle, which at sixty a second nobody can see.
        took = took === 0 ? spentMs : took * 0.9 + spentMs * 0.1;

        const over = drawn > PORTAL_RENDER_BUDGET || took > PANE_MILLISECONDS;
        const roomToGrow =
            reach < PORTAL_BOUNCES &&
            drawn * 5 < PORTAL_RENDER_BUDGET * 4 &&
            took * 5 < PANE_MILLISECONDS * 4;

        // **Slow, and asymmetric, because moving this at all is visible.**
        //
        // Paul, on the first version: *the walls flicker, they all do not show
        // every frame*. Changing `reach` moves where every chain in the level
        // ends at once, and the end of a chain is a wall — so a controller
        // that bobs across its own threshold blinks a wall in and out at the
        // back of every reflection in the room, together, which is far more
        // noticeable than one extra level of depth is worth.
        //
        // So: a single expensive frame does nothing. Going shallower needs the
        // cost to stay over for `IMPATIENCE` frames running, and going deeper
        // needs it to stay under four fifths of the allowance for `PATIENCE`
        // of them. A dead band between the two is the point — anywhere
        // inside it nothing moves at all, and in a room that fits comfortably
        // (which is now most of them, since the openings end the chains rather
        // than the budget) this never fires after the first half second.
        //
        // Shrinking is quicker than growing on purpose. Being one level too
        // deep costs frame rate, which is worse than being one level too
        // shallow.
        //
        // Four fifths rather than a half, which was the first try and cost
        // nine levels of depth for nothing: the counters are what stop the
        // bobbing, not the width of the band. One more level adds under a
        // tenth to the count at this depth, so growing at four fifths cannot
        // land over the line.
        if (over) {
            patience = 0;
            impatience += 1;
            hasBeenOver = true;
        } else if (roomToGrow) {
            impatience = 0;
            patience += 1;
        } else {
            patience = 0;
            impatience = 0;
        }

        // **Climb fast until the ceiling is found, then hold still.**
        //
        // Half a second of patience per level is right for keeping the depth
        // still and hopeless for arriving at it: from a standing start of two,
        // thirty frames a level is **fifteen seconds** to reach the twenty-odd
        // a room of mirrors can afford. Measured over that ramp in
        // `hall-of-mirrors`, bare wall covers 20.7% of the screen on the first
        // frame, 12.4% after a second, 2.9% after five and 1.1% once it
        // settles — so for the whole of that a player is looking at a room
        // with walls in it, which is exactly what Paul reported after the
        // flicker was fixed.
        //
        // Nothing is known about what a room costs until a frame has been over
        // budget, so until then there is nothing to be careful of: climb a
        // level a frame and find out, which takes well under a second. Once a
        // frame *has* been over, the ceiling is known and every move from then
        // on is visible, so patience applies.
        //
        // A room that never goes over never becomes patient and simply climbs
        // to `PORTAL_BOUNCES`, which is right: it can afford it.
        if (impatience >= IMPATIENCE) {
            reach = Math.max(1, reach - 1);
            impatience = 0;
            hasBeenOver = true;
        } else if (patience >= (hasBeenOver ? PATIENCE : 1)) {
            reach += 1;
            patience = 0;
        }

        // Back around the player, for the view they actually get.
        sky?.follow(camera.position.x, camera.position.y, camera.position.z);

        // What the player is about to be shown.
        for (const pane of panes) {
            pane.show(0);
        }

        // Last of all, and only for the view the player gets: a pane they have
        // walked right up to squares up to the screen, so the near plane cannot
        // cut a hole in it. Left until now because a pane held in front of the
        // player's face has no business turning up in another pane's view.
        for (const portal of portals) {
            portal.hug(camera, PANE_CLEARANCE);
        }

        // Put everyone back the way the main pass needs them.
        actors.faceViewer(
            camera.position.x,
            camera.position.z,
            camera.rotation.y,
        );
        props.faceViewer(camera.position.x, camera.position.z);
    };
}
