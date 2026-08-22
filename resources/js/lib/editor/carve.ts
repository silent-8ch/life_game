import clipping from 'polygon-clipping';
import type { Level, Sector, SectorPoint } from '@/types';
import { closestOnEdge, freeSlug, samePoint, windingOf } from './map';
import type { Point } from './map';

/**
 * Rooms are not allowed to overlap: drawing one on top of another cuts the new
 * shape out of the old one, so the two end up sharing a boundary instead of
 * sitting inside each other.
 *
 * A sector is one closed loop with no holes, so a room drawn wholly inside
 * another leaves something the model cannot hold. That remainder is sliced into
 * horizontal slabs — for the ordinary case of a box inside a box, four pieces
 * around it — and each slab becomes a room of its own.
 */

/** Corners closer together than this are the same corner. */
const EPSILON = 1e-6;

/** Rings smaller than this are slivers thrown up by the arithmetic. */
const MIN_AREA = 1e-4;

type Ring = [number, number][];

/** A room's corners as the clipping library wants them, closed. */
function toRing(points: Point[]): Ring {
    const ring: Ring = points.map((point) => [point.x, point.z]);
    ring.push([points[0].x, points[0].z]);

    return ring;
}

/** Back to corners: the closing point dropped, wound the way the engine reads. */
function toPoints(ring: Ring): Point[] {
    const points = ring
        .slice(0, -1)
        .map(([x, z]) => ({ x, z }))
        .filter(
            (point, index, all) =>
                !samePoint(point, all[(index + 1) % all.length]),
        );

    return windingOf(points) > 0 ? points : points.reverse();
}

function areaOf(ring: Ring): number {
    return (
        Math.abs(windingOf(ring.slice(0, -1).map(([x, z]) => ({ x, z })))) / 2
    );
}

/** Whether a corner sits on a wall, ends included. */
function onEdge(from: Point, to: Point, at: Point): boolean {
    const near = closestOnEdge(from, to, at);

    return Math.hypot(at.x - near.x, at.z - near.z) < 1e-4;
}

/**
 * The wall a stretch of the cut result came from, so a surviving piece of an
 * old wall keeps its texture and its solid and mirror flags. A stretch that
 * matches nothing was made by the cut, and starts out as a plain open boundary.
 */
function inherit(source: Sector, from: Point, to: Point): SectorPoint {
    const found = source.points.find((point, index) => {
        const next = source.points[(index + 1) % source.points.length];

        return (
            !samePoint(point, next) &&
            onEdge(point, next, from) &&
            onEdge(point, next, to)
        );
    });

    return {
        x: from.x,
        z: from.z,
        wallTexture: found?.wallTexture ?? null,
        blocks: found?.blocks ?? false,
        isMirror: found?.isMirror ?? false,
        isSky: found?.isSky ?? false,
        // A portal is a pair, and a cut wall is not the wall its partner was
        // linked to any more, so the link does not survive being carved.
        portalLink: null,
    };
}

/** A ring of bare corners dressed back up as a room's walls. */
function dress(source: Sector, points: Point[]): SectorPoint[] {
    return points.map((point, index) =>
        inherit(source, point, points[(index + 1) % points.length]),
    );
}

/**
 * A polygon with holes, cut into bands between every height its corners sit at.
 * Every band is a simple loop, which is all a sector can be.
 */
function intoSlabs(polygon: Ring[]): Ring[] {
    const [outer, ...holes] = polygon;

    if (holes.length === 0) {
        return [outer];
    }

    const zs = [...new Set(polygon.flat().map(([, z]) => z))].sort(
        (a, b) => a - b,
    );
    const xs = polygon.flat().map(([x]) => x);
    const left = Math.min(...xs) - 1;
    const right = Math.max(...xs) + 1;

    const slabs: Ring[] = [];

    for (let index = 0; index < zs.length - 1; index++) {
        const [top, bottom] = [zs[index], zs[index + 1]];

        if (bottom - top < EPSILON) {
            continue;
        }

        const band: Ring = [
            [left, top],
            [right, top],
            [right, bottom],
            [left, bottom],
            [left, top],
        ];

        for (const piece of clipping.intersection([polygon], [[band]])) {
            // A band is bounded by the heights the holes start and stop at, so
            // no hole can survive inside one; only the outer ring is taken.
            if (areaOf(piece[0]) > MIN_AREA) {
                slabs.push(piece[0]);
            }
        }
    }

    return slabs;
}

/**
 * What is left of a room once another has been cut out of it, as one or more
 * rooms. An empty list means the cut swallowed it whole.
 */
function cut(sector: Sector, blade: Ring): Sector[] {
    const remainder = clipping.difference([[toRing(sector.points)]], [[blade]]);

    const rings = remainder
        .flatMap((polygon) => intoSlabs(polygon))
        .filter((ring) => areaOf(ring) > MIN_AREA);

    return rings.map((ring, index) => ({
        ...sector,
        points: dress(sector, toPoints(ring)),
        // The first piece stays the room it was; the rest are named later.
        slug: index === 0 ? sector.slug : '',
        name: index === 0 ? sector.name : `${sector.name} ${index + 1}`,
    }));
}

/**
 * Adds a corner wherever one room's corner lands partway along another room's
 * wall. Without it a cut leaves a T-junction: the two rooms touch, but they do
 * not name the same pair of corners, so the engine never sees a doorway there.
 */
export function weldCorners(level: Level): Level {
    const corners = level.sectors.flatMap((sector) => sector.points);

    return {
        ...level,
        sectors: level.sectors.map((sector) => {
            const points: SectorPoint[] = [];

            sector.points.forEach((point, index) => {
                const next = sector.points[(index + 1) % sector.points.length];

                points.push(point);

                if (samePoint(point, next)) {
                    return;
                }

                const between = corners
                    .filter(
                        (corner) =>
                            !samePoint(corner, point) &&
                            !samePoint(corner, next) &&
                            onEdge(point, next, corner),
                    )
                    .filter(
                        (corner, at, all) =>
                            all.findIndex((other) =>
                                samePoint(other, corner),
                            ) === at,
                    )
                    .sort(
                        (a, b) =>
                            Math.hypot(a.x - point.x, a.z - point.z) -
                            Math.hypot(b.x - point.x, b.z - point.z),
                    );

                if (between.length > 0) {
                    // A halved wall is no longer the wall its partner links to.
                    points[points.length - 1] = {
                        ...point,
                        portalLink: null,
                    };
                }

                for (const corner of between) {
                    points.push({
                        ...point,
                        x: corner.x,
                        z: corner.z,
                        portalLink: null,
                    });
                }
            });

            return { ...sector, points };
        }),
    };
}

/**
 * Cuts the room at `index` out of every other room it overlaps, and welds the
 * result so the new boundaries are shared. The cut room is left alone and ends
 * up last, so the caller can select it.
 */
export function carveRooms(level: Level, index: number): Level {
    const blade = level.sectors[index];

    if (blade === undefined || blade.points.length < 3) {
        return level;
    }

    const bladeRing = toRing(blade.points);
    const kept: Sector[] = [];
    const extras: Sector[] = [];

    level.sectors.forEach((sector, at) => {
        if (at === index) {
            return;
        }

        const overlap = clipping.intersection(
            [[toRing(sector.points)]],
            [[bladeRing]],
        );

        const overlaps = overlap.some(
            (polygon) => areaOf(polygon[0]) > MIN_AREA,
        );

        if (!overlaps) {
            kept.push(sector);

            return;
        }

        const pieces = cut(sector, bladeRing);

        // Nothing left of it: the new room covers the old one completely.
        if (pieces.length === 0) {
            return;
        }

        kept.push(pieces[0]);
        extras.push(...pieces.slice(1));
    });

    // Named last, against every slug already spoken for, so none can collide.
    const settled = [...kept, ...extras, blade];
    const named = [...kept, ...extras].map((sector) => {
        if (sector.slug !== '') {
            return sector;
        }

        const slug = freeSlug({ ...level, sectors: settled }, 'room');

        // Held so the next unnamed piece cannot be handed the same slug.
        settled.push({ ...sector, slug });

        return { ...sector, slug };
    });

    return weldCorners({ ...level, sectors: [...named, blade] });
}
