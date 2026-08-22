import { twinEdge } from '@/lib/editor/map';
import type { Selection } from '@/lib/editor/map';
import type { Level, Sector } from '@/types';

/**
 * What is true about the wall the editor has picked.
 *
 * All of this used to be worked out inside the Inspector, above the early
 * returns, which made it look like state the whole panel shared. It is not —
 * every one of these is read only by the wall panel. Out here it is a function
 * of the level and the selection and nothing else, which means it can be tested
 * without a browser, and the Inspector can be cut up later without carrying it
 * around.
 */

export type WallFacts = {
    /** The same wall as the room on the other side of it names it, if shared. */
    twin: { sector: number; edge: number } | null;
    /** The room on the other side. */
    across: Sector | null;
    /** The other end of the portal: the wall elsewhere naming the same link. */
    partner: Sector | null;
    /**
     * How many walls in the level name this one's link.
     *
     * A portal is a pair, so anything other than two is broken — one end is a
     * way to nowhere, three or more is ambiguous — and the panel says so.
     */
    portalEnds: number;
    /** Whether the player can walk through it, which is a property of both sides. */
    openDoorway: boolean;
    /**
     * How long this mouth is and how long the one it is paired with, in metres.
     * Null unless the link is a complete pair, since one length is not a
     * comparison.
     *
     * Here so that the panel can *check* what it has always asserted. It told
     * every correctly paired portal to give both walls the same length whether
     * or not they already had it, which Paul saw on mouths that were equal in
     * two different levels. A warning that is always on is not a warning; it is
     * a decoration that hides the one time it means something.
     */
    mouth: { here: number; there: number } | null;
};

/** How long one of a room's walls is, in metres. */
function edgeLength(sector: Sector, edge: number): number {
    const from = sector.points[edge];
    const to = sector.points[(edge + 1) % sector.points.length];

    return Math.hypot(to.x - from.x, to.z - from.z);
}

export function wallFacts(level: Level, selection: Selection): WallFacts {
    const sector =
        selection === null ? null : (level.sectors[selection.sector] ?? null);

    const edge =
        sector === null || selection?.edge === null || selection === null
            ? null
            : (sector.points[selection.edge] ?? null);

    // Gated on the wall actually being there, not merely on one having been
    // picked. A carve can delete the wall under the selection before the panel
    // next renders, and twinEdge reads the corner after it without checking —
    // so this threw, and took the editor down with it. It was reachable from
    // the Inspector before this moved out here; extracting it is what found it.
    const twin =
        sector === null ||
        selection === null ||
        selection.edge === null ||
        edge === null
            ? null
            : twinEdge(level, selection.sector, selection.edge);

    const across = twin === null ? null : (level.sectors[twin.sector] ?? null);

    const partner =
        edge === null || edge.portalLink === null || edge.portalLink === ''
            ? null
            : (level.sectors.find(
                  (other, index) =>
                      other.points.some(
                          (point, at) =>
                              point.portalLink === edge.portalLink &&
                              !(
                                  index === selection?.sector &&
                                  at === selection?.edge
                              ),
                      ) &&
                      other.points.filter(
                          (point) => point.portalLink === edge.portalLink,
                      ).length > 0,
              ) ?? null);

    const portalEnds =
        edge === null || edge.portalLink === null
            ? 0
            : level.sectors.reduce(
                  (total, other) =>
                      total +
                      other.points.filter(
                          (point) => point.portalLink === edge.portalLink,
                      ).length,
                  0,
              );

    // The far end as a wall rather than as a room, which is what a length needs.
    // A room can hold both ends of a wrap-around portal, so this cannot be
    // found by looking for the link in some *other* room: it is the first wall
    // naming this link that is not this wall.
    const partnerEnd =
        edge === null || edge.portalLink === null || edge.portalLink === ''
            ? null
            : (level.sectors.flatMap((other, index) =>
                  other.points.flatMap((point, at) =>
                      point.portalLink === edge.portalLink &&
                      !(index === selection?.sector && at === selection?.edge)
                          ? [{ sector: index, edge: at }]
                          : [],
                  ),
              )[0] ?? null);

    const mouth =
        sector === null ||
        selection === null ||
        selection.edge === null ||
        partnerEnd === null ||
        portalEnds !== 2
            ? null
            : {
                  here: edgeLength(sector, selection.edge),
                  there: edgeLength(
                      level.sectors[partnerEnd.sector],
                      partnerEnd.edge,
                  ),
              };

    const openDoorway =
        edge !== null &&
        twin !== null &&
        across !== null &&
        !edge.blocks &&
        !across.points[twin.edge].blocks;

    return { twin, across, partner, portalEnds, openDoorway, mouth };
}

/**
 * A short name for each of a room's walls, for picking one out of a list.
 *
 * "Wall 3" tells nobody anything, and a slope is authored by choosing the wall
 * a floor is hinged on — so the list has to say which wall is which without
 * making the author count corners round the plan. The compass point is worked
 * out from the wall's outward normal, which is the side of the room it is on.
 *
 * North is -z, the way the camera looks at a yaw of zero.
 */
export function wallLabels(sector: Sector): string[] {
    const points = sector.points;
    const count = points.length;

    // Which way round the corners run, so "into the room" means into the room.
    let twiceArea = 0;

    for (let index = 0; index < count; index++) {
        const point = points[index];
        const next = points[(index + 1) % count];

        twiceArea += point.x * next.z - next.x * point.z;
    }

    const turn = twiceArea > 0 ? 1 : -1;

    return points.map((point, index) => {
        const next = points[(index + 1) % count];
        const spanX = next.x - point.x;
        const spanZ = next.z - point.z;
        const length = Math.hypot(spanX, spanZ) || 1;

        // Outward: the inward normal turned around.
        const outX = -((-spanZ / length) * turn);
        const outZ = -((spanX / length) * turn);

        const compass =
            Math.abs(outZ) > Math.abs(outX)
                ? outZ < 0
                    ? 'north'
                    : 'south'
                : outX > 0
                  ? 'east'
                  : 'west';

        return `${index + 1} — ${compass}`;
    });
}
