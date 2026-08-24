import * as THREE from 'three';
import { SKY_CEILING_ORDER, WALL_INSET } from '@/lib/engine/build/constants';
import type { BuildContext } from '@/lib/engine/build/context';
import { gridGeometry } from '@/lib/engine/build/geometry';
import { buildMirrorPane } from '@/lib/engine/build/mirrors';
import { GRID_SPACING } from '@/lib/engine/constants';
import { inwardNormal } from '@/lib/engine/sectors';
import type { Edge } from '@/lib/engine/sectors';
import { tileUvs, tileWallUvs } from '@/lib/engine/textures';

/**
 * A run of wall, given the height of its bottom and top at each of its two
 * ends.
 *
 * Level at both ends it is a rectangle, which is what every wall was before
 * slopes and what every wall in a flat room still is. Under a slope it is a
 * trapezoid, and it may reach zero height at one end — which is what a Build
 * staircase looks like, and is why "the top is not above the bottom" cannot be
 * a test on the whole wall any more. It is a test per end instead: the quad
 * collapses into a triangle, and only a wall that is degenerate at *both* ends
 * is skipped.
 */
export type WallHeights = {
    bottomFrom: number;
    bottomTo: number;
    topFrom: number;
    topTo: number;
};

export function buildWall(
    ctx: BuildContext,
    edge: Edge,
    heights: WallHeights,
    textureName: string | null,
): THREE.Object3D | null {
    const { scene, materials, topology } = ctx;

    const { bottomFrom, bottomTo, topFrom, topTo } = heights;
    const flat = bottomFrom === bottomTo && topFrom === topTo;

    if (topFrom - bottomFrom <= 1e-3 && topTo - bottomTo <= 1e-3) {
        return null;
    }

    const bottom = Math.min(bottomFrom, bottomTo);
    const top = Math.max(topFrom, topTo);
    const height = top - bottom;

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

    /**
     * Which way along the wall the holder's own +x points.
     *
     * The quarter turn that faces the wall into its room sends local +x to the
     * wall's direction or to the reverse of it, depending which way the room
     * was wound. A rectangle does not care. A trapezoid does: put the two ends
     * the wrong way round and the wall slopes uphill where the floor slopes
     * down.
     */
    const towards = (normal.z * spanX - normal.x * spanZ) / length > 0 ? 1 : -1;

    /** How far along the wall a point at this local x is, as a fraction. */
    const alongAt = (localX: number): number =>
        0.5 + (towards * (localX + (towards * (front - back)) / 2)) / length;

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
        const mirror = buildMirrorPane(
            ctx,
            edge,
            new THREE.Vector3(centreX, bottom + height / 2, centreZ),
            new THREE.Vector3(normal.x, 0, normal.z),
            length,
            height,
        );

        // ...and then the wall it hangs on, which this used to skip entirely.
        //
        // **A room whose every edge is a mirror had no geometry at eye level at
        // all.** Floor and ceiling seeded the reflections above and below, and
        // reflected beautifully. Along the horizon there was nothing but panes
        // showing panes, and at the last bounce those close into a loop with
        // nothing to end on. Black is that loop's fixed point: it starts black
        // on the first frame and no number of frames fills it in. Photographed
        // at Paul's own spot, 86 to 100 per cent of every pane's middle row
        // came back pure black while the floor a few rows below was perfect.
        //
        // So the corridor now ends on plaster, the way it ends in a real room
        // of mirrors — because there is a real wall there, not because
        // something paints one on. `prepareReflections` takes the mirrors out
        // of the picture at the last bounce and this is what is behind them.
        //
        // A hair further back than the wall would otherwise stand, so the pane
        // and its backing are not in the same plane fighting over which is in
        // front. That leaves the pane where it was, which matters: the pane's
        // plane *is* the mirror, and moving it would move every reflection in
        // the room.
        holder.position.x -= normal.x * WALL_INSET;
        holder.position.z -= normal.z * WALL_INSET;

        // ...and taken out of its own mirror's pass, or it is the only thing
        // that mirror can see.
        //
        // A mirror's camera stands **behind** the glass, and the backing wall
        // is behind the glass as well — so the wall lands between the camera
        // and the room it is meant to be looking into. The tilted near plane is
        // supposed to cut away everything on that side, and mostly does, but
        // WALL_INSET is 0.01 and CLIP_BIAS is 0.005: the wall sits inside the
        // slack and survives, filling the pane with the back of itself. Paul,
        // immediately: *i see mostly the backing walls*.
        //
        // `behind` is the list this file's neighbour already keeps for exactly
        // this — what a pane's own camera would otherwise be staring at — and
        // taking the room out of the pass is called the certain way there for
        // the same reason it is the certain way here. Only from this mirror's
        // own view: every *other* pane still sees it, which is what makes the
        // far end of the tunnel a wall rather than a hole.
        mirror.behind = [holder];
    }

    const drawn = length + back + front;
    const material = materials.surface(textureName);

    /** Where the local x/y frame's origin sits in world height. */
    const middle = bottom + height / 2;

    const lerp = (from: number, to: number, at: number): number =>
        from + (to - from) * at;

    /**
     * The wall as four corners, when it is not a rectangle.
     *
     * The heights are the plane's own at each end of the wall, so extending the
     * quad past those ends — which every wall does where it turns a corner —
     * extrapolates them. An overhang left flat would poke out through the
     * neighbouring wall exactly where the two are meant to close a notch.
     */
    const trapezoid = (): {
        geometry: THREE.BufferGeometry;
        along: number[];
        up: number[];
    } => {
        const geometry = new THREE.BufferGeometry();

        const points: number[] = [];
        const along: number[] = [];
        const up: number[] = [];

        for (const localX of [-drawn / 2, drawn / 2]) {
            const at = alongAt(localX);
            const under = lerp(bottomFrom, bottomTo, at);
            const over = Math.max(lerp(topFrom, topTo, at), under);

            // Bottom then top, so the two ends interleave into the winding
            // below without any arithmetic on indices.
            points.push(localX, under - middle, 0);
            points.push(localX, over - middle, 0);

            along.push(at * length, at * length);
            up.push(under, over);
        }

        geometry.setAttribute(
            'position',
            new THREE.Float32BufferAttribute(points, 3),
        );
        geometry.setAttribute(
            'uv',
            new THREE.Float32BufferAttribute([0, 0, 0, 0, 0, 0, 0, 0], 2),
        );
        // 0 and 1 are the near end's bottom and top, 2 and 3 the far end's.
        // Wound so the face looks along the holder's +z, which is the inward
        // normal — the same way a PlaneGeometry does.
        geometry.setIndex([0, 2, 3, 0, 3, 1]);
        geometry.computeVertexNormals();

        return { geometry, along, up };
    };

    if (edge.from.isSky) {
        const face = materials.track(
            flat
                ? new THREE.PlaneGeometry(drawn, height)
                : trapezoid().geometry,
        );
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

    const shaped = flat ? null : trapezoid();
    const face = materials.track(
        shaped === null
            ? new THREE.PlaneGeometry(drawn, height)
            : shaped.geometry,
    );

    if (material === null) {
        const mesh = new THREE.Mesh(
            face,
            materials.backing(materials.wallColor),
        );
        const grid = materials.track(gridGeometry(drawn, height, GRID_SPACING));

        if (shaped !== null) {
            // The grid describes the wall, so it has to stay inside it: each
            // line is squeezed into the height the trapezoid actually has where
            // that line falls.
            const position = grid.getAttribute('position');

            for (let index = 0; index < position.count; index++) {
                const localX = position.getX(index);
                const at = alongAt(localX);
                const under = lerp(bottomFrom, bottomTo, at);
                const over = Math.max(lerp(topFrom, topTo, at), under);
                const share = (position.getY(index) + height / 2) / height;

                position.setY(index, lerp(under, over, share) - middle);
            }

            position.needsUpdate = true;
        }

        holder.add(
            mesh,
            new THREE.LineSegments(grid, materials.lines(materials.wallColor)),
        );
        scene.targets.push(mesh);
    } else {
        if (shaped === null) {
            tileUvs(face, drawn, height);
        } else {
            tileWallUvs(face, shaped.along, shaped.up);
        }

        const mesh = new THREE.Mesh(face, material);
        holder.add(mesh);
        scene.targets.push(mesh);
    }

    scene.group.add(holder);
    scene.remember(edge.sector.slug, holder);

    return holder;
}
