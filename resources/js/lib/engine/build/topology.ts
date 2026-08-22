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
     * For a room, itself and every room an open doorway eventually leads to.
     * Anything standing in one of those can turn up in a view of that room, and
     * has to be drawn for it — a mirror through a doorway that never gets
     * redrawn shows a reflection that never moves.
     *
     * ## Why this is reachability and not a count of doorways
     *
     * It used to be **one hop**: a room and the rooms immediately open to it.
     * That is not what being able to see something is. Two rooms at the ends of
     * a straight corridor are in plain sight of each other however many
     * doorways lie between them, and two rooms sharing a doorway round a corner
     * are not in sight of each other at all. A hop count is wrong in both
     * directions and its only virtue was keeping the work down.
     *
     * Paul, from the demo, twice and from both ends: *the mirror at least a
     * room away cant see through the portal behind me*, and *the portal can not
     * see whats in the mirror*. Measured by the session watching him, the
     * nearest mirror to that portal's far room is **two** doorways out. So the
     * portal's set did not reach the mirror and the mirror's set did not reach
     * back — one gate, both symptoms, and *sits frozen* is this file's own name
     * for it.
     *
     * Keeping the work down is now left to the two things that are actually
     * about work: the frustum test in `reflections.ts`, which is what visibility
     * really is, and `PORTAL_RENDER_BUDGET`, which is what a budget really is.
     * This stays as a filter rather than being deleted because reachability
     * still excludes something real — a room in a different connected piece of
     * the plan, or one that can only be got to through a portal, is genuinely
     * not on the other side of this opening.
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

/** Each room, plus everywhere an open doorway eventually leads. */
function readRoomsSeenFrom(level: Level): Map<string, string[]> {
    /** Which rooms each room shares an open boundary with. */
    const openTo = new Map<string, Set<string>>();

    for (const sector of level.sectors) {
        openTo.set(sector.slug, new Set<string>());
    }

    for (const edge of edgesOf(level.sectors)) {
        const { sector, beyond } = edge;

        // Solid from either side is solid, the same reading the wall builder
        // and the nav graph both make. A portal mouth is `beyond === null` and
        // so is not a way through here — a portal pane already carries the room
        // on its far side as its own starting point.
        //
        // Except that an invisible room is see-through whether or not it is
        // walk-through. This is about what can be *seen* from where, and a wall
        // onto a room that draws nothing but its floor stops nobody's eye — so
        // a mirror on one side of an invisible room has to be redrawn for a
        // view from the other, or its reflection sits frozen. The nav graph
        // makes the opposite reading of the same boundary on purpose: you still
        // cannot walk through a wall you can see through.
        const seeThrough = sector.isInvisible || (beyond?.isInvisible ?? false);

        if (
            beyond === null ||
            (!seeThrough &&
                (edge.from.blocks || (edge.beyondFrom?.blocks ?? false)))
        ) {
            continue;
        }

        openTo.get(sector.slug)?.add(beyond.slug);
        openTo.get(beyond.slug)?.add(sector.slug);
    }

    const roomsSeenFrom = new Map<string, string[]>();

    // One flood per room rather than one flood per connected piece shared out
    // among its rooms. The answer is the same either way — reachability is
    // symmetric — and the levels this runs on have tens of rooms, once, at
    // build. Worth revisiting on a level with thousands.
    for (const sector of level.sectors) {
        const seen = new Set<string>([sector.slug]);
        const queue = [sector.slug];

        while (queue.length > 0) {
            for (const next of openTo.get(queue.shift() as string) ?? []) {
                if (!seen.has(next)) {
                    seen.add(next);
                    queue.push(next);
                }
            }
        }

        roomsSeenFrom.set(sector.slug, [...seen]);
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
