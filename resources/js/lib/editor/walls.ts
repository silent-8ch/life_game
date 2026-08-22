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
};

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

    const openDoorway =
        edge !== null &&
        twin !== null &&
        across !== null &&
        !edge.blocks &&
        !across.points[twin.edge].blocks;

    return { twin, across, partner, portalEnds, openDoorway };
}
