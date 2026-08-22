import * as THREE from 'three';
import { SKY_CEILING_ORDER } from '@/lib/engine/build/constants';
import type { BuildContext } from '@/lib/engine/build/context';
import { gridGeometry, shapeOf } from '@/lib/engine/build/geometry';
import { GRID_SPACING } from '@/lib/engine/constants';
import { boundsOf } from '@/lib/engine/sectors';
import { tileFlatUvs } from '@/lib/engine/textures';
import type { Sector } from '@/types';

/** A floor or a ceiling: the sector's polygon, laid flat at a height. */
export function buildFlat(
    ctx: BuildContext,
    sector: Sector,
    height: number,
    textureName: string | null,
    isWater: boolean,
): void {
    const { scene, materials, textures } = ctx;
    const geometry = materials.track(new THREE.ShapeGeometry(shapeOf(sector)));

    let material: THREE.MeshBasicMaterial | null;

    if (isWater) {
        const bounds = boundsOf([sector]);
        const uv = geometry.getAttribute('uv');

        // The water sheet is a strip of frames, so it is stretched over the
        // sector once rather than tiled.
        for (let index = 0; index < uv.count; index++) {
            uv.setXY(
                index,
                (uv.getX(index) - bounds.minX) /
                    Math.max(bounds.maxX - bounds.minX, 1e-3),
                (uv.getY(index) + bounds.maxZ) /
                    Math.max(bounds.maxZ - bounds.minZ, 1e-3),
            );
        }

        uv.needsUpdate = true;

        material = materials.keep(
            new THREE.MeshBasicMaterial({
                map: textures.water(),
                side: THREE.DoubleSide,
            }),
        );
    } else {
        material = materials.surface(textureName);

        if (material !== null) {
            tileFlatUvs(geometry);
        }
    }

    const holder = new THREE.Group();
    holder.position.y = height;
    holder.rotation.x = -Math.PI / 2;

    if (material === null) {
        const bounds = boundsOf([sector]);
        const mesh = new THREE.Mesh(
            geometry,
            materials.backing(materials.floorColor),
        );
        const grid = materials.track(
            gridGeometry(
                bounds.maxX - bounds.minX,
                bounds.maxZ - bounds.minZ,
                GRID_SPACING,
            ),
        );
        const lineMesh = new THREE.LineSegments(
            grid,
            materials.lines(materials.floorColor),
        );

        lineMesh.position.set(
            (bounds.minX + bounds.maxX) / 2,
            -(bounds.minZ + bounds.maxZ) / 2,
            0.004,
        );

        holder.add(mesh, lineMesh);
        scene.targets.push(mesh);
    } else {
        const mesh = new THREE.Mesh(geometry, material);
        holder.add(mesh);
        scene.targets.push(mesh);
    }

    // Tagged for the same reason walls are: an unpainted surface in a debug
    // picture can happen to land on a colour the legend already uses, and a
    // reading tool that occasionally names the wrong wall is worse than no
    // tool at all.
    holder.userData.flat = { sector: sector.slug, height };

    scene.group.add(holder);
    scene.remember(sector.slug, holder);
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
export function buildSkyCeiling(ctx: BuildContext, sector: Sector): void {
    const { scene, materials } = ctx;
    const geometry = materials.track(new THREE.ShapeGeometry(shapeOf(sector)));
    const material = materials.keep(
        new THREE.MeshBasicMaterial({
            colorWrite: false,
            side: THREE.DoubleSide,
        }),
    );

    const holder = new THREE.Group();
    holder.position.y = sector.ceilingHeight;
    holder.rotation.x = -Math.PI / 2;

    const mesh = new THREE.Mesh(geometry, material);
    mesh.renderOrder = SKY_CEILING_ORDER;

    holder.add(mesh);
    scene.group.add(holder);
    scene.skyLids.push({ mesh: holder, room: sector.slug });
}

/** Every room's floor, and its ceiling or the lid that stands in for one. */
export function buildSectorFlats(ctx: BuildContext): void {
    for (const sector of ctx.level.sectors) {
        buildFlat(
            ctx,
            sector,
            sector.floorHeight,
            sector.floorTexture,
            sector.isWater,
        );

        if (sector.isSky) {
            buildSkyCeiling(ctx, sector);
        } else {
            buildFlat(
                ctx,
                sector,
                sector.ceilingHeight,
                sector.ceilingTexture,
                false,
            );
        }
    }
}
