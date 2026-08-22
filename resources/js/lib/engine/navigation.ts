import { createPortals } from '@/lib/engine/portals';
import {
    contains,
    edgesOf,
    heightsAlong,
    inwardNormal,
    sectorAt,
} from '@/lib/engine/sectors';
import type { Level, Sector } from '@/types';

/**
 * Which rooms lead to which, and where the way through is.
 *
 * In a sector engine the room plan **is** the nav mesh, and it has been sitting
 * there since before any of this: `build/boundaries.ts` already decides, for
 * every boundary in the level, whether the climb and the headroom let somebody
 * through — and then uses the answer only to place a collider. The same
 * question asked once more gives a graph.
 *
 * That matters because the people could not find doorways at all. Wandering
 * picked a point anywhere in the level's bounding box, checked only that *a*
 * room was under it, and then walked at it in a straight line with collision to
 * slide along whatever it met. A doorway is a metre-wide gap; a straight line
 * from one room to a point in another almost never goes through one. So a
 * person scraped along the nearest wall until the still-timer gave up and
 * picked somewhere else, quite possibly somewhere they could never reach. They
 * were not refusing to use doors. They could not see them.
 *
 * ## What a link costs is not decided here
 *
 * A doorway carries its own numbers — the worst climb across it and the least
 * headroom — and whether those are passable is the caller's question, not the
 * graph's. `MAX_STEP` is about to stop being a build-time constant and become a
 * runtime decision, and a graph that had baked it in would be wrong the day
 * that lands. Same lesson as `tooSteepFor` taking the limit rather than
 * knowing one.
 */

export type Point = { x: number; z: number };

/** A way out of a room, and what it costs to use it. */
export type Doorway = {
    /** The room on the other side. */
    to: string;
    /** Where to walk to get through: the middle of the opening. */
    at: Point;
    /** The worst step up or down across the opening, in metres. */
    climb: number;
    /** The least gap between floor and ceiling across it. */
    headroom: number;
    /**
     * A portal rather than a doorway. Walking into it carries you bodily to
     * somewhere else on the plan, so the two rooms are neighbours however far
     * apart they look.
     */
    portal: boolean;
};

/** Whether whoever is walking can use a way through. */
export type CanPass = (doorway: Doorway) => boolean;

export type NavGraph = {
    /** Every room, by slug. */
    rooms: string[];
    /** The ways out of a room. */
    waysOut: (room: string) => Doorway[];
    /** Every room somebody standing in this one could get to, this one first. */
    reachableFrom: (room: string, canPass: CanPass) => string[];
    /**
     * The doorways to walk through, in order, to get from one room to another.
     *
     * Empty for a room you are already in, and null when there is no way.
     */
    routeBetween: (
        from: string,
        to: string,
        canPass: CanPass,
    ) => Doorway[] | null;
};

/** The middle of a wall, which is the part of an opening to aim at. */
function middleOf(from: Point, to: Point): Point {
    return { x: (from.x + to.x) / 2, z: (from.z + to.z) / 2 };
}

/**
 * How far past an opening to aim, in metres.
 *
 * Aiming *at* the middle of a doorway means aiming at a point on the wall's own
 * plane, and somebody who arrives there is standing in the gap rather than
 * through it — so they press against the jamb and slide. Measured over two
 * minutes of five people in the house, aiming at the opening left them stuck
 * 15% of the time against 11% before; aiming a stride beyond it, into the room
 * they are going to, brings that back down. A person walking through a door
 * looks at the room, not at the frame.
 */
const BEYOND = 0.6;

export function buildNavGraph(level: Level): NavGraph {
    const ways = new Map<string, Doorway[]>();

    for (const sector of level.sectors) {
        ways.set(sector.slug, []);
    }

    const link = (from: string, doorway: Doorway): void => {
        ways.get(from)?.push(doorway);
    };

    for (const edge of edgesOf(level.sectors)) {
        const { sector, beyond } = edge;

        // Passability belongs to the boundary rather than to one room, exactly
        // as the wall builder reads it: if either side calls it solid it is a
        // wall, and there is no way through for anybody.
        if (
            beyond === null ||
            edge.from.blocks ||
            (edge.beyondFrom?.blocks ?? false)
        ) {
            continue;
        }

        const here = heightsAlong(sector, edge.from, edge.to);
        const over = heightsAlong(beyond, edge.from, edge.to);

        // Both surfaces are planes, so along a straight opening the extremes
        // are at its two ends. The same reasoning the step gate uses.
        // Aimed a stride into the room being entered rather than at the gap
        // itself, so arriving means through rather than in the doorway.
        //
        // Measured from *this* room's normal and reversed, not from the far
        // room's. `edgesOf` hands the corners back in this sector's winding
        // order, and the neighbour walks the same wall the other way round —
        // so asking the far room for a normal along these corners answers for
        // the wrong side of the wall, and where the two rooms happen to be
        // wound alike it answers with the near side exactly, aiming the
        // waypoint a stride back into the room being left.
        const into = inwardNormal(sector, edge.from, edge.to);
        const gap = middleOf(edge.from, edge.to);

        link(sector.slug, {
            to: beyond.slug,
            at: {
                x: gap.x - into.x * BEYOND,
                z: gap.z - into.z * BEYOND,
            },
            climb: Math.max(
                Math.abs(over.floorFrom - here.floorFrom),
                Math.abs(over.floorTo - here.floorTo),
            ),
            headroom: Math.min(
                Math.min(here.ceilingFrom, over.ceilingFrom) -
                    Math.max(here.floorFrom, over.floorFrom),
                Math.min(here.ceilingTo, over.ceilingTo) -
                    Math.max(here.floorTo, over.floorTo),
            ),
            portal: false,
        });
    }

    // Portals are links too. Two rooms joined by one are neighbours however far
    // apart they sit on the plan, and a wanderer may take the stairs.
    for (const portal of createPortals(level.sectors)) {
        // A mouth is walked *into*, and crossing happens on the way through, so
        // the aim is past its plane the same way a doorway's is — except the
        // room being entered is somewhere else entirely, so it is the entry
        // room's own normal, reversed.
        const mouth = middleOf(portal.entry.from, portal.entry.to);
        const inward = inwardNormal(
            portal.entry.sector,
            portal.entry.from,
            portal.entry.to,
        );

        link(portal.entry.sector.slug, {
            to: portal.exit.sector.slug,
            at: {
                x: mouth.x - inward.x * BEYOND,
                z: mouth.z - inward.z * BEYOND,
            },
            // A portal carries you bodily, so there is no step to climb and no
            // lintel to duck: whatever the two floors are, you arrive standing.
            climb: 0,
            headroom: Infinity,
            portal: true,
        });
    }

    const waysOut = (room: string): Doorway[] => ways.get(room) ?? [];

    const reachableFrom = (room: string, canPass: CanPass): string[] => {
        const seen = new Set<string>([room]);
        const queue = [room];

        while (queue.length > 0) {
            for (const way of waysOut(queue.shift() as string)) {
                if (!seen.has(way.to) && canPass(way)) {
                    seen.add(way.to);
                    queue.push(way.to);
                }
            }
        }

        return [...seen];
    };

    const routeBetween = (
        from: string,
        to: string,
        canPass: CanPass,
    ): Doorway[] | null => {
        if (from === to) {
            return [];
        }

        // Breadth first, so the route is the fewest rooms rather than the
        // shortest walk. A person crossing a house does not work out the
        // shortest path either.
        const cameBy = new Map<string, { room: string; way: Doorway }>();
        const seen = new Set<string>([from]);
        const queue = [from];

        while (queue.length > 0) {
            const room = queue.shift() as string;

            for (const way of waysOut(room)) {
                if (seen.has(way.to) || !canPass(way)) {
                    continue;
                }

                seen.add(way.to);
                cameBy.set(way.to, { room, way });

                if (way.to === to) {
                    const route: Doorway[] = [];

                    for (let at = to; at !== from;) {
                        const step = cameBy.get(at);

                        if (step === undefined) {
                            return null;
                        }

                        route.unshift(step.way);
                        at = step.room;
                    }

                    return route;
                }

                queue.push(way.to);
            }
        }

        return null;
    };

    return {
        rooms: level.sectors.map((sector) => sector.slug),
        waysOut,
        reachableFrom,
        routeBetween,
    };
}

/**
 * A spot inside a particular room, chosen at random.
 *
 * Its own bounding box rather than the level's, and checked against the room
 * itself — a room is a polygon and its box is not, so a corner of the box can
 * easily be in the room next door or in no room at all.
 */
export function somewhereIn(
    sector: Sector,
    sectors: Sector[],
    attempts = 12,
): Point | null {
    const xs = sector.points.map((point) => point.x);
    const zs = sector.points.map((point) => point.z);
    const minX = Math.min(...xs);
    const maxX = Math.max(...xs);
    const minZ = Math.min(...zs);
    const maxZ = Math.max(...zs);

    for (let attempt = 0; attempt < attempts; attempt++) {
        const x = minX + Math.random() * (maxX - minX);
        const z = minZ + Math.random() * (maxZ - minZ);

        // Both tests: inside this room's outline, and this room is the one the
        // engine agrees is under that spot. Rooms can overlap on the plan where
        // one is above another.
        if (contains(sector, x, z) && sectorAt(sectors, x, z) === sector) {
            return { x, z };
        }
    }

    return null;
}
