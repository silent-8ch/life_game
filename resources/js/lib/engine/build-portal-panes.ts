import * as THREE from 'three';
import { WALL_INSET } from '@/lib/engine/build-geometry';
import type { LevelScene } from '@/lib/engine/build-scene';
import {
    MIRROR_TEXTURE_HEIGHT,
    MIRROR_TEXTURE_WIDTH,
    PORTAL_BOUNCES,
} from '@/lib/engine/constants';
import { createPortalSurface } from '@/lib/engine/portal-surface';
import type { PortalSurface } from '@/lib/engine/portal-surface';
import { portalLinkOf, turnBetween } from '@/lib/engine/portals';
import { boundaryKey, edgesOf, inwardNormal } from '@/lib/engine/sectors';
import type { Edge } from '@/lib/engine/sectors';
import type { Level } from '@/types';

/**
 * The panes that fill portal mouths, each showing what stands beyond the far
 * one, and the pieces that decide how big a pane is and what has to be left out
 * of its view.
 */

/**
 * A portal pane sits exactly in the mouth it fills, unlike an ordinary wall,
 * which is nudged a hair into its own room. Set even slightly forward and its
 * rim reads the far view outside the opening; set back, and walking past it at
 * an angle shows daylight through the gap it leaves. The rim is dealt with in
 * the shader instead, by reading a hair inside the pane rather than at its edge.
 */
const PORTAL_RECESS = 0;

/**
 * How many walls each link names.
 *
 * A portal only counts once both of its walls are there; half a portal stays
 * an ordinary wall rather than a hole to nowhere. Walls are counted rather
 * than faces, since a wall between two rooms has a face each way and both of
 * them are the same mouth.
 */
export function portalEndsOf(level: Level): (link: string) => number {
    const portalWalls = new Map<string, Set<string>>();

    for (const edge of edgesOf(level.sectors)) {
        const link = portalLinkOf(edge);

        if (link === null) {
            continue;
        }

        const walls = portalWalls.get(link) ?? new Set<string>();

        walls.add(boundaryKey(edge.from, edge.to));
        portalWalls.set(link, walls);
    }

    return (link: string): number => portalWalls.get(link)?.size ?? 0;
}

/**
 * Every edge by the room and corner it starts at, for finding the walls
 * that meet the ends of a portal mouth.
 */
function edgesByCorner(level: Level): Map<string, Edge> {
    const edgeAt = new Map<string, Edge>();

    for (const edge of edgesOf(level.sectors)) {
        edgeAt.set(`${edge.sector.slug}#${edge.index}`, edge);
    }

    return edgeAt;
}

/**
 * How far to pull a portal pane back from each end of its mouth.
 *
 * Every wall is nudged WALL_INSET into its own room, so a wall standing at
 * the end of a mouth has its face a centimetre inside the opening. The pane
 * has to stop where that face is, or it hangs past it and its outer rim
 * reads the far view outside the opening.
 *
 * But that is only true where a wall actually stands there. A mouth whose
 * end runs into an open doorway has nothing to meet, and trimming it there
 * leaves a slot a centimetre wide that looks straight past the pane into
 * the room behind the mouth — which is what the flicker at the edge of a
 * portal turned out to be. The two ends are asked separately for that
 * reason: they are very often not alike.
 *
 * The dot product handles walls that meet the mouth at something other than
 * a right angle, where only part of the nudge is along the opening.
 */
function trimOf(
    edgeAt: Map<string, Edge>,
    mouth: Edge,
): { back: number; front: number } {
    const corners = mouth.sector.points.length;

    const spanX = mouth.to.x - mouth.from.x;
    const spanZ = mouth.to.z - mouth.from.z;
    const length = Math.hypot(spanX, spanZ) || 1;
    const along = { x: spanX / length, z: spanZ / length };

    /**
     * @param  index  Which corner of the room the neighbouring wall starts
     *                at.
     * @param  into   Which way along the mouth its own room lies.
     */
    const trim = (index: number, into: number): number => {
        const beside = edgeAt.get(`${mouth.sector.slug}#${index}`);

        if (beside === undefined) {
            return 0;
        }

        // An open boundary draws no face over the height of the mouth, so
        // there is nothing there for the pane to stop against.
        const solid =
            beside.beyond === null ||
            beside.from.blocks ||
            (beside.beyondFrom?.blocks ?? false);

        if (!solid) {
            return 0;
        }

        const normal = inwardNormal(beside.sector, beside.from, beside.to);
        const reach =
            (normal.x * along.x + normal.z * along.z) * into * WALL_INSET;

        return Math.max(0, reach);
    };

    return {
        back: trim((mouth.index - 1 + corners) % corners, 1),
        front: trim((mouth.index + 1) % corners, -1),
    };
}

/** The pane for one mouth, showing what stands beyond the other. */
function buildPortalPane(
    scene: LevelScene,
    edgeAt: Map<string, Edge>,
    entry: Edge,
    exit: Edge,
): PortalSurface {
    const place = (edge: Edge) => {
        const normal = inwardNormal(edge.sector, edge.from, edge.to);

        return {
            centre: new THREE.Vector3(
                (edge.from.x + edge.to.x) / 2,
                0,
                (edge.from.z + edge.to.z) / 2,
            ),
            normal: new THREE.Vector3(normal.x, 0, normal.z),
            length: Math.hypot(
                edge.to.x - edge.from.x,
                edge.to.z - edge.from.z,
            ),
        };
    };

    const near = place(entry);
    const far = place(exit);

    // The same turn the player is given when they walk in, so the pane
    // shows exactly what they arrive in.
    const turn = turnBetween(near.normal, far.normal);

    const through = new THREE.Matrix4()
        .makeTranslation(far.centre.x, 0, far.centre.z)
        .multiply(new THREE.Matrix4().makeRotationY(turn))
        .multiply(
            new THREE.Matrix4().makeTranslation(
                -near.centre.x,
                0,
                -near.centre.z,
            ),
        );

    const height = entry.sector.ceilingHeight - entry.sector.floorHeight;

    // Pulled back at each end to meet the face of whatever wall stands
    // there, which is very often only one of the two.
    const trim = trimOf(edgeAt, entry);

    const geometry = scene.palette.track(
        new THREE.PlaneGeometry(near.length - trim.back - trim.front, height),
    );

    // Trimming one end and not the other moves the middle of the pane off
    // the middle of the mouth.
    const shift = (trim.back - trim.front) / 2;
    const alongX = (entry.to.x - entry.from.x) / (near.length || 1);
    const alongZ = (entry.to.z - entry.from.z) / (near.length || 1);

    const carried = new THREE.Vector3();

    const surface = createPortalSurface({
        geometry,
        aim: (from, out) => {
            // The camera stands where the player would if they walked in.
            out.matrixWorld.multiplyMatrices(through, from.matrixWorld);
            out.matrixWorld.decompose(out.position, out.quaternion, out.scale);
            out.matrixWorldInverse.copy(out.matrixWorld).invert();
        },
        viewerAt: (from) => {
            carried
                .setFromMatrixPosition(from.matrixWorld)
                .applyMatrix4(through);

            return {
                x: carried.x,
                z: carried.z,
                yaw: from.rotation.y + turn,
            };
        },
        readByFarCamera: false,
        tint: new THREE.Color('#ffffff'),
        exitPoint: far.centre,
        exitNormal: far.normal,
        facePoint: near.centre.clone(),
        faceNormal: near.normal.clone(),
        textureWidth: MIRROR_TEXTURE_WIDTH,
        textureHeight: MIRROR_TEXTURE_HEIGHT,
        bounces: PORTAL_BOUNCES,
        home: entry.sector.slug,
        onto: scene.seenFrom(exit.sector.slug),
    });

    // Set back a hair rather than forward like an ordinary wall. Standing
    // proud would make the pane cover slightly more of the screen than the
    // mouth does, and its outer rim would read the far view outside the
    // opening, where the tilted near plane has left nothing but sky — a
    // one-pixel bright line all the way round the portal.
    surface.mesh.position.set(
        near.centre.x - near.normal.x * PORTAL_RECESS + alongX * shift,
        entry.sector.floorHeight + height / 2,
        near.centre.z - near.normal.z * PORTAL_RECESS + alongZ * shift,
    );
    surface.mesh.rotation.y = Math.atan2(near.normal.x, near.normal.z);
    surface.mesh.updateMatrixWorld(true);
    surface.settle();

    scene.group.add(surface.mesh);
    scene.targets.push(surface.mesh);

    geometry.computeBoundingSphere();
    surface.bounds
        .copy(geometry.boundingSphere as THREE.Sphere)
        .applyMatrix4(surface.mesh.matrixWorld);

    return surface;
}

/**
 * What a pane looking through this mouth must not draw: whatever the room
 * behind the mouth put on the camera's side of it.
 *
 * Only what is on that side, give or take the hair every wall is drawn past
 * its own corners. A room that wraps properly back past the plane of its own
 * wall — a mouth set in a notch, say — has parts that genuinely show through
 * the opening, and those stay in.
 */
function standingIn(scene: LevelScene, mouth: Edge): THREE.Object3D[] {
    // The room straight through the mouth is the obvious one, but not the
    // only one that can reach the opening. A room that merely shares a
    // corner with the mouth has a wall running away from that corner, and
    // that wall is nudged a centimetre into its own room — which, for the
    // wall standing at the end of a mouth, means a centimetre into the
    // opening. It then draws down the edge of the pane as a hard stripe of
    // a wall from somewhere else entirely, which is the fault that was
    // being chased as a flicker at portal borders.
    const touching = new Set<string>(
        mouth.beyond === null ? [] : [mouth.beyond.slug],
    );

    const sameSpot = (
        a: { x: number; z: number },
        b: { x: number; z: number },
    ): boolean => Math.hypot(a.x - b.x, a.z - b.z) < 1e-3;

    for (const sector of scene.level.sectors) {
        if (sector.slug === mouth.sector.slug) {
            continue;
        }

        const meets = sector.points.some(
            (point) => sameSpot(point, mouth.from) || sameSpot(point, mouth.to),
        );

        if (meets) {
            touching.add(sector.slug);
        }
    }

    if (touching.size === 0) {
        return [];
    }

    const normal = inwardNormal(mouth.sector, mouth.from, mouth.to);
    const plane = new THREE.Plane().setFromNormalAndCoplanarPoint(
        new THREE.Vector3(normal.x, 0, normal.z),
        new THREE.Vector3(
            (mouth.from.x + mouth.to.x) / 2,
            0,
            (mouth.from.z + mouth.to.z) / 2,
        ),
    );

    const box = new THREE.Box3();
    const corner = new THREE.Vector3();

    const behind = [...touching].flatMap((slug) => scene.drawnIn(slug));

    return behind.filter((what) => {
        box.setFromObject(what);

        // The furthest any of it reaches towards the room being looked at.
        let reach = -Infinity;

        for (const x of [box.min.x, box.max.x]) {
            for (const y of [box.min.y, box.max.y]) {
                for (const z of [box.min.z, box.max.z]) {
                    reach = Math.max(
                        reach,
                        plane.distanceToPoint(corner.set(x, y, z)),
                    );
                }
            }
        }

        // A wall is drawn a hair past its own corners, so the room's walls
        // poke that far through the plane of its mouth. That overhang is
        // what showed as a sliver down the edge of the opening.
        return reach <= WALL_INSET * 2;
    });
}

/**
 * Fills each portal mouth with a pane showing the far mouth's room. Both
 * mouths have to be known first, since each one's pane is drawn by a camera
 * standing behind the other.
 */
export function buildPortalPanes(scene: LevelScene, found: Edge[]): void {
    const edgeAt = edgesByCorner(scene.level);
    const pairs = new Map<string, Edge[]>();

    for (const mouth of found) {
        const link = portalLinkOf(mouth) ?? '';

        pairs.set(link, [...(pairs.get(link) ?? []), mouth]);
    }

    for (const pair of pairs.values()) {
        if (pair.length !== 2) {
            continue;
        }

        const made: PortalSurface[] = [];

        for (const [entry, exit] of [
            [pair[0], pair[1]],
            [pair[1], pair[0]],
        ]) {
            const surface = buildPortalPane(scene, edgeAt, entry, exit);

            scene.portals.push(surface);
            made.push(surface);
        }

        // Each pane is drawn by a camera standing behind the other one, in
        // the room whose wall closes that mouth off.
        made[0].partner = made[1].mesh;
        made[1].partner = made[0].mesh;

        made[0].behind = standingIn(scene, pair[1]);
        made[1].behind = standingIn(scene, pair[0]);
    }
}
