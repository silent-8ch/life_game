import * as THREE from 'three';
import { PORTAL_RECESS, WALL_INSET } from '@/lib/engine/build/constants';
import type { BuildContext } from '@/lib/engine/build/context';
import {
    MIRROR_TEXTURE_HEIGHT,
    MIRROR_TEXTURE_WIDTH,
    PORTAL_BOUNCES,
} from '@/lib/engine/constants';
import { createPortalSurface } from '@/lib/engine/portal-surface';
import type { PortalSurface } from '@/lib/engine/portal-surface';
import { portalLinkOf, turnBetween } from '@/lib/engine/portals';
import { floorAt, heightsAlong, inwardNormal } from '@/lib/engine/sectors';
import type { Edge } from '@/lib/engine/sectors';

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
    ctx: BuildContext,
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
        const beside = ctx.topology.edgeAt.get(`${mouth.sector.slug}#${index}`);

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
    ctx: BuildContext,
    entry: Edge,
    exit: Edge,
): PortalSurface {
    const { scene, materials, topology } = ctx;

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

    // How far the far room sits above this one, at the middle of each mouth.
    // The camera is lifted by it for the same reason the walk is: a pane
    // showing one thing while the walk arrives at another is the one way a
    // portal can look wrong, and this file already says so about the turn.
    const rise =
        floorAt(exit.sector, far.centre.x, far.centre.z) -
        floorAt(entry.sector, near.centre.x, near.centre.z);

    const through = new THREE.Matrix4()
        .makeTranslation(far.centre.x, rise, far.centre.z)
        .multiply(new THREE.Matrix4().makeRotationY(turn))
        .multiply(
            new THREE.Matrix4().makeTranslation(
                -near.centre.x,
                0,
                -near.centre.z,
            ),
        );

    // Pulled back at each end to meet the face of whatever wall stands
    // there, which is very often only one of the two.
    const trim = trimOf(ctx, entry);

    const width = near.length - trim.back - trim.front;

    // A mouth covers its room's floor to its room's ceiling, and under a slope
    // neither of those is one number. Both ends are taken, and the opening is
    // the trapezoid between them.
    const mouth = heightsAlong(entry.sector, entry.from, entry.to);
    const flat =
        mouth.floorFrom === mouth.floorTo &&
        mouth.ceilingFrom === mouth.ceilingTo;

    const bottom = Math.min(mouth.floorFrom, mouth.floorTo);
    const top = Math.max(mouth.ceilingFrom, mouth.ceilingTo);
    const height = top - bottom;
    const middle = bottom + height / 2;

    // Trimming one end and not the other moves the middle of the pane off
    // the middle of the mouth.
    const shift = (trim.back - trim.front) / 2;
    const alongX = (entry.to.x - entry.from.x) / (near.length || 1);
    const alongZ = (entry.to.z - entry.from.z) / (near.length || 1);

    /**
     * Which way along the mouth the pane's own +x points, and how far along the
     * mouth a point at a given local x is. The same reasoning as a wall's: the
     * quarter turn that faces the pane into its room sends local +x one way or
     * the other depending which way the room was wound, and a trapezoid put on
     * back to front slopes against its own opening.
     */
    const towards =
        (near.normal.z * (entry.to.x - entry.from.x) -
            near.normal.x * (entry.to.z - entry.from.z)) /
            (near.length || 1) >
        0
            ? 1
            : -1;

    const alongAt = (localX: number): number =>
        0.5 + (shift + towards * localX) / (near.length || 1);

    const trapezoid = (): THREE.BufferGeometry => {
        const shaped = new THREE.BufferGeometry();
        const points: number[] = [];
        const uvs: number[] = [];

        for (const localX of [-width / 2, width / 2]) {
            const at = alongAt(localX);
            const under =
                mouth.floorFrom + (mouth.floorTo - mouth.floorFrom) * at;
            const over = Math.max(
                mouth.ceilingFrom + (mouth.ceilingTo - mouth.ceilingFrom) * at,
                under,
            );

            points.push(localX, under - middle, 0);
            points.push(localX, over - middle, 0);
            uvs.push(0, 0, 0, 1);
        }

        shaped.setAttribute(
            'position',
            new THREE.Float32BufferAttribute(points, 3),
        );
        shaped.setAttribute('uv', new THREE.Float32BufferAttribute(uvs, 2));
        shaped.setIndex([0, 2, 3, 0, 3, 1]);
        shaped.computeVertexNormals();

        return shaped;
    };

    const geometry = materials.track(
        flat ? new THREE.PlaneGeometry(width, height) : trapezoid(),
    );

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
        exitPoint: far.centre,
        exitNormal: far.normal,
        // The pane's own middle, not the mouth's point on the floor plan.
        //
        // `hug()` measures how far the eye is along the opening and how far up
        // it, and refuses unless both are inside the pane. It measures from
        // this point — so handing it `near.centre`, whose y is zero because it
        // is a floor-plan position, made "how far up" mean "how high is the eye
        // above the floor of the level" rather than "how far is the eye from
        // the middle of this opening".
        //
        // A mouth at ground level survives that by coincidence and nothing
        // else: a room 0 to 3 puts the limit at 1.5 + PANE_CLEARANCE = 1.62,
        // and EYE_HEIGHT is 1.62, so the test passes by exactly zero. Every
        // mouth in the portal demo is such a room, which is why the demo is
        // seamless and why this was never found there.
        //
        // Level 8's stairs portal is 4.8 to 8.6. The eye stands at 6.42, which
        // is 0.28 from the middle of the mouth and well inside it — but
        // measured from zero it reads as 6.42 against a limit of 2.02, so the
        // pane never hugged at all. Walking into that portal you meet the near
        // plane cutting an un-hugged pane instead, which is the flash.
        facePoint: new THREE.Vector3(
            near.centre.x + alongX * shift,
            middle,
            near.centre.z + alongZ * shift,
        ),
        faceNormal: near.normal.clone(),
        textureWidth: MIRROR_TEXTURE_WIDTH,
        textureHeight: MIRROR_TEXTURE_HEIGHT,
        bounces: PORTAL_BOUNCES,
        home: entry.sector.slug,
        onto: topology.seenFrom(exit.sector.slug),
    });

    // Set back a hair rather than forward like an ordinary wall. Standing
    // proud would make the pane cover slightly more of the screen than the
    // mouth does, and its outer rim would read the far view outside the
    // opening, where the tilted near plane has left nothing but sky — a
    // one-pixel bright line all the way round the portal.
    surface.mesh.position.set(
        near.centre.x - near.normal.x * PORTAL_RECESS + alongX * shift,
        middle,
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
function standingIn(ctx: BuildContext, mouth: Edge): THREE.Object3D[] {
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

    for (const sector of ctx.level.sectors) {
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

    const behind = [...touching].flatMap(
        (slug) => ctx.scene.drawnByRoom.get(slug) ?? [],
    );

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
export function buildPortals(ctx: BuildContext, found: Edge[]): void {
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
            const surface = buildPortalPane(ctx, entry, exit);

            ctx.scene.portals.push(surface);
            made.push(surface);
        }

        // Each pane is drawn by a camera standing behind the other one, in
        // the room whose wall closes that mouth off.
        made[0].partner = made[1].mesh;
        made[1].partner = made[0].mesh;

        made[0].behind = standingIn(ctx, pair[1]);
        made[1].behind = standingIn(ctx, pair[0]);

        // And what stands behind each pane's *own* mouth, which is what would
        // otherwise be drawn over it while it is hugged across the player's
        // view. The other way round from `behind`, and needed for the frame the
        // player is shown rather than the one the pane draws.
        made[0].blocking = standingIn(ctx, pair[0]);
        made[1].blocking = standingIn(ctx, pair[1]);
    }
}
