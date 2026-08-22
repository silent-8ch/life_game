import * as THREE from 'three';
import type { LevelScene } from '@/lib/engine/build-scene';
import {
    MIRROR_TEXTURE_HEIGHT,
    MIRROR_TEXTURE_WIDTH,
    MIRROR_TINT,
    PORTAL_BOUNCES,
} from '@/lib/engine/constants';
import { createPortalSurface } from '@/lib/engine/portal-surface';
import type { Edge } from '@/lib/engine/sectors';

/**
 * A mirror: the same pane as a portal, except that the camera drawing it is
 * the viewpoint reflected in the wall rather than carried through it, and
 * the plane it is clipped against is the wall itself.
 *
 * The camera is built by looking from the reflected eye rather than by a
 * reflection matrix, because a matrix with a flip in it reverses the winding
 * of every triangle in the scene and turns one-sided surfaces inside out.
 */
export function buildMirrorPane(
    scene: LevelScene,
    edge: Edge,
    centre: THREE.Vector3,
    normal: THREE.Vector3,
    length: number,
    height: number,
): void {
    const geometry = scene.palette.track(
        new THREE.PlaneGeometry(length, height),
    );

    const eye = new THREE.Vector3();
    const at = new THREE.Vector3();
    const up = new THREE.Vector3();
    const ahead = new THREE.Vector3();

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
            const behind = reflectEye(from).clone();

            // Where the viewpoint is looking, reflected as well.
            ahead
                .set(0, 0, -1)
                .applyQuaternion(from.quaternion)
                .add(from.position);

            across(ahead, at);

            up.set(0, 1, 0).applyQuaternion(from.quaternion).reflect(normal);

            out.position.copy(behind);
            out.up.copy(up);
            out.lookAt(at);
            out.updateMatrix();
            out.matrixWorld.copy(out.matrix);
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
        readByFarCamera: true,
        tint: new THREE.Color(MIRROR_TINT),
        exitPoint: centre,
        exitNormal: normal,
        textureWidth: MIRROR_TEXTURE_WIDTH,
        textureHeight: MIRROR_TEXTURE_HEIGHT,
        bounces: PORTAL_BOUNCES,
        home: edge.sector.slug,
        onto: scene.seenFrom(edge.sector.slug),
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
