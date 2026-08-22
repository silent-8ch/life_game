import * as THREE from 'three';
import { WALL_INSET, gridGeometry } from '@/lib/engine/build-geometry';
import { buildMirrorPane } from '@/lib/engine/build-mirrors';
import { portalEndsOf } from '@/lib/engine/build-portal-panes';
import type { LevelScene } from '@/lib/engine/build-scene';
import { buildSkyWall } from '@/lib/engine/build-sky';
import { GRID_SPACING, MAX_STEP, MIN_HEADROOM } from '@/lib/engine/constants';
import { namesPortal, portalLinkOf } from '@/lib/engine/portals';
import { edgesOf, inwardNormal } from '@/lib/engine/sectors';
import type { Edge } from '@/lib/engine/sectors';
import { tileUvs } from '@/lib/engine/textures';
import type { Level } from '@/types';

/**
 * The walls: a full-height face for every boundary with nothing on the far
 * side, the step up to the next room and the drop from its ceiling where there
 * is, and the collider that goes with each of them.
 */

/** Which ends of a wall run straight on into another one. */
type CarriedOn = {
    /** Whether another wall picks up where this one stops. */
    front: (edge: Edge) => boolean;
    /** Whether another wall runs into where this one starts. */
    back: (edge: Edge) => boolean;
};

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
 *
 * Worked out over the whole level rather than per sector: the wall that carries
 * on is very often the next room's.
 */
function carriedOnEnds(level: Level): CarriedOn {
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

/**
 * A flat run of wall between two heights, facing into its own sector.
 */
function buildWall(
    scene: LevelScene,
    carriedOn: CarriedOn,
    edge: Edge,
    bottom: number,
    top: number,
    textureName: string | null,
): THREE.Object3D | null {
    const { palette } = scene;
    const height = top - bottom;

    if (height <= 1e-3) {
        return null;
    }

    const spanX = edge.to.x - edge.from.x;
    const spanZ = edge.to.z - edge.from.z;
    const length = Math.hypot(spanX, spanZ);

    if (length <= 1e-3) {
        return null;
    }

    const normal = inwardNormal(edge.sector, edge.from, edge.to);

    // Drawn past its ends only where it turns a corner. Where the two ends
    // differ the middle of the face is no longer the middle of the wall.
    const back = carriedOn.back(edge) ? 0 : WALL_INSET;
    const front = carriedOn.front(edge) ? 0 : WALL_INSET;
    const shift = (front - back) / 2;

    // Every wall is nudged a hair into its own room. Two rooms that meet
    // draw a face each, and a fence run along the back of a house sits in
    // the same plane as the house wall; without the nudge those pairs fight
    // over which one is in front.
    const centreX =
        (edge.from.x + edge.to.x) / 2 +
        normal.x * WALL_INSET +
        (spanX / length) * shift;
    const centreZ =
        (edge.from.z + edge.to.z) / 2 +
        normal.z * WALL_INSET +
        (spanZ / length) * shift;

    const holder = new THREE.Group();
    holder.position.set(centreX, bottom + height / 2, centreZ);
    holder.rotation.y = Math.atan2(normal.x, normal.z);

    // Which boundary this face belongs to, so debug painting can name a
    // stray sliver rather than leaving somebody to guess at it. Costs a
    // small object per wall and nothing at all to draw.
    holder.userData.wall = {
        sector: edge.sector.slug,
        index: edge.index,
        from: { x: edge.from.x, z: edge.from.z },
        to: { x: edge.to.x, z: edge.to.z },
        beyond: edge.beyond?.slug ?? null,
    };

    if (edge.from.isMirror) {
        buildMirrorPane(
            scene,
            edge,
            new THREE.Vector3(centreX, bottom + height / 2, centreZ),
            new THREE.Vector3(normal.x, 0, normal.z),
            length,
            height,
        );

        return null;
    }

    const drawn = length + back + front;
    const material = palette.surfaceMaterial(textureName);

    if (edge.from.isSky) {
        return buildSkyWall(scene, edge, holder, drawn, height);
    }

    const face = palette.track(new THREE.PlaneGeometry(drawn, height));

    if (material === null) {
        const mesh = new THREE.Mesh(face, palette.backing(palette.wallColor));
        const grid = palette.track(gridGeometry(drawn, height, GRID_SPACING));

        holder.add(
            mesh,
            new THREE.LineSegments(grid, palette.lines(palette.wallColor)),
        );
        scene.targets.push(mesh);
    } else {
        tileUvs(face, drawn, height);

        const mesh = new THREE.Mesh(face, material);
        holder.add(mesh);
        scene.targets.push(mesh);
    }

    scene.group.add(holder);
    scene.remember(edge.sector.slug, holder);

    return holder;
}

/**
 * Every boundary in the level, as a wall, a pair of step walls, or a portal
 * mouth — and the collider, if any, that goes with it.
 *
 * @return The mouths, whose panes are filled in once both ends are known.
 */
export function buildWalls(scene: LevelScene): Edge[] {
    const { level } = scene;
    const carriedOn = carriedOnEnds(level);
    const portalEnds = portalEndsOf(level);
    const mouths: Edge[] = [];

    for (const edge of edgesOf(level.sectors)) {
        const { sector, beyond } = edge;
        const texture = edge.from.wallTexture ?? sector.wallTexture;

        const link = portalLinkOf(edge);

        if (link !== null && portalEnds(link) === 2) {
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
                scene,
                carriedOn,
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
                scene,
                carriedOn,
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
        buildWall(
            scene,
            carriedOn,
            edge,
            sector.floorHeight,
            beyond.floorHeight,
            texture,
        );

        if (!(sector.isSky && beyond.isSky)) {
            buildWall(
                scene,
                carriedOn,
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
