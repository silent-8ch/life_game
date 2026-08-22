import type { BuildContext } from '@/lib/engine/build/context';
import { buildWall } from '@/lib/engine/build/walls';
import { MAX_STEP, MIN_HEADROOM } from '@/lib/engine/constants';
import { namesPortal, portalLinkOf } from '@/lib/engine/portals';
import { edgesOf, inwardNormal } from '@/lib/engine/sectors';
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

        const link = portalLinkOf(edge);

        if (link !== null && topology.portalEnds(link) === 2) {
            // The face the link was set on is not a wall and does not stop the
            // player: it is a pane showing the far mouth's room, built once both
            // mouths are known.
            if (namesPortal(edge)) {
                mouths.push(edge);

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
            buildWall(
                ctx,
                edge,
                sector.floorHeight,
                Math.max(sector.ceilingHeight, beyond?.ceilingHeight ?? 0),
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
            buildWall(
                ctx,
                edge,
                sector.floorHeight,
                sector.ceilingHeight,
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

        // The step up to the next room, and the drop from its ceiling.
        buildWall(ctx, edge, sector.floorHeight, beyond.floorHeight, texture);

        if (!(sector.isSky && beyond.isSky)) {
            buildWall(
                ctx,
                edge,
                beyond.ceilingHeight,
                sector.ceilingHeight,
                texture,
            );
        }

        const climb = Math.abs(beyond.floorHeight - sector.floorHeight);
        const headroom =
            Math.min(sector.ceilingHeight, beyond.ceilingHeight) -
            Math.max(sector.floorHeight, beyond.floorHeight);

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
