import * as THREE from 'three';
import { SKY_CEILING_ORDER, shapeOf } from '@/lib/engine/build-geometry';
import type { LevelScene } from '@/lib/engine/build-scene';
import type { Edge } from '@/lib/engine/sectors';
import type { Sector } from '@/types';

/**
 * The two surfaces that show the sky: the lid over a room open to it, and a
 * wall that does the same trick stood upright. Both write depth and no colour,
 * so the pixels they cover keep whatever the sky dome painted there and
 * everything beyond them is cut away.
 *
 * Both join `skyLids`, so both are only ever shown to somebody standing in the
 * room they belong to.
 */

/** Depth and no colour, drawn before the rooms it is there to hide. */
function nothingButSky(scene: LevelScene): THREE.MeshBasicMaterial {
    const material = new THREE.MeshBasicMaterial({
        colorWrite: false,
        side: THREE.DoubleSide,
    });

    scene.palette.keep(material);

    return material;
}

/**
 * A wall that shows the sky, in the holder its own wall would have used. It
 * keeps its collider — a sky wall is still a wall, so the level cannot be
 * walked out of.
 */
export function buildSkyWall(
    scene: LevelScene,
    edge: Edge,
    holder: THREE.Group,
    drawn: number,
    height: number,
): THREE.Object3D {
    const face = scene.palette.track(new THREE.PlaneGeometry(drawn, height));

    const mesh = new THREE.Mesh(face, nothingButSky(scene));
    mesh.renderOrder = SKY_CEILING_ORDER;

    holder.add(mesh);
    scene.group.add(holder);
    scene.remember(edge.sector.slug, holder);
    scene.skyLids.push({ mesh: holder, room: edge.sector.slug });

    return holder;
}

/**
 * A lid for a room that is open to the sky. It is never seen: it writes
 * depth and no colour, so the pixels it covers keep whatever the sky dome
 * painted there, and everything beyond it is cut away.
 *
 * Without one, a room with no ceiling is a room with a hole in the roof.
 * Sight-lines run out over its walls and into whatever else happens to be
 * on the plan — and in a level that uses the Doom trick, what is on the
 * plan next door is the floor above.
 *
 * Drawn after the sky and before everything else, so the depth is down
 * before there is anything for it to hide.
 *
 * Only ever shown to somebody standing in the room it belongs to. A lid
 * hides whatever is behind it from wherever it is seen, and two sky rooms
 * whose floor plans overlap would otherwise cut each other's rooms away and
 * leave the sky showing through from next door.
 */
export function buildSkyCeiling(scene: LevelScene, sector: Sector): void {
    const geometry = scene.palette.track(
        new THREE.ShapeGeometry(shapeOf(sector)),
    );
    const material = nothingButSky(scene);

    const holder = new THREE.Group();
    holder.position.y = sector.ceilingHeight;
    holder.rotation.x = -Math.PI / 2;

    const mesh = new THREE.Mesh(geometry, material);
    mesh.renderOrder = SKY_CEILING_ORDER;

    holder.add(mesh);
    scene.group.add(holder);
    scene.skyLids.push({ mesh: holder, room: sector.slug });
}
