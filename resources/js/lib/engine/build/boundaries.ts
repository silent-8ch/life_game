import type { BuildContext } from '@/lib/engine/build/context';
import { buildWall } from '@/lib/engine/build/walls';
import { MAX_STEP, MIN_HEADROOM } from '@/lib/engine/constants';
import { namesPortal, portalLinkOf } from '@/lib/engine/portals';
import { edgesOf, heightsAlong, inwardNormal } from '@/lib/engine/sectors';
import type { Edge } from '@/lib/engine/sectors';

/**
 * Walks every boundary in the level and decides what it is: a portal mouth, a
 * wall, or a doorway with only the step and the lintel drawn. Colliders go down
 * alongside, since what stops the player is the same decision as what is drawn.
 *
 * @return The mouths found, which cannot be filled until both ends are known.
 */
export function buildBoundaries(ctx: BuildContext): Edge[] {
    const { level, scene, topology } = ctx;
    const mouths: Edge[] = [];

    for (const edge of edgesOf(level.sectors)) {
        const { sector, beyond } = edge;
        const texture = edge.from.wallTexture ?? sector.wallTexture;

        // A boundary with an invisible room on either side draws nothing, from
        // either side — Paul's ruling, and the half of it that is easy to miss
        // is the *outside*. A normal room looking towards an invisible one sees
        // straight through it to whatever is beyond, so the wall between them
        // cannot be drawn for the normal room either, or the room would be a
        // painted box with an invisible inside.
        //
        // Everything below still runs. Only the drawing is skipped: the
        // colliders, the portal mouths and the walkability gate all read the
        // same as they always did, because collision is unchanged and an
        // invisible room is a room you can walk into and not see.
        const unseen = sector.isInvisible || (beyond?.isInvisible ?? false);

        const drawWall: typeof buildWall = (...given) =>
            unseen ? null : buildWall(...given);

        // Both surfaces are planes, so along a straight wall their heights are
        // linear and the two ends are the extremes. Everything below is decided
        // from these four numbers per room and nothing in between.
        const here = heightsAlong(sector, edge.from, edge.to);
        const over =
            beyond === null ? null : heightsAlong(beyond, edge.from, edge.to);

        const link = portalLinkOf(edge);

        if (link !== null && topology.portalEnds(link) === 2) {
            // The face the link was set on is not a wall and does not stop the
            // player: it is a pane showing the far mouth's room, built once both
            // mouths are known.
            if (namesPortal(edge)) {
                // Except one standing in a room that is not drawn: a pane is a
                // surface, and an invisible room has none but its floor.
                if (!unseen) {
                    mouths.push(edge);
                }

                continue;
            }

            // The room behind the mouth keeps its wall and sees nothing unusual.
            // Its collider only pushes back from its own side, though: a
            // collider is a line on the floor plan with no sides to it, and one
            // laid across a mouth seals the portal for the room in front too.
            const facing = inwardNormal(sector, edge.from, edge.to);

            // Up to whichever is higher, its own ceiling or the top of the
            // mouth. A mouth covers the height of the room that owns it, and
            // that room's floor can sit well above this one's ceiling — a
            // landing at the top of a staircase, over the room below it. The
            // band between the two belongs to neither, and left open the
            // portal's own camera sees straight out through it: sky above and
            // below the far room for the last few centimetres of walking in,
            // where the tilted near plane has been dropped and cannot cut it.
            drawWall(
                ctx,
                edge,
                {
                    bottomFrom: here.floorFrom,
                    bottomTo: here.floorTo,
                    topFrom: Math.max(here.ceilingFrom, over?.ceilingFrom ?? 0),
                    topTo: Math.max(here.ceilingTo, over?.ceilingTo ?? 0),
                },
                texture,
            );

            scene.colliders.push({
                kind: 'segment',
                x1: edge.from.x,
                z1: edge.from.z,
                x2: edge.to.x,
                z2: edge.to.z,
                facing,
            });

            continue;
        }

        // Passability belongs to the boundary, not to one room: if either side
        // calls the wall solid it is a wall, and both rooms get a face.
        const blocks = edge.from.blocks || (edge.beyondFrom?.blocks ?? false);

        if (beyond === null || blocks) {
            drawWall(
                ctx,
                edge,
                {
                    bottomFrom: here.floorFrom,
                    bottomTo: here.floorTo,
                    topFrom: here.ceilingFrom,
                    topTo: here.ceilingTo,
                },
                texture,
            );
            scene.colliders.push({
                kind: 'segment',
                x1: edge.from.x,
                z1: edge.from.z,
                x2: edge.to.x,
                z2: edge.to.z,
            });

            continue;
        }

        const beyondHeights = over as NonNullable<typeof over>;

        // The step up to the next room, and the drop from its ceiling. Each is
        // a trapezoid between two planes, and where the two floors cross partway
        // along the wall each side's own quad collapses into the triangle
        // covering the stretch where its floor is the lower of the two. The two
        // triangles together close the gap.
        drawWall(
            ctx,
            edge,
            {
                bottomFrom: here.floorFrom,
                bottomTo: here.floorTo,
                topFrom: beyondHeights.floorFrom,
                topTo: beyondHeights.floorTo,
            },
            texture,
        );

        if (!(sector.isSky && beyond.isSky)) {
            drawWall(
                ctx,
                edge,
                {
                    bottomFrom: beyondHeights.ceilingFrom,
                    bottomTo: beyondHeights.ceilingTo,
                    topFrom: here.ceilingFrom,
                    topTo: here.ceilingTo,
                },
                texture,
            );
        }

        // Both are differences of linear functions along the wall, so their
        // extremes are at the ends: the worst climb and the worst headroom
        // decide, and the gate itself is unchanged.
        const climb = Math.max(
            Math.abs(beyondHeights.floorFrom - here.floorFrom),
            Math.abs(beyondHeights.floorTo - here.floorTo),
        );
        const headroom = Math.min(
            Math.min(here.ceilingFrom, beyondHeights.ceilingFrom) -
                Math.max(here.floorFrom, beyondHeights.floorFrom),
            Math.min(here.ceilingTo, beyondHeights.ceilingTo) -
                Math.max(here.floorTo, beyondHeights.floorTo),
        );

        if (climb > MAX_STEP || headroom < MIN_HEADROOM) {
            scene.colliders.push({
                kind: 'segment',
                x1: edge.from.x,
                z1: edge.from.z,
                x2: edge.to.x,
                z2: edge.to.z,
            });
        }
    }

    return mouths;
}
