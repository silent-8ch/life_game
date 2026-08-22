import { portalLinkOf } from '@/lib/engine/portals';
import { boundaryKey, edgesOf, inwardNormal } from '@/lib/engine/sectors';
import type { Edge } from '@/lib/engine/sectors';
import type { Level } from '@/types';

/**
 * What the floor plan says about itself, worked out once before anything is
 * drawn. All of it is a pure reading of the level: which walls carry on into
 * each other, which rooms can see which, where each edge is, and which portal
 * links have both of their ends.
 */
export type Topology = {
    /**
     * Which wall ends carry straight on into another wall drawn in the same
     * plane, facing the same way — the far half of a long side that carving or a
     * doorway opposite has split in two, whether or not the halves belong to the
     * same room.
     *
     * A wall is drawn a hair past each of its ends, because every wall is nudged
     * into its own room and at a corner the two of them no longer reach each
     * other, leaving a notch you can see straight through. Where a wall carries
     * on rather than turning there is no corner and no notch, and the overhang
     * would put two faces in the same plane fighting over a strip two
     * centimetres wide and the whole height of the wall. That is what flickers
     * along the joins, so those ends are left where they are.
     */
    carriedOn: {
        /** Whether another wall picks up where this one stops. */
        front: (edge: Edge) => boolean;
        /** Whether another wall runs into where this one starts. */
        back: (edge: Edge) => boolean;
    };
    /**
     * For a room, itself and whatever can be seen from it through an open
     * doorway. Anything standing in one of those can turn up in a view of that
     * room, and has to be drawn for it — a mirror through a doorway that never
     * gets redrawn shows a reflection that never moves.
     */
    seenFrom: (slug: string) => string[];
    /**
     * Every edge by the room and corner it starts at, for finding the walls
     * that meet the ends of a portal mouth.
     */
    edgeAt: Map<string, Edge>;
    /**
     * How many distinct walls carry a portal link. A portal only counts once
     * both of its walls are there; half a portal stays an ordinary wall rather
     * than a hole to nowhere. Walls are counted rather than faces, since a wall
     * between two rooms has a face each way and both of them are the same mouth.
     */
    portalEnds: (link: string) => number;
};

/** Which wall ends carry straight on into another wall in the same plane. */
function readCarriedOn(level: Level): Topology['carriedOn'] {
    const round = (value: number): string => value.toFixed(3);

    const facing = (edge: Edge) => {
        const spanX = edge.to.x - edge.from.x;
        const spanZ = edge.to.z - edge.from.z;
        const length = Math.hypot(spanX, spanZ) || 1;
        const normal = inwardNormal(edge.sector, edge.from, edge.to);

        return `${round(spanX / length)},${round(spanZ / length)}|${round(normal.x)},${round(normal.z)}`;
    };

    const at = (point: { x: number; z: number }, edge: Edge): string =>
        `${round(point.x)},${round(point.z)}|${facing(edge)}`;

    const starts = new Set<string>();
    const ends = new Set<string>();

    for (const edge of edgesOf(level.sectors)) {
        starts.add(at(edge.from, edge));
        ends.add(at(edge.to, edge));
    }

    return {
        front: (edge: Edge): boolean => starts.has(at(edge.to, edge)),
        back: (edge: Edge): boolean => ends.has(at(edge.from, edge)),
    };
}

/** Each room, plus whatever an open doorway lets it see. */
function readRoomsSeenFrom(level: Level): Map<string, string[]> {
    const roomsSeenFrom = new Map<string, string[]>();

    for (const sector of level.sectors) {
        roomsSeenFrom.set(sector.slug, [sector.slug]);
    }

    for (const edge of edgesOf(level.sectors)) {
        const { sector, beyond } = edge;

        if (
            beyond === null ||
            edge.from.blocks ||
            (edge.beyondFrom?.blocks ?? false)
        ) {
            continue;
        }

        for (const [from, to] of [
            [sector.slug, beyond.slug],
            [beyond.slug, sector.slug],
        ]) {
            const seen = roomsSeenFrom.get(from);

            if (seen !== undefined && !seen.includes(to)) {
                seen.push(to);
            }
        }
    }

    return roomsSeenFrom;
}

export function readTopology(level: Level): Topology {
    const roomsSeenFrom = readRoomsSeenFrom(level);

    const edgeAt = new Map<string, Edge>();
    const portalWalls = new Map<string, Set<string>>();

    for (const edge of edgesOf(level.sectors)) {
        edgeAt.set(`${edge.sector.slug}#${edge.index}`, edge);

        const link = portalLinkOf(edge);

        if (link === null) {
            continue;
        }

        const walls = portalWalls.get(link) ?? new Set<string>();

        walls.add(boundaryKey(edge.from, edge.to));
        portalWalls.set(link, walls);
    }

    return {
        carriedOn: readCarriedOn(level),
        seenFrom: (slug: string): string[] => roomsSeenFrom.get(slug) ?? [slug],
        edgeAt,
        portalEnds: (link: string): number => portalWalls.get(link)?.size ?? 0,
    };
}
