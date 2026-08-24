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
import { PANE_CLEARANCE, PORTAL_BOUNCES } from '@/lib/engine/constants';
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
        window: Aperture,
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

        pane.render(renderer, scene, from, depth, window);

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
            // **Nothing but the geometry decides where a chain stops.**
            //
            // There is no budget here at all any more, and that is Paul's call:
            // *safety for the engine should be the level designer's job.* What
            // bounds a frame is the opening test below — a branch ends where
            // its reflection stops overlapping the one showing it — with
            // `PORTAL_BOUNCES` as a backstop that a mirrored room never
            // reaches, because the openings close first.
            //
            // Everything that used to bound it instead turned out to be a
            // source of the faults it was meant to prevent, and each was found
            // by him rather than by a measurement here:
            //
            // - A running count spent depth-first is an **ordering**, not a
            //   budget. The corridor is walked first and drills to the bottom;
            //   the branches beside it meet an empty purse and draw a room with
            //   no mirrors in it. *Many mirrors straight ahead, reflections to
            //   the side showing as walls* — 8 of the 12 passes at the first
            //   bounce rendered bare walls.
            //
            // - One depth for the whole frame, moved between frames to hold the
            //   cost near a target, fixes that unfairness and buys a swing:
            //   every chain ends at that depth, so moving it moves every ending
            //   at once. *The walls flicker* — and then, once that was slowed,
            //   *the walls still flicker when the user is not moving*, because
            //   a controller that can climb back to a depth it has already
            //   failed at oscillates whatever its patience.
            //
            // Both are gone. What is left cannot flicker at all, because there
            // is nothing left that varies between frames while the room does
            // not.
            const goesDeeper = depth < allowed;

            /**
             * The panes this pass actually drew one level in.
             *
             * Kept, because it is not the same set as "every pane in the
             * level" and the difference is what a room of mirrors looked
             * like. See the showing loop below.
             */
            let drew: readonly PortalSurface[] = NONE;

            // What this pass draws, and what it will be read back through.
            //
            // Hoisted out of the recursion below because every pass needs it,
            // not only one that goes deeper: a target is sized and cropped to
            // its own window now, which is what makes a reflection at the back
            // of a tunnel as sharp as one at the front.
            const inner = pane.aim(from);

            const shown = pane.mirrored
                ? flipAcross(aperture, roomFor(inside, depth))
                : copyAperture(aperture, roomFor(inside, depth));

            // `partner` is the right mesh to measure rather than `mesh`. A
            // mirror's camera stands behind its own glass, so its outline in
            // its own target is itself — and `buildMirrorPane` sets `partner`
            // to its own mesh, so this reads correctly for both. A portal's
            // camera stands at the **far** mouth, and it is that mouth the view
            // is bounded by.
            const mouth = pane.partner ?? pane.mesh;
            const outline = apertureOf(mouth, inner, roomFor(own, depth));

            const through =
                outline === null
                    ? null
                    : narrow(shown, outline, roomFor(kept, depth));

            // Nothing of this pane is showing, so there is no window to draw
            // through. Its own opening will do: it costs a slightly larger
            // buffer and nothing reads it.
            const window = through ?? shown;

            if (goesDeeper) {
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
                        other.show(depth + 1, window);
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

                    // ...and the room stands where its reflection was.
                    //
                    // This is where a chain stopped, so the pane comes out —
                    // it has no level left to show, and showing one taken from
                    // another viewpoint is the thing this renderer will not do.
                    // What is behind it is the wall it hangs on, and *only bare
                    // walls where mirrors should be* is the last thing Paul
                    // reported. `build/images.ts` puts a reflected copy of the
                    // room there instead, which is not a stand-in for the
                    // continuation but is the continuation — the method of
                    // images, in geometry rather than in cameras.
                    //
                    // Never for the pane being drawn. Its own image sits
                    // between its camera and the glass, on the wrong side
                    // entirely, and the tilted near plane is only mostly able
                    // to cut it away — the same slack that made `behind`
                    // necessary.
                    if (other !== pane) {
                        for (const room of other.image) {
                            room.visible = true;
                        }
                    }

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

                    other.show(readsItself ? depth - 1 : depth, window);
                } else {
                    // The cheap pass, for a portal mouth the player cannot see.
                    //
                    // Mirrors have already come out above. A mouth cannot: it
                    // is an opening, and taking it out leaves a hole to the sky
                    // where the next room should be.
                    other.mesh.visible = other !== pane;

                    if (other !== pane) {
                        other.show(depth, window);
                    }
                }
            }

            drawPane(pane, renderer, scene, from, depth, window);

            for (const other of panes) {
                other.mesh.visible = true;

                for (const room of other.image) {
                    room.visible = false;
                }
            }
        };

        for (const pane of panes) {
            // A pane the player cannot see still needs its own view drawn, in
            // case another pane is looking at it, but it is not worth any depth.
            // Nothing reads that pass except the player, who cannot see it: a
            // pane that turns up in some other pane's reflection is drawn by
            // that chain, at that chain's depth, from that chain's camera.
            const seen = inView.includes(pane);

            deepen(pane, camera, 0, seen ? PORTAL_BOUNCES : 0, WHOLE_SCREEN);
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
