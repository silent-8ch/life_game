import * as THREE from 'three';
import type { Actors } from '@/lib/engine/actors';
import type { PropSet } from '@/lib/engine/build/things';
import {
    PANE_CLEARANCE,
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

    return (renderer, scene) => {
        // Whatever was pulled in front of the player last frame goes back where
        // it belongs before anything is drawn, or every other pane's camera
        // finds a wall-sized sheet hanging in the middle of the room.
        for (const portal of portals) {
            portal.release();
        }

        /**
         * How much of the frame's budget this top-level pane has left.
         *
         * Per pane rather than per frame, and that is the fix for a room with
         * four mirrored walls. Paul drew one — an eight-metre square with every
         * side a mirror — and reported *some mirrors are black, some are super
         * stretched*.
         *
         * Four mutually visible panes means each recurses into the other three,
         * so the tree is 3^depth and `PORTAL_BOUNCES` of 8 asks for 6561 draws
         * against a budget of 40. Shared as one counter spent depth-first in
         * array order, the first pane in the list took the whole thing and the
         * other three got a single shallow draw each. That is both of his
         * words: **black** where a depth was never drawn at all, and
         * **stretched** because `readable()` falls back to the nearest depth
         * that *was* drawn — a target this pane holds from some other camera
         * this frame — and a pane samples by screen position, so a picture
         * taken from somewhere else smears across it.
         *
         * A share each cannot starve anybody. It does not make the deep case
         * cheap, and it is not meant to: what it guarantees is that every pane
         * the player can see is drawn from the player's own camera every frame,
         * and that no pane's depth depends on where it happens to sit in an
         * array.
         *
         * The corridor cases are untouched. A portal pair skips its own partner
         * two lines down, so its branching is nearly nothing and eight bounces
         * cost eight draws — well inside any share.
         */
        const inView = panes.filter((pane) => inViewOf(pane, camera));

        const share = Math.max(
            1,
            Math.floor(PORTAL_RENDER_BUDGET / Math.max(inView.length, 1)),
        );

        let spent = 0;

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
        ): void => {
            if (depth < allowed && spent < share) {
                const inner = pane.aim(from);

                // This pane's own continuation first — see `tunnelFirst`.
                for (const other of tunnelFirst(panes, pane)) {
                    // The far mouth is taken out of this view, so drawing what
                    // it holds is work for nobody. Skipping it is most of the
                    // saving: for an ordinary pair, the only pane in the room
                    // beyond is the partner, so that whole branch disappears
                    // and the budget goes where it can be seen — a portal hung
                    // to look back at itself.
                    if (other.mesh === pane.partner) {
                        continue;
                    }

                    // And only what stands in a room this pane can see into. A
                    // frustum knows nothing of walls, so without this every pane
                    // in the level that happened to fall in the cone would be
                    // drawn, and the depth would go on rooms that are not on the
                    // other side of this one at all. A doorway counts: a mirror
                    // one room further on is still in the picture, and if it is
                    // never drawn for this view its reflection sits frozen.
                    if (
                        pane.onto.includes(other.home) &&
                        inViewOf(other, inner)
                    ) {
                        deepen(other, inner, depth + 1, allowed);
                    }
                }
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
            const deepest = depth >= allowed;

            for (const other of panes) {
                if (!deepest) {
                    other.mesh.visible = true;
                    other.show(depth + 1);

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
                if (depth >= 1) {
                    other.mesh.visible = true;
                    other.show(depth - 1);
                } else {
                    other.mesh.visible = false;
                }
            }

            spent++;

            drawPane(pane, renderer, scene, from, depth);

            for (const other of panes) {
                other.mesh.visible = true;
            }
        };

        for (const pane of panes) {
            // A pane the player cannot see still needs its own view drawn, in
            // case another pane is looking at it, but it is not worth spending
            // the frame's depth on. What is in front of them gets that — and it
            // costs one draw, outside anybody's share, which is why the share is
            // divided among the panes in view rather than among all of them.
            const seen = inView.includes(pane);

            spent = 0;

            deepen(pane, camera, 0, seen ? PORTAL_BOUNCES : 0);
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
