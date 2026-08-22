import * as THREE from 'three';
import { SKY_CEILING_ORDER } from '@/lib/engine/build/constants';
import type { BuildContext } from '@/lib/engine/build/context';
import { gridGeometry, shapeOf } from '@/lib/engine/build/geometry';
import { GRID_SPACING } from '@/lib/engine/constants';
import { boundsOf } from '@/lib/engine/sectors';
import { tileFlatUvs } from '@/lib/engine/textures';
import type { Sector } from '@/types';

/**
 * Turns a flat over so that it faces down.
 *
 * A ceiling is the same polygon as a floor, laid at a different height, and
 * both are made by rotating the shape a quarter turn about x. That leaves a
 * ceiling's normal pointing **up**, exactly like a floor's — harmless while
 * nothing is lit and every surface is drawn double-sided, and fatal the moment
 * anything is, because every ceiling in the level then lights as though it were
 * the floor.
 *
 * Rotating it the other way is not the fix: keeping the polygon where it is
 * while turning its normal over is a reflection rather than a rotation, and the
 * reflection moves the room. So the triangles are wound the other way round
 * instead and the normals worked out again from that — the polygon does not
 * move a millimetre, and the face that is the front of it becomes the one
 * underneath, which is what a ceiling drawn `FrontSide` will want.
 */
function faceDownwards(geometry: THREE.BufferGeometry): void {
    const index = geometry.getIndex();

    if (index === null) {
        return;
    }

    for (let at = 0; at < index.count; at += 3) {
        const second = index.getX(at + 1);

        index.setX(at + 1, index.getX(at + 2));
        index.setX(at + 2, second);
    }

    index.needsUpdate = true;
    geometry.computeVertexNormals();
}

/** How a flat is laid: what it is for, and which way it faces. */
type FlatOptions = {
    height: number;
    textureName: string | null;
    isWater: boolean;
    /** A ceiling. Floors face up; ceilings have to be turned over. */
    facesDown: boolean;
};

/** A floor or a ceiling: the sector's polygon, laid flat at a height. */
export function buildFlat(
    ctx: BuildContext,
    sector: Sector,
    { height, textureName, isWater, facesDown }: FlatOptions,
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

    if (facesDown) {
        faceDownwards(geometry);
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

    // A lid is a ceiling, and is turned over with the rest of them. It paints
    // nothing and so cannot be lit, but a surface that reports which way it
    // faces should not be the one that lies about it.
    faceDownwards(geometry);

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
        buildFlat(ctx, sector, {
            height: sector.floorHeight,
            textureName: sector.floorTexture,
            isWater: sector.isWater,
            facesDown: false,
        });

        if (sector.isSky) {
            buildSkyCeiling(ctx, sector);
        } else {
            buildFlat(ctx, sector, {
                height: sector.ceilingHeight,
                textureName: sector.ceilingTexture,
                isWater: false,
                facesDown: true,
            });
        }
    }
}
