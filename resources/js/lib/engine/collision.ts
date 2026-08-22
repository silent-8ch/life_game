/**
 * Collision is solved entirely on the floor plan: the player is a circle, walls
 * are line segments, and things are rotated rectangles. Nothing has a height as
 * far as this file is concerned — there is no jumping and no crouching.
 */

export type Point = { x: number; z: number };

export type SegmentCollider = {
    kind: 'segment';
    x1: number;
    z1: number;
    x2: number;
    z2: number;
    /**
     * The side it is solid from, if it is only solid from one.
     *
     * A wall is a line on the floor plan and a line has no sides, so an ordinary
     * collider stops the player from either direction — which is what you want
     * for a wall. The far face of a portal is the exception: it is a wall to the
     * room behind it and a way through to the room in front, and one two-sided
     * line laid across the mouth seals the portal for everybody.
     */
    facing?: Point;
};

export type BoxCollider = {
    kind: 'box';
    x: number;
    z: number;
    halfWidth: number;
    halfDepth: number;
    /** Yaw in radians. */
    angle: number;
};

export type Collider = SegmentCollider | BoxCollider;

/**
 * Corners are resolved by pushing out of one collider at a time, repeatedly.
 * Two walls meeting at a sharp angle need several goes: leaving one pushes back
 * into the other, and each pass only halves what is left. Too few and the
 * player settles a little way inside the corner, close enough for the camera's
 * near plane to cut through the wall.
 */
const RESOLVE_PASSES = 12;

const EPSILON = 1e-6;

function clamp(value: number, min: number, max: number): number {
    return Math.min(Math.max(value, min), max);
}

function signOf(value: number): number {
    return value < 0 ? -1 : 1;
}

function pushOutOfSegment(
    point: Point,
    segment: SegmentCollider,
    radius: number,
): Point {
    const dx = segment.x2 - segment.x1;
    const dz = segment.z2 - segment.z1;
    const lengthSquared = dx * dx + dz * dz;

    const t =
        lengthSquared < EPSILON
            ? 0
            : clamp(
                  ((point.x - segment.x1) * dx + (point.z - segment.z1) * dz) /
                      lengthSquared,
                  0,
                  1,
              );

    const closestX = segment.x1 + dx * t;
    const closestZ = segment.z1 + dz * t;

    const awayX = point.x - closestX;
    const awayZ = point.z - closestZ;
    const distance = Math.hypot(awayX, awayZ);

    if (distance >= radius) {
        return point;
    }

    const { facing } = segment;

    // Approached from the open side, a one-sided wall is not there at all.
    //
    // Dead on the line counts as the open side: from the solid side the player
    // is stopped a whole radius short and can never reach it, so anybody
    // standing on it walked in from the front and is on their way through.
    if (
        facing !== undefined &&
        (distance < EPSILON || awayX * facing.x + awayZ * facing.z < 0)
    ) {
        return point;
    }

    if (distance < EPSILON) {
        // Dead on the line: leave along its normal rather than dividing by zero.
        const length = Math.sqrt(lengthSquared) || 1;

        return {
            x: closestX + (-dz / length) * radius,
            z: closestZ + (dx / length) * radius,
        };
    }

    return {
        x: closestX + (awayX / distance) * radius,
        z: closestZ + (awayZ / distance) * radius,
    };
}

function pushOutOfBox(point: Point, box: BoxCollider, radius: number): Point {
    const cos = Math.cos(box.angle);
    const sin = Math.sin(box.angle);

    const offsetX = point.x - box.x;
    const offsetZ = point.z - box.z;

    // Into the box's own frame, where it is axis aligned.
    let localX = offsetX * cos + offsetZ * sin;
    let localZ = -offsetX * sin + offsetZ * cos;

    const insideX = Math.abs(localX) <= box.halfWidth;
    const insideZ = Math.abs(localZ) <= box.halfDepth;

    if (insideX && insideZ) {
        const escapeX = box.halfWidth - Math.abs(localX) + radius;
        const escapeZ = box.halfDepth - Math.abs(localZ) + radius;

        if (escapeX < escapeZ) {
            localX = signOf(localX) * (box.halfWidth + radius);
        } else {
            localZ = signOf(localZ) * (box.halfDepth + radius);
        }
    } else {
        const closestX = clamp(localX, -box.halfWidth, box.halfWidth);
        const closestZ = clamp(localZ, -box.halfDepth, box.halfDepth);

        const awayX = localX - closestX;
        const awayZ = localZ - closestZ;
        const distance = Math.hypot(awayX, awayZ);

        if (distance >= radius) {
            return point;
        }

        if (distance < EPSILON) {
            return point;
        }

        localX = closestX + (awayX / distance) * radius;
        localZ = closestZ + (awayZ / distance) * radius;
    }

    return {
        x: box.x + localX * cos - localZ * sin,
        z: box.z + localX * sin + localZ * cos,
    };
}

/**
 * Nudge a position out of everything it overlaps. Sliding along a wall falls out
 * of this for free: the component of the move into the wall is what gets undone.
 */
export function resolveCollisions(
    position: Point,
    colliders: Collider[],
    radius: number,
): Point {
    let resolved = position;

    for (let pass = 0; pass < RESOLVE_PASSES; pass++) {
        const before = resolved;

        for (const collider of colliders) {
            resolved =
                collider.kind === 'segment'
                    ? pushOutOfSegment(resolved, collider, radius)
                    : pushOutOfBox(resolved, collider, radius);
        }

        if (resolved.x === before.x && resolved.z === before.z) {
            break;
        }
    }

    return resolved;
}

export function moveWithCollisions(
    from: Point,
    deltaX: number,
    deltaZ: number,
    colliders: Collider[],
    radius: number,
): Point {
    return resolveCollisions(
        { x: from.x + deltaX, z: from.z + deltaZ },
        colliders,
        radius,
    );
}
