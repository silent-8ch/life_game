import * as THREE from 'three';
import type { Actors } from '@/lib/engine/actors';
import type { PropSet } from '@/lib/engine/build/things';
import {
    PANE_CLEARANCE,
    PORTAL_BOUNCES,
    PORTAL_RENDER_BUDGET,
    TUNNEL_SHRINK,
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
export function prepareReflections(
    mirrors: PortalSurface[],
    portals: PortalSurface[],
    playerSprite: SpriteActor,
    actors: Actors,
    props: PropSet,
    camera: THREE.PerspectiveCamera,
    sky: SkyDome | null,
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
        playerSprite.object.visible = true;

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
            if (depth < allowed && spent < PORTAL_RENDER_BUDGET) {
                const inner = pane.aim(from);

                for (const other of panes) {
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

            const deepest = depth >= allowed;

            for (const other of panes) {
                if (!deepest) {
                    other.mesh.visible = true;
                    other.show(depth + 1);

                    continue;
                }

                // The tunnel has run out of levels. Rather than leave a hole at
                // the end of it — which shows the sky, a mouth having nothing
                // behind it — the panes are given the view from one level out,
                // pulled in from the edges so it reads as a room further away.
                // It is last frame's, this frame not having drawn it yet, and
                // at the far end of a corridor of portals nobody is going to
                // catch it lagging.
                //
                // Only from the second level down: at the first there is no
                // level out to borrow, so the pane goes instead, since a
                // texture cannot be read and written at once.
                if (depth >= 1) {
                    other.mesh.visible = true;
                    other.show(depth - 1, TUNNEL_SHRINK);
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
            // the frame's depth on. What is in front of them gets that.
            deepen(
                pane,
                camera,
                0,
                inViewOf(pane, camera) ? PORTAL_BOUNCES : 0,
            );
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
