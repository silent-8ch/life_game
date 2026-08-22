import type { Sector, SectorPoint } from '@/types';

/**
 * Floor plan arithmetic. A sector is a closed polygon of corners; the edge that
 * starts at corner i runs to corner i + 1, and the last one wraps round. Two
 * sectors naming the same pair of corners share that edge and the player can
 * cross it, which is what makes a doorway.
 */

export type Edge = {
    sector: Sector;
    /** Index of the corner the edge starts at. */
    index: number;
    from: SectorPoint;
    to: SectorPoint;
    /** The sector on the other side, if this edge is shared. */
    beyond: Sector | null;
    /** The corner carrying the matching wall over there, if this edge is shared. */
    beyondFrom: SectorPoint | null;
};

/** Twice the signed area: positive when the corners run anticlockwise in x/z. */
function signedArea(sector: Sector): number {
    let total = 0;

    sector.points.forEach((point, index) => {
        const next = sector.points[(index + 1) % sector.points.length];

        total += point.x * next.z - next.x * point.z;
    });

    return total;
}

/** The unit normal of an edge, pointing into its own sector. */
export function inwardNormal(
    sector: Sector,
    from: SectorPoint,
    to: SectorPoint,
): { x: number; z: number } {
    const spanX = to.x - from.x;
    const spanZ = to.z - from.z;
    const length = Math.hypot(spanX, spanZ) || 1;

    const turn = signedArea(sector) > 0 ? 1 : -1;

    return {
        x: (-spanZ / length) * turn,
        z: (spanX / length) * turn,
    };
}

/** Corners are matched on their coordinates, since that is what the editor moves. */
function cornerKey(point: SectorPoint): string {
    return `${point.x.toFixed(3)},${point.z.toFixed(3)}`;
}

/**
 * The name of the boundary a wall lies on, the same from either side. Two
 * sectors naming the same pair of corners are looking at one wall between them,
 * and anything that belongs to the wall rather than to a room — whether it is
 * solid, whether it is a portal mouth — is keyed by this.
 */
export function boundaryKey(from: SectorPoint, to: SectorPoint): string {
    return [cornerKey(from), cornerKey(to)].sort().join('|');
}

/**
 * Every edge in the level, each already knowing whether another sector lies on
 * the far side of it.
 */
export function edgesOf(sectors: Sector[]): Edge[] {
    const owners = new Map<string, { sector: Sector; from: SectorPoint }[]>();

    for (const sector of sectors) {
        sector.points.forEach((point, index) => {
            const next = sector.points[(index + 1) % sector.points.length];
            const key = boundaryKey(point, next);

            owners.set(key, [
                ...(owners.get(key) ?? []),
                { sector, from: point },
            ]);
        });
    }

    return sectors.flatMap((sector) =>
        sector.points.map((point, index): Edge => {
            const next = sector.points[(index + 1) % sector.points.length];
            const sharing = owners.get(boundaryKey(point, next)) ?? [];
            const other = sharing.find((held) => held.sector !== sector);

            return {
                sector,
                index,
                from: point,
                to: next,
                beyond: other?.sector ?? null,
                beyondFrom: other?.from ?? null,
            };
        }),
    );
}

export function contains(sector: Sector, x: number, z: number): boolean {
    let inside = false;

    sector.points.forEach((point, index) => {
        const next = sector.points[(index + 1) % sector.points.length];
        const straddles = point.z > z !== next.z > z;

        if (!straddles) {
            return;
        }

        const crossingX =
            point.x + ((z - point.z) / (next.z - point.z)) * (next.x - point.x);

        if (x < crossingX) {
            inside = !inside;
        }
    });

    return inside;
}

/**
 * The sector a spot on the floor plan belongs to. Overlapping sectors are
 * resolved by taking the one drawn last, the way a level editor stacks them.
 */
export function sectorAt(
    sectors: Sector[],
    x: number,
    z: number,
): Sector | null {
    for (let index = sectors.length - 1; index >= 0; index--) {
        if (contains(sectors[index], x, z)) {
            return sectors[index];
        }
    }

    return null;
}

export function boundsOf(sectors: Sector[]): {
    minX: number;
    maxX: number;
    minZ: number;
    maxZ: number;
} {
    const points = sectors.flatMap((sector) => sector.points);

    if (points.length === 0) {
        return { minX: 0, maxX: 1, minZ: 0, maxZ: 1 };
    }

    return {
        minX: Math.min(...points.map((point) => point.x)),
        maxX: Math.max(...points.map((point) => point.x)),
        minZ: Math.min(...points.map((point) => point.z)),
        maxZ: Math.max(...points.map((point) => point.z)),
    };
}

/**
 * A sloped surface's height at a spot: the base along the hinge wall, plus the
 * rise for however far into the room the spot is.
 *
 * `floorHeight` therefore means "how high this floor is **along its hinge
 * wall**", not "how high this floor is". That is Build's convention and it is
 * what makes shared walls line up for nothing: hinge two rooms on the wall
 * between them at the same base height and they meet flush there, each rising
 * into its own room, because `inwardNormal` points opposite ways for the two
 * sides.
 *
 * Mirrored in PHP on `App\Models\LevelSector`, which validates what this draws.
 * Two copies is the established cost here — the same note as `LevelAssets`.
 */
function heightAt(
    sector: Sector,
    base: number,
    slope: number,
    hinge: number | null,
    x: number,
    z: number,
): number {
    const corners = sector.points;

    // A hinge past the end of the point list is an old row whose wall was
    // carved away. Flat is the honest answer, not a crash.
    if (!slope || hinge === null || corners.length < 3) {
        return base;
    }

    if (hinge >= corners.length) {
        return base;
    }

    const from = corners[hinge];
    const to = corners[(hinge + 1) % corners.length];

    if (Math.hypot(to.x - from.x, to.z - from.z) < 1e-9) {
        return base;
    }

    const normal = inwardNormal(sector, from, to);
    const into = (x - from.x) * normal.x + (z - from.z) * normal.z;

    return base + slope * into;
}

/** How high the floor is at a spot in the room. */
export function floorAt(sector: Sector, x: number, z: number): number {
    // The two slope fields are read defensively rather than by type alone. The
    // columns default to flat, so a row written before they existed carries no
    // value for them, and neither does a hand-written fixture. Undefined means
    // flat here, the same as zero.
    return heightAt(
        sector,
        sector.floorHeight,
        sector.floorSlope ?? 0,
        sector.floorSlopeEdge ?? null,
        x,
        z,
    );
}

/** How high the ceiling is at a spot in the room. */
export function ceilingAt(sector: Sector, x: number, z: number): number {
    return heightAt(
        sector,
        sector.ceilingHeight,
        sector.ceilingSlope ?? 0,
        sector.ceilingSlopeEdge ?? null,
        x,
        z,
    );
}

/**
 * The four numbers a wall between two rooms needs: each surface at each end of
 * the wall.
 *
 * Both surfaces are planes, so their heights along a straight edge are linear
 * and the extremes are always at the two ends. Nothing in the middle has to be
 * sampled — which is what makes every check below exact rather than a guess.
 */
export function heightsAlong(
    sector: Sector,
    from: SectorPoint,
    to: SectorPoint,
): {
    floorFrom: number;
    floorTo: number;
    ceilingFrom: number;
    ceilingTo: number;
} {
    return {
        floorFrom: floorAt(sector, from.x, from.z),
        floorTo: floorAt(sector, to.x, to.z),
        ceilingFrom: ceilingAt(sector, from.x, from.z),
        ceilingTo: ceilingAt(sector, to.x, to.z),
    };
}
