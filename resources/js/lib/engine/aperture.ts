import * as THREE from 'three';

/**
 * How much of the screen is still showing, some way down a chain of panes.
 *
 * ## Why a chain of mirrors needs this and a corridor of portals did not
 *
 * A pane recursed into whatever fell inside its camera's frustum. A frustum is
 * the whole screen, so in a room with four mirrored walls every pane saw the
 * other three at every level and the tree branched by three per bounce: sixteen
 * bounces is 43 million passes asked of a budget of ninety-six. What the budget
 * then decided was not how deep the room went but *which* branch got the depth,
 * and the answer was whichever pane happened to come first — which is the one
 * thing a symmetric room cannot survive.
 *
 * A pane is not a screen, though. It is a **hole**, and what can be seen
 * through a hole is bounded by the hole. Two mirrors facing each other down a
 * room show each other at nearly full size, so that chain runs deep. A mirror
 * off to one side is seen through the first one as a sliver, and through two of
 * them as nothing at all — so that chain stops on its own, at the level where
 * it stopped being visible rather than at the level where the purse ran out.
 *
 * That is the whole of it: **the tunnel ends where the picture gets too small
 * to see**, which is where a real one ends, and it costs a rectangle
 * intersection per candidate rather than a policy.
 *
 * ## Why screen rectangles compose at all
 *
 * Because of the identity the rest of the engine already rests on: a pane
 * samples its target by the fragment's own screen position, so the target's
 * screen and the pass that displays it are the *same screen*. A rectangle
 * measured in one is a rectangle in the other, and nesting is intersection.
 * Nothing here is true of a `textureMatrix` read.
 *
 * Coordinates are NDC — −1 to 1 across and up — because that is what a
 * projection hands back and it makes the whole screen a constant.
 */
export type Aperture = {
    left: number;
    right: number;
    bottom: number;
    top: number;
};

/** Everything: what the player's own camera is looking through. */
export const WHOLE_SCREEN: Aperture = {
    left: -1,
    right: 1,
    bottom: -1,
    top: 1,
};

/**
 * How small a reflection may get before a chain stops following it, as a
 * fraction of the screen's width or height.
 *
 * This is the honest end of a tunnel. Below it the next level is a few pixels
 * across and holds nothing the eye can resolve, so drawing it buys a pass and
 * no picture. Above it the chain carries on however deep that turns out to be —
 * which is the point, because a corridor of two facing mirrors barely shrinks
 * per bounce and should run until `PORTAL_BOUNCES` stops it.
 *
 * A two-hundredth of the screen is about ten pixels across at 1080p. It is not
 * what bounds the cost — measured in `hall-of-mirrors`, moving it from a
 * thousandth to a twenty-fifth changed a frame from 245 passes to 210, because
 * what ends a chain is nearly always the openings failing to overlap at all
 * rather than getting small. It is here so that a chain which shrinks without
 * ever quite closing has an end.
 */
export const APERTURE_FLOOR = 0.005;

const corner = new THREE.Vector4();
const viewProjection = new THREE.Matrix4();

/**
 * Where a pane's own rectangle lands on the screen a camera draws, or null if
 * none of it does.
 *
 * The eight corners of the mesh's bounding box rather than the four of the
 * quad, because a pane is a quad only until something gives it thickness, and
 * a box that contains the quad can only ever be too generous — which is the
 * safe direction. Being too generous costs a pass; being too mean loses a
 * reflection that was really there.
 *
 * **A corner behind the camera makes the answer the whole screen.** Dividing by
 * a negative `w` puts a point on the wrong side of the screen and inside-out,
 * so a pane straddling the near plane would measure as a rectangle off in a
 * corner — and a pane straddling the near plane is exactly the one filling the
 * view. There is no cheap right answer, and the conservative one is free.
 */
export function apertureOf(
    mesh: THREE.Mesh,
    camera: THREE.Camera,
    into: Aperture,
): Aperture | null {
    // Nothing to measure is not the same as nothing there. A surface with no
    // geometry to ask — a stub, or a pane whose shape is decided later — is
    // treated as covering the whole screen, so it is never culled by a
    // measurement that could not be taken. The same reading as a corner behind
    // the camera, and for the same reason: too generous costs a pass, too mean
    // loses a reflection that was really there.
    if (
        mesh.geometry === undefined ||
        mesh.geometry === null ||
        mesh.matrixWorld === undefined
    ) {
        return copyAperture(WHOLE_SCREEN, into);
    }

    if (mesh.geometry.boundingBox === null) {
        mesh.geometry.computeBoundingBox();
    }

    const bounds = mesh.geometry.boundingBox;

    if (bounds === null) {
        return copyAperture(WHOLE_SCREEN, into);
    }

    viewProjection.multiplyMatrices(
        camera.projectionMatrix,
        camera.matrixWorldInverse,
    );
    viewProjection.multiply(mesh.matrixWorld);

    into.left = Infinity;
    into.right = -Infinity;
    into.bottom = Infinity;
    into.top = -Infinity;

    for (let at = 0; at < 8; at++) {
        corner.set(
            (at & 1) === 0 ? bounds.min.x : bounds.max.x,
            (at & 2) === 0 ? bounds.min.y : bounds.max.y,
            (at & 4) === 0 ? bounds.min.z : bounds.max.z,
            1,
        );

        corner.applyMatrix4(viewProjection);

        // Behind the camera, or exactly on it. Nothing measured from here can
        // be trusted, so the pane is treated as covering everything.
        if (corner.w <= 0) {
            into.left = -1;
            into.right = 1;
            into.bottom = -1;
            into.top = 1;

            return into;
        }

        const x = corner.x / corner.w;
        const y = corner.y / corner.w;

        into.left = Math.min(into.left, x);
        into.right = Math.max(into.right, x);
        into.bottom = Math.min(into.bottom, y);
        into.top = Math.max(into.top, y);
    }

    if (into.right < -1 || into.left > 1 || into.top < -1 || into.bottom > 1) {
        return null;
    }

    return into;
}

/**
 * What is left of an opening once it is seen through another one.
 *
 * Null when the two do not overlap, which is a chain that has run out of
 * anything to look through — the reflection is behind the edge of the mirror
 * showing it — and is the ordinary way a branch ends.
 */
export function narrow(
    outer: Aperture,
    inner: Aperture,
    into: Aperture,
): Aperture | null {
    into.left = Math.max(outer.left, inner.left);
    into.right = Math.min(outer.right, inner.right);
    into.bottom = Math.max(outer.bottom, inner.bottom);
    into.top = Math.min(outer.top, inner.top);

    if (into.right <= into.left || into.top <= into.bottom) {
        return null;
    }

    return into;
}

/**
 * Whether an opening is still worth drawing through.
 *
 * NDC spans two, so a span is halved to read as a fraction of the screen.
 */
export function worthDrawing(aperture: Aperture): boolean {
    return (
        (aperture.right - aperture.left) / 2 >= APERTURE_FLOOR &&
        (aperture.top - aperture.bottom) / 2 >= APERTURE_FLOOR
    );
}

/**
 * The same opening as the target of a **mirror** sees it.
 *
 * A mirror's camera carries a left-for-right turn — `R · M · flip` — so that
 * its basis stays right-handed and three does not cull every single-sided
 * surface in the level inside out. The picture it draws is therefore the
 * reflected room *flipped*, and the pane's shader flips it back when it reads
 * it.
 *
 * Which means a rectangle in the pass that displays the pane is the same
 * rectangle flipped about the middle of the screen inside the target. Miss this
 * and every chain through a mirror hunts for its reflections on the wrong side
 * of the view, which prunes exactly the branches that were really there and
 * keeps the ones that were not.
 */
export function flipAcross(aperture: Aperture, into: Aperture): Aperture {
    const { left, right } = aperture;

    into.left = -right;
    into.right = -left;
    into.bottom = aperture.bottom;
    into.top = aperture.top;

    return into;
}

/** A working rectangle, for a caller that needs somewhere to put one. */
export function anAperture(): Aperture {
    return { left: -1, right: 1, bottom: -1, top: 1 };
}

/** Copies one opening over another. */
export function copyAperture(from: Aperture, into: Aperture): Aperture {
    into.left = from.left;
    into.right = from.right;
    into.bottom = from.bottom;
    into.top = from.top;

    return into;
}
