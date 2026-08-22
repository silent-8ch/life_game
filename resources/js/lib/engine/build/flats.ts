import * as THREE from 'three';
import { SKY_CEILING_ORDER } from '@/lib/engine/build/constants';
import type { BuildContext } from '@/lib/engine/build/context';
import { gridGeometry, shapeOf } from '@/lib/engine/build/geometry';
import { GRID_SPACING } from '@/lib/engine/constants';
import { boundsOf, ceilingAt, floorAt } from '@/lib/engine/sectors';
import { tileFlatUvs } from '@/lib/engine/textures';
import type { Sector } from '@/types';

/**
 * The least gap between a floor and a ceiling that counts as a room having
 * height. The same figure `buildWall` refuses a wall at, for the same reason:
 * below it the two surfaces are one surface and drawing both only makes them
 * fight.
 */
const FLAT_MINIMUM = 1e-3;

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

/** How a flat is laid: what it is, and at what base height. */
type FlatOptions = {
    /** Which surface, since a ceiling faces down and slopes on its own hinge. */
    surface: 'floor' | 'ceiling';
    height: number;
    textureName: string | null;
    isWater: boolean;
};

/**
 * Tilts a flat onto its slope, one vertex at a time.
 *
 * `ShapeGeometry` lays the polygon out in the local x/y plane as `(x, -z)` and
 * the holder's quarter turn about x maps local +z onto world +y. So a vertex is
 * raised by displacing its **local z**, and the group's own `position.y` stays
 * at the sector's base height — which keeps `tileFlatUvs` correct and untouched,
 * since the UVs go on projected from the horizontal and are meant to stretch
 * along the slope. That is Build's behaviour.
 *
 * @param  offset  Where the geometry sits inside the holder, for the wireframe
 *                 grid, which is built centred on its own origin and then moved.
 * @param  at      The local z it already had, which the grid uses to sit a
 *                 fraction proud of the surface it describes.
 */
function tiltToSlope(
    geometry: THREE.BufferGeometry,
    sector: Sector,
    surface: 'floor' | 'ceiling',
    base: number,
    offset: { x: number; y: number } = { x: 0, y: 0 },
    at = 0,
): void {
    const slope = surface === 'floor' ? sector.floorSlope : sector.ceilingSlope;
    const hinge =
        surface === 'floor' ? sector.floorSlopeEdge : sector.ceilingSlopeEdge;

    if (slope === 0 || hinge === null) {
        return;
    }

    const heightAt = surface === 'floor' ? floorAt : ceilingAt;
    const position = geometry.getAttribute('position');

    for (let index = 0; index < position.count; index++) {
        const x = position.getX(index) + offset.x;
        const y = position.getY(index) + offset.y;

        position.setZ(index, at + heightAt(sector, x, -y) - base);
    }

    position.needsUpdate = true;
    geometry.computeBoundingSphere();
}

/** A floor or a ceiling: the sector's polygon, laid flat at a height. */
export function buildFlat(
    ctx: BuildContext,
    sector: Sector,
    { surface, height, textureName, isWater }: FlatOptions,
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

    tiltToSlope(geometry, sector, surface, height);

    if (surface === 'ceiling') {
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

        const middle = {
            x: (bounds.minX + bounds.maxX) / 2,
            y: -(bounds.minZ + bounds.maxZ) / 2,
        };

        // The grid describes the surface, so it has to follow it. Built centred
        // on its own origin and then moved, so where each line actually falls in
        // the room is its own position plus that move.
        tiltToSlope(grid, sector, surface, height, middle, 0.004);

        lineMesh.position.set(middle.x, middle.y, 0.004);

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

    // A lid follows its room's ceiling slope, or it stops covering the room.
    tiltToSlope(geometry, sector, 'ceiling', sector.ceilingHeight);

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

/**
 * Whether a room has any height in it at all.
 *
 * A room whose ceiling is nowhere above its floor puts both surfaces in one
 * plane, and two opaque flats in one plane is a z-fight the size of the room:
 * which one wins is decided per pixel by the last bit of the depth value, so it
 * changes as the camera moves and the whole slab flashes between two textures.
 *
 * `buildWall` has refused a wall with no height between its ends since slopes
 * landed. Flats had no such guard, and level 8 has two rooms left over from
 * carving — `room-11` and `room-12`, floor and ceiling both at 15 — sitting
 * fifteen metres up over the edge of the yard, where a sky room's walls stop
 * well below them and there is nothing to hide the fight.
 *
 * Both surfaces are planes, so the gap between them is linear across the floor
 * plan and its largest value is at one of the corners. Checking the corners is
 * therefore exact rather than a sample, and it stays right under a slope: a room
 * that pinches to nothing at one end still has height at the other and still
 * gets its ceiling.
 */
function hasHeight(sector: Sector): boolean {
    return sector.points.some(
        (point) =>
            ceilingAt(sector, point.x, point.z) -
                floorAt(sector, point.x, point.z) >
            FLAT_MINIMUM,
    );
}

/** Every room's floor, and its ceiling or the lid that stands in for one. */
export function buildSectorFlats(ctx: BuildContext): void {
    for (const sector of ctx.level.sectors) {
        buildFlat(ctx, sector, {
            surface: 'floor',
            height: sector.floorHeight,
            textureName: sector.floorTexture,
            isWater: sector.isWater,
        });

        // The floor of a room with no height in it is also its ceiling. Draw
        // both and they fight; draw one and the slab is at least stable.
        if (!hasHeight(sector)) {
            continue;
        }

        if (sector.isSky) {
            buildSkyCeiling(ctx, sector);
        } else {
            buildFlat(ctx, sector, {
                surface: 'ceiling',
                height: sector.ceilingHeight,
                textureName: sector.ceilingTexture,
                isWater: false,
            });
        }
    }
}
