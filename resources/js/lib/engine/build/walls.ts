import * as THREE from 'three';
import { SKY_CEILING_ORDER, WALL_INSET } from '@/lib/engine/build/constants';
import type { BuildContext } from '@/lib/engine/build/context';
import { gridGeometry } from '@/lib/engine/build/geometry';
import { buildMirrorPane } from '@/lib/engine/build/mirrors';
import { GRID_SPACING } from '@/lib/engine/constants';
import { inwardNormal } from '@/lib/engine/sectors';
import type { Edge } from '@/lib/engine/sectors';
import { tileUvs } from '@/lib/engine/textures';

/**
 * A flat run of wall between two heights, facing into its own sector.
 */
export function buildWall(
    ctx: BuildContext,
    edge: Edge,
    bottom: number,
    top: number,
    textureName: string | null,
): THREE.Object3D | null {
    const { scene, materials, topology } = ctx;
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
    const back = topology.carriedOn.back(edge) ? 0 : WALL_INSET;
    const front = topology.carriedOn.front(edge) ? 0 : WALL_INSET;
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
            ctx,
            edge,
            new THREE.Vector3(centreX, bottom + height / 2, centreZ),
            new THREE.Vector3(normal.x, 0, normal.z),
            length,
            height,
        );

        return null;
    }

    const drawn = length + back + front;
    const material = materials.surface(textureName);

    if (edge.from.isSky) {
        const face = materials.track(new THREE.PlaneGeometry(drawn, height));
        const skyMaterial = materials.keep(
            new THREE.MeshBasicMaterial({
                colorWrite: false,
                side: THREE.DoubleSide,
            }),
        );

        const mesh = new THREE.Mesh(face, skyMaterial);
        mesh.renderOrder = SKY_CEILING_ORDER;

        holder.add(mesh);
        scene.group.add(holder);
        scene.remember(edge.sector.slug, holder);
        scene.skyLids.push({ mesh: holder, room: edge.sector.slug });

        return holder;
    }

    const face = materials.track(new THREE.PlaneGeometry(drawn, height));

    if (material === null) {
        const mesh = new THREE.Mesh(
            face,
            materials.backing(materials.wallColor),
        );
        const grid = materials.track(gridGeometry(drawn, height, GRID_SPACING));

        holder.add(
            mesh,
            new THREE.LineSegments(grid, materials.lines(materials.wallColor)),
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
