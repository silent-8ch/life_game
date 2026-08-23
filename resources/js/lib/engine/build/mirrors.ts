import * as THREE from 'three';
import type { BuildContext } from '@/lib/engine/build/context';
import {
    MIRROR_TEXTURE_HEIGHT,
    MIRROR_TEXTURE_WIDTH,
    PORTAL_BOUNCES,
} from '@/lib/engine/constants';
import { createPortalSurface } from '@/lib/engine/portal-surface';
import type { Edge } from '@/lib/engine/sectors';

/**
 * The left-for-right turn every mirror camera carries, so that its basis stays
 * right-handed. Shared: it is the same matrix for every wall in every level.
 */
const TURNED = new THREE.Matrix4().makeScale(-1, 1, 1);

/**
 * A mirror: the same pane as a portal, except that the camera drawing it is
 * the viewpoint reflected in the wall rather than carried through it, and
 * the plane it is clipped against is the wall itself.
 */
export function buildMirrorPane(
    ctx: BuildContext,
    edge: Edge,
    centre: THREE.Vector3,
    normal: THREE.Vector3,
    length: number,
    height: number,
): void {
    const { scene, materials, topology } = ctx;
    const geometry = materials.track(new THREE.PlaneGeometry(length, height));

    const eye = new THREE.Vector3();
    const ahead = new THREE.Vector3();

    /**
     * Reflection in this wall, as a matrix: `I - 2nn^T` about the plane, with
     * the shift that carries it off the origin. Built once — the wall does not
     * move — and the whole of what a mirror's camera is.
     */
    const reflection = ((): THREE.Matrix4 => {
        const { x, y, z } = normal;
        const away = normal.dot(centre) * 2;

        return new THREE.Matrix4().set(
            1 - 2 * x * x,
            -2 * x * y,
            -2 * x * z,
            away * x,
            -2 * y * x,
            1 - 2 * y * y,
            -2 * y * z,
            away * y,
            -2 * z * x,
            -2 * z * y,
            1 - 2 * z * z,
            away * z,
            0,
            0,
            0,
            1,
        );
    })();

    /**
     * A point reflected in the wall: measured from the middle of it,
     * bounced off, and put back. Reflecting the offset is the whole of it —
     * turning it round as well would leave the point where it started.
     */
    const across = (point: THREE.Vector3, into: THREE.Vector3): THREE.Vector3 =>
        into.copy(point).sub(centre).reflect(normal).add(centre);

    /** The viewpoint's eye, reflected in the wall. */
    const reflectEye = (from: THREE.PerspectiveCamera): THREE.Vector3 =>
        across(eye.setFromMatrixPosition(from.matrixWorld), eye);

    const surface = createPortalSurface({
        geometry,
        aim: (from, out) => {
            // The camera is the viewer's own, reflected in this wall — as a
            // matrix, not as a position and a place to look.
            //
            // It used to be built with `lookAt` from a reflected eye and a
            // reflected target. That is what three's own `Reflector` does, and
            // it is right *for `Reflector`*, which samples by projecting each
            // point through that camera: the projection undoes what `lookAt`
            // gets wrong. **`lookAt` cannot express a reflection.** It builds a
            // right-handed basis and a reflection is left-handed, so the camera
            // it produces is a rotation that happens to sit in the right place,
            // and its picture is the reflected room *not* flipped.
            //
            // Sampling by screen position needs the real thing. The reason it
            // is exact is that a point on the mirror plane lands on the same
            // pixel for the viewer and for the viewer's reflection — true only
            // when the second camera is `R · M`, with `R` the reflection. A
            // rotation that is merely near it is wrong by a flip, and worst
            // where the mirror faces you square on, because that is where the
            // most of it is on screen.
            out.matrixWorld
                .multiplyMatrices(reflection, from.matrixWorld)
                // ...and then turned left-for-right, which costs nothing and
                // is the difference between a mirror that nests and one that
                // does not.
                //
                // `R · M` alone has a determinant of −1, because a reflection
                // is left-handed. Three reverses the winding of every triangle
                // drawn through such a camera and does not know it has: the
                // renderer compensates for a negative determinant on an
                // **object** and never looks at the camera's. Every
                // single-sided material in the level is then culled inside
                // out — the panes first of all, so a mirror's view contains no
                // mirrors and there is nothing for the next level to nest in;
                // the sky, which is `BackSide`, so it goes black; and any prop
                // that did not ask for two sides.
                //
                // Flipping x in the camera's own space makes the basis
                // right-handed again. The picture it draws is the same picture
                // left-for-right, and the pane reads it back flipped, which is
                // one subtraction in the fragment shader. Measured equal to six
                // figures at every point tried, and on this wall's own plane it
                // lands on the same pixel as the player's own camera — the
                // identity the screen-space read is built on.
                .multiply(TURNED);

            // Three renders from `matrixWorldInverse` and never reads the
            // fields, so this looks like bookkeeping and is not: the rest of
            // this file asks the camera where it is standing.
            //
            // `aim` measures how far the camera is off the plane it clips
            // against, and picks both whether to tilt the near plane and what
            // bias to tilt it with from that one number. `viewerAt` turns every
            // billboard in the pass by the camera's own quaternion. Left
            // undecomposed, a mirror's camera answered *the world origin,
            // facing down -z* to both questions — so the distance was origin to
            // wall rather than eye to wall, off by however far the room happens
            // to sit from the middle of the level.
            //
            // Too small and the tilt is dropped, the wall behind the mirror is
            // never clipped away, and the pane shows whatever is on the far
            // side of it — the sky, usually. Too large and the far plane
            // collapses (see `ObliqueClipTest`) and the pane goes black. Both
            // at once, in one room, decided by nothing but where that room was
            // built. Which is what Paul saw: *some black mirrors, and some
            // mirrors are showing the sky*.
            //
            // Safe to decompose only because of the turn above: `R · M` alone
            // is left-handed, and decomposing that hands back a negative scale
            // and a quaternion that means nothing. Turned, it is a proper
            // rotation and comes apart cleanly.
            out.matrixWorld.decompose(out.position, out.quaternion, out.scale);
            out.matrixWorldInverse.copy(out.matrixWorld).invert();
        },
        viewerAt: (from) => {
            const behind = reflectEye(from);

            ahead.set(0, 0, -1).applyQuaternion(from.quaternion);
            ahead.reflect(normal);

            return {
                x: behind.x,
                z: behind.z,
                yaw: Math.atan2(-ahead.x, -ahead.z),
            };
        },
        mirrored: true,
        exitPoint: centre,
        exitNormal: normal,
        textureWidth: MIRROR_TEXTURE_WIDTH,
        textureHeight: MIRROR_TEXTURE_HEIGHT,
        bounces: PORTAL_BOUNCES,
        home: edge.sector.slug,
        onto: topology.seenFrom(edge.sector.slug),
    });

    // A mirror cannot see itself: the camera stands behind it.
    surface.partner = surface.mesh;

    surface.mesh.position.copy(centre);
    surface.mesh.rotation.y = Math.atan2(normal.x, normal.z);
    surface.mesh.updateMatrixWorld(true);
    surface.settle();

    scene.group.add(surface.mesh);
    scene.targets.push(surface.mesh);

    geometry.computeBoundingSphere();
    surface.bounds
        .copy(geometry.boundingSphere as THREE.Sphere)
        .applyMatrix4(surface.mesh.matrixWorld);

    scene.mirrors.push(surface);
}
