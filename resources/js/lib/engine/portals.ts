import type { Sector, SectorPoint } from '@/types';
import type { Edge } from './sectors';
import { boundaryKey, edgesOf, floorAt, inwardNormal } from './sectors';

/**
 * A portal is two walls that name the same link. Walking into one puts the
 * player out of the other, carrying their offset along the wall, their heading
 * and their speed through the turn between the two — so a pair laid out to
 * match cannot be told from a doorway, which is the trick Doom levels use to
 * stack one room over another.
 *
 * The crossing is a rigid transform rather than a jump to a fixed spot, because
 * that is what a drawn-through portal needs as well: the same transform moves
 * the camera that renders the far side.
 *
 * Only the face the link was set on is a way in. The room behind that wall keeps
 * its wall and sees nothing — but it must not seal the mouth with a collider,
 * which is what `SegmentCollider.facing` is for. A portal drawn on a wall with a
 * real room behind it therefore leaves that room exactly as it was.
 */

export type Point = { x: number; z: number };

export type PortalMouth = {
    link: string;
    /** The wall this is a face of. Both faces of one wall share it. */
    boundary: string;
    sector: Sector;
    from: SectorPoint;
    to: SectorPoint;
    /** Middle of the wall, which the transform turns about. */
    centre: Point;
    /** Points into the mouth's own room. */
    normal: Point;
};

export type Portal = {
    /** The mouth walked into. */
    entry: PortalMouth;
    /** The mouth walked out of. */
    exit: PortalMouth;
    /** How far the player is turned on the way through, in radians of yaw. */
    turn: number;
    /**
     * How far the player is lifted on the way through, in metres.
     *
     * The difference between the two mouths' floors, so **you step out level
     * with the floor you stepped in from**. Paul, having walked a slope up to a
     * portal: *I expect the floor at the other end to be level with my end of
     * the portal, currently it is showing it below me perspectively.*
     *
     * It used to be nothing at all — the transform was a translation in x and z
     * and a turn about y, with a hard zero where this is. That preserved
     * absolute height, so a mouth at the top of his slope looked into a room
     * three metres beneath him and walking in would have dropped him into it.
     *
     * Measured at the middle of each mouth rather than at the point crossed.
     * A mouth is a straight edge and a floor is a plane, so where the two rooms
     * are sloped differently the floors can only be made level along one line;
     * the middle is the one that is stable, is the same number for the walk and
     * for the pane's camera, and does not change depending on which end of the
     * opening somebody walks through.
     *
     * Zero for every portal in every level in this repo, which is what made it
     * safe to add: all of their mouths already sit at matching heights.
     */
    rise: number;
};

/** Nudge past the far wall, so arriving does not read as crossing back. */
const CLEARANCE = 0.02;

/**
 * A direction as a yaw: the engine's forward is (-sin yaw, -cos yaw), so yaw 0
 * faces north.
 */
export function yawOf(x: number, z: number): number {
    return Math.atan2(-x, -z);
}

/**
 * How far a portal turns whoever goes through it. Walking into a mouth travels
 * against its own room's normal and arriving travels along the far room's, so
 * the turn is the angle between those two.
 *
 * The player's crossing and the camera that draws the far side both come from
 * here, because a pane showing one thing while the walk arrives at another is
 * the one way a portal can look wrong.
 */
export function turnBetween(entryNormal: Point, exitNormal: Point): number {
    return (
        yawOf(exitNormal.x, exitNormal.z) -
        yawOf(-entryNormal.x, -entryNormal.z)
    );
}

/** Turns a vector in the floor plane by an amount of yaw. */
function turnBy(point: Point, turn: number): Point {
    const sin = Math.sin(turn);
    const cos = Math.cos(turn);

    return {
        x: point.x * cos + point.z * sin,
        z: point.z * cos - point.x * sin,
    };
}

/**
 * The portal a wall belongs to, whichever of its two faces the link was set on,
 * or null if it is not a mouth. Named on one face only, the room behind it would
 * go on drawing a solid wall across the opening and seal the portal from both
 * sides — the same trap that passability had before it moved to the boundary.
 */
export function portalLinkOf(edge: Edge): string | null {
    const named = edge.from.portalLink;
    const behind = edge.beyondFrom?.portalLink ?? null;
    const link = named !== null && named !== '' ? named : behind;

    return link === null || link === '' ? null : link;
}

/**
 * Whether this face of the wall is the one the link was set on, and so the way
 * in. The other face is an ordinary wall to the room it belongs to.
 */
export function namesPortal(edge: Edge): boolean {
    return edge.from.portalLink !== null && edge.from.portalLink !== '';
}

function mouthsOf(sectors: Sector[]): PortalMouth[] {
    const mouths: PortalMouth[] = [];

    for (const edge of edgesOf(sectors)) {
        const link = namesPortal(edge) ? portalLinkOf(edge) : null;

        if (link === null) {
            continue;
        }

        mouths.push({
            link,
            boundary: boundaryKey(edge.from, edge.to),
            sector: edge.sector,
            from: edge.from,
            to: edge.to,
            centre: {
                x: (edge.from.x + edge.to.x) / 2,
                z: (edge.from.z + edge.to.z) / 2,
            },
            normal: inwardNormal(edge.sector, edge.from, edge.to),
        });
    }

    return mouths;
}

/**
 * Every portal in the level, both ways round, so whichever mouth is walked into
 * has an exit waiting. A link named once or more than twice is ignored: half a
 * portal would put the player where there is nothing to arrive in.
 */
export function createPortals(sectors: Sector[]): Portal[] {
    const byLink = new Map<string, PortalMouth[]>();

    for (const mouth of mouthsOf(sectors)) {
        byLink.set(mouth.link, [...(byLink.get(mouth.link) ?? []), mouth]);
    }

    const portals: Portal[] = [];

    for (const mouths of byLink.values()) {
        if (mouths.length !== 2) {
            continue;
        }

        const [first, second] = mouths;

        for (const [entry, exit] of [
            [first, second],
            [second, first],
        ]) {
            // Walking into a mouth travels against its own room's normal, and
            // arriving travels along the far room's, so the turn is between the
            // two. Everything else follows from it.
            portals.push({
                entry,
                exit,
                turn: turnBetween(entry.normal, exit.normal),
                rise: floorMiddleOf(exit) - floorMiddleOf(entry),
            });
        }
    }

    return portals;
}

/**
 * How high the floor is halfway along a mouth.
 *
 * The middle rather than either end, because under a slope a mouth's floor is a
 * range and not a number, and the middle is the one point that is the same
 * whichever way somebody is walking.
 */
function floorMiddleOf(mouth: PortalMouth): number {
    return floorAt(mouth.sector, mouth.centre.x, mouth.centre.z);
}

/** Where two segments cross, as a fraction along the first, or null. */
function crossingAlong(
    fromX: number,
    fromZ: number,
    toX: number,
    toZ: number,
    wall: PortalMouth,
): number | null {
    const pathX = toX - fromX;
    const pathZ = toZ - fromZ;
    const wallX = wall.to.x - wall.from.x;
    const wallZ = wall.to.z - wall.from.z;

    const denominator = pathX * wallZ - pathZ * wallX;

    if (Math.abs(denominator) < 1e-9) {
        return null;
    }

    const offsetX = wall.from.x - fromX;
    const offsetZ = wall.from.z - fromZ;

    const alongPath = (offsetX * wallZ - offsetZ * wallX) / denominator;
    const alongWall = (offsetX * pathZ - offsetZ * pathX) / denominator;

    if (alongPath < 0 || alongPath > 1 || alongWall < 0 || alongWall > 1) {
        return null;
    }

    return alongPath;
}

export type Crossing = {
    x: number;
    z: number;
    yaw: number;
    /** How far up to carry whoever is crossing, so the two floors meet. */
    rise: number;
    portal: Portal;
};

/**
 * Whether a step across the floor walks into a portal, and where it comes out.
 * The nearest mouth wins, so a step long enough to reach two of them takes the
 * one it would meet first.
 */
export function crossPortal(
    portals: Portal[],
    fromX: number,
    fromZ: number,
    toX: number,
    toZ: number,
    yaw: number,
): Crossing | null {
    let nearest: { along: number; portal: Portal } | null = null;

    for (const portal of portals) {
        const { normal } = portal.entry;

        // Only on the way out of the room the mouth belongs to.
        if ((toX - fromX) * normal.x + (toZ - fromZ) * normal.z >= 0) {
            continue;
        }

        const along = crossingAlong(fromX, fromZ, toX, toZ, portal.entry);

        if (along !== null && (nearest === null || along < nearest.along)) {
            nearest = { along, portal };
        }
    }

    if (nearest === null) {
        return null;
    }

    const { entry, exit, turn } = nearest.portal;

    const offset = turnBy(
        { x: toX - entry.centre.x, z: toZ - entry.centre.z },
        turn,
    );

    return {
        x: exit.centre.x + offset.x + exit.normal.x * CLEARANCE,
        z: exit.centre.z + offset.z + exit.normal.z * CLEARANCE,
        yaw: yaw + turn,
        rise: nearest.portal.rise,
        portal: nearest.portal,
    };
}
