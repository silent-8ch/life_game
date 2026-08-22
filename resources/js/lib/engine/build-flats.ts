import * as THREE from 'three';
import { gridGeometry, shapeOf } from '@/lib/engine/build-geometry';
import type { LevelScene } from '@/lib/engine/build-scene';
import { buildSkyCeiling } from '@/lib/engine/build-sky';
import { GRID_SPACING } from '@/lib/engine/constants';
import { boundsOf } from '@/lib/engine/sectors';
import { tileFlatUvs } from '@/lib/engine/textures';
import type { Sector } from '@/types';

/**
 * The floors and ceilings. A sector contributes a floor, and a ceiling unless
 * it is open to the sky, in which case it gets a lid instead.
 */

/** A floor or a ceiling: the sector's polygon, laid flat at a height. */
function buildFlat(
    scene: LevelScene,
    sector: Sector,
    height: number,
    textureName: string | null,
    isWater: boolean,
): void {
    const { palette } = scene;
    const geometry = palette.track(new THREE.ShapeGeometry(shapeOf(sector)));

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

        material = new THREE.MeshBasicMaterial({
            map: scene.textures.water(),
            side: THREE.DoubleSide,
        });
        palette.keep(material);
    } else {
        material = palette.surfaceMaterial(textureName);

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
            palette.backing(palette.floorColor),
        );
        const grid = palette.track(
            gridGeometry(
                bounds.maxX - bounds.minX,
                bounds.maxZ - bounds.minZ,
                GRID_SPACING,
            ),
        );
        const lineMesh = new THREE.LineSegments(
            grid,
            palette.lines(palette.floorColor),
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

/** A floor for every room, and a ceiling or a sky lid over it. */
export function buildFlats(scene: LevelScene): void {
    for (const sector of scene.level.sectors) {
        buildFlat(
            scene,
            sector,
            sector.floorHeight,
            sector.floorTexture,
            sector.isWater,
        );

        if (sector.isSky) {
            buildSkyCeiling(scene, sector);
        } else {
            buildFlat(
                scene,
                sector,
                sector.ceilingHeight,
                sector.ceilingTexture,
                false,
            );
        }
    }
}
