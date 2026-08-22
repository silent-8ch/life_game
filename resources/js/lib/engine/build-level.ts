import * as THREE from 'three';
import type { Collider } from '@/lib/engine/collision';
import {
    GRID_SPACING,
    MAX_STEP,
    MIN_HEADROOM,
    MIRROR_TEXTURE_HEIGHT,
    MIRROR_TEXTURE_WIDTH,
    MIRROR_TINT,
    PORTAL_BOUNCES,
} from '@/lib/engine/constants';
import { createPortalSurface } from '@/lib/engine/portal-surface';
import type { PortalSurface } from '@/lib/engine/portal-surface';
import { namesPortal, portalLinkOf, turnBetween } from '@/lib/engine/portals';
import {
    boundaryKey,
    boundsOf,
    edgesOf,
    inwardNormal,
} from '@/lib/engine/sectors';
import type { Edge } from '@/lib/engine/sectors';
import { tileFlatUvs, tileUvs } from '@/lib/engine/textures';
import type { TextureLibrary } from '@/lib/engine/textures';
import type { Level, LevelThing, Sector } from '@/types';

/**
 * Turns a level's sectors into geometry. A sector contributes a floor, a
 * ceiling unless it is open to the sky, and a wall for every edge that has
 * nothing on the far side. Where two sectors meet, only the step between their
 * floors and the drop between their ceilings get built, which is what leaves a
 * doorway open.
 *
 * Surfaces with a texture are drawn with it; surfaces without one fall back to
 * the wireframe, so a half-built level still reads.
 */

/** How much of its line colour an untextured surface keeps. */
const SOLID_TINT = 0.11;

const POLYGON_OFFSET = 1;

/** How far a wall is nudged into its own sector, in metres. */
const WALL_INSET = 0.01;

/**
 * Where the lids on open-to-sky rooms come in the draw order: after the sky,
 * which is at -1 and lays down no depth of its own, and before the rooms, which
 * are at 0 and are what the lids are there to hide.
 */
const SKY_CEILING_ORDER = -0.5;

/**
 * A portal pane sits exactly in the mouth it fills, unlike an ordinary wall,
 * which is nudged a hair into its own room. Set even slightly forward and its
 * rim reads the far view outside the opening; set back, and walking past it at
 * an angle shows daylight through the gap it leaves. The rim is dealt with in
 * the shader instead, by reading a hair inside the pane rather than at its edge.
 */
const PORTAL_RECESS = 0;

const HIGHLIGHT_COLOR = '#ffffff';

export type BuiltLevel = {
    group: THREE.Group;
    colliders: Collider[];
    /** Everything the look-at ray can hit, walls included. */
    targets: THREE.Object3D[];
    /** Mirrors, which are panes whose far side is the room reflected. */
    mirrors: PortalSurface[];
    /** One pane per portal mouth, each showing the view from the far one. */
    portals: PortalSurface[];
    /**
     * The lids over rooms open to the sky and the walls that show it, each
     * knowing whose room it is. Only the ones belonging to the room the player
     * is standing in belong in the picture.
     */
    skyLids: { mesh: THREE.Object3D; room: string }[];
    highlight: (slug: string | null) => void;
    dispose: () => void;
};

/** A grid of lines filling a rectangle centred on the origin of the XY plane. */
function gridGeometry(
    width: number,
    height: number,
    spacing: number,
): THREE.BufferGeometry {
    const points: number[] = [];

    const positionsAlong = (extent: number): number[] => {
        const half = extent / 2;
        const values = [-half];

        for (let at = -half + spacing; at < half - 1e-4; at += spacing) {
            values.push(at);
        }

        values.push(half);

        return values;
    };

    for (const x of positionsAlong(width)) {
        points.push(x, -height / 2, 0, x, height / 2, 0);
    }

    for (const y of positionsAlong(height)) {
        points.push(-width / 2, y, 0, width / 2, y, 0);
    }

    const geometry = new THREE.BufferGeometry();
    geometry.setAttribute(
        'position',
        new THREE.Float32BufferAttribute(points, 3),
    );

    return geometry;
}

/** The polygon of a sector, as a shape the triangulator can fill. */
function shapeOf(sector: Sector): THREE.Shape {
    const shape = new THREE.Shape();

    sector.points.forEach((point, index) => {
        if (index === 0) {
            shape.moveTo(point.x, -point.z);
        } else {
            shape.lineTo(point.x, -point.z);
        }
    });

    shape.closePath();

    return shape;
}

export function buildLevel(level: Level, textures: TextureLibrary): BuiltLevel {
    const group = new THREE.Group();
    const colliders: Collider[] = [];
    const targets: THREE.Object3D[] = [];
    const mirrors: PortalSurface[] = [];
    const portals: PortalSurface[] = [];
    const skyLids: { mesh: THREE.Object3D; room: string }[] = [];

    const geometries: THREE.BufferGeometry[] = [];
    const materials: THREE.Material[] = [];

    const track = (geometry: THREE.BufferGeometry): THREE.BufferGeometry => {
        geometries.push(geometry);

        return geometry;
    };

    const wallColor = new THREE.Color(level.wallColor);
    const floorColor = new THREE.Color(level.floorColor);
    const accentColor = new THREE.Color(level.accentColor);

    const untexturedMaterials = new Map<string, THREE.MeshBasicMaterial>();
    const lineMaterials = new Map<string, THREE.LineBasicMaterial>();

    /** The dark backing an untextured surface gets, so walls still occlude. */
    const backing = (color: THREE.Color): THREE.MeshBasicMaterial => {
        const key = color.getHexString();
        const existing = untexturedMaterials.get(key);

        if (existing !== undefined) {
            return existing;
        }

        const material = new THREE.MeshBasicMaterial({
            color: color.clone().multiplyScalar(SOLID_TINT),
            side: THREE.DoubleSide,
            polygonOffset: true,
            polygonOffsetFactor: POLYGON_OFFSET,
            polygonOffsetUnits: POLYGON_OFFSET,
        });

        materials.push(material);
        untexturedMaterials.set(key, material);

        return material;
    };

    const lines = (color: THREE.Color): THREE.LineBasicMaterial => {
        const key = color.getHexString();
        const existing = lineMaterials.get(key);

        if (existing !== undefined) {
            return existing;
        }

        const material = new THREE.LineBasicMaterial({ color });
        materials.push(material);
        lineMaterials.set(key, material);

        return material;
    };

    const textured = new Map<string, THREE.MeshBasicMaterial>();

    const surfaceMaterial = (
        name: string | null,
    ): THREE.MeshBasicMaterial | null => {
        if (name === null) {
            return null;
        }

        const existing = textured.get(name);

        if (existing !== undefined) {
            return existing;
        }

        const map = textures.surface(name);

        if (map === null) {
            return null;
        }

        const material = new THREE.MeshBasicMaterial({
            map,
            side: THREE.DoubleSide,
        });

        materials.push(material);
        textured.set(name, material);

        return material;
    };

    /**
     * A flat run of wall between two heights, facing into its own sector.
     */
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
     */
    const carriedOn = (() => {
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
            /** Whether another wall picks up where this one stops. */
            front: (edge: Edge): boolean => starts.has(at(edge.to, edge)),
            /** Whether another wall runs into where this one starts. */
            back: (edge: Edge): boolean => ends.has(at(edge.from, edge)),
        };
    })();

    /**
     * Everything each room drew, by slug.
     *
     * A pane's camera stands in the room behind its far mouth, so that whole
     * room is between the camera and the opening: its own wall across the mouth,
     * the walls meeting it at the corners, its floor and its ceiling. The tilted
     * near plane is meant to cut all of it away, but anything touching the
     * mouth's plane sits inside the slack CLIP_BIAS leaves and survives as a
     * sliver down the edge of the opening. Taking the room out of the pass is
     * the certain way.
     */
    const drawnByRoom = new Map<string, THREE.Object3D[]>();

    const remember = (slug: string, what: THREE.Object3D | null): void => {
        if (what === null) {
            return;
        }

        drawnByRoom.set(slug, [...(drawnByRoom.get(slug) ?? []), what]);
    };

    const buildWall = (
        edge: Edge,
        bottom: number,
        top: number,
        textureName: string | null,
    ): THREE.Object3D | null => {
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
                edge,
                new THREE.Vector3(centreX, bottom + height / 2, centreZ),
                new THREE.Vector3(normal.x, 0, normal.z),
                length,
                height,
            );

            return null;
        }

        const drawn = length + back + front;
        const material = surfaceMaterial(textureName);

        if (edge.from.isSky) {
            const face = track(new THREE.PlaneGeometry(drawn, height));
            const material = new THREE.MeshBasicMaterial({
                colorWrite: false,
                side: THREE.DoubleSide,
            });

            materials.push(material);

            const mesh = new THREE.Mesh(face, material);
            mesh.renderOrder = SKY_CEILING_ORDER;

            holder.add(mesh);
            group.add(holder);
            remember(edge.sector.slug, holder);
            skyLids.push({ mesh: holder, room: edge.sector.slug });

            return holder;
        }

        const face = track(new THREE.PlaneGeometry(drawn, height));

        if (material === null) {
            const mesh = new THREE.Mesh(face, backing(wallColor));
            const grid = track(gridGeometry(drawn, height, GRID_SPACING));

            holder.add(mesh, new THREE.LineSegments(grid, lines(wallColor)));
            targets.push(mesh);
        } else {
            tileUvs(face, drawn, height);

            const mesh = new THREE.Mesh(face, material);
            holder.add(mesh);
            targets.push(mesh);
        }

        group.add(holder);
        remember(edge.sector.slug, holder);

        return holder;
    };

    /** A floor or a ceiling: the sector's polygon, laid flat at a height. */
    const buildFlat = (
        sector: Sector,
        height: number,
        textureName: string | null,
        isWater: boolean,
    ): void => {
        const geometry = track(new THREE.ShapeGeometry(shapeOf(sector)));

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
                map: textures.water(),
                side: THREE.DoubleSide,
            });
            materials.push(material);
        } else {
            material = surfaceMaterial(textureName);

            if (material !== null) {
                tileFlatUvs(geometry);
            }
        }

        const holder = new THREE.Group();
        holder.position.y = height;
        holder.rotation.x = -Math.PI / 2;

        if (material === null) {
            const bounds = boundsOf([sector]);
            const mesh = new THREE.Mesh(geometry, backing(floorColor));
            const grid = track(
                gridGeometry(
                    bounds.maxX - bounds.minX,
                    bounds.maxZ - bounds.minZ,
                    GRID_SPACING,
                ),
            );
            const lineMesh = new THREE.LineSegments(grid, lines(floorColor));

            lineMesh.position.set(
                (bounds.minX + bounds.maxX) / 2,
                -(bounds.minZ + bounds.maxZ) / 2,
                0.004,
            );

            holder.add(mesh, lineMesh);
            targets.push(mesh);
        } else {
            const mesh = new THREE.Mesh(geometry, material);
            holder.add(mesh);
            targets.push(mesh);
        }

        // Tagged for the same reason walls are: an unpainted surface in a debug
        // picture can happen to land on a colour the legend already uses, and a
        // reading tool that occasionally names the wrong wall is worse than no
        // tool at all.
        holder.userData.flat = { sector: sector.slug, height };

        group.add(holder);
        remember(sector.slug, holder);
    };

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
    const buildSkyCeiling = (sector: Sector): void => {
        const geometry = track(new THREE.ShapeGeometry(shapeOf(sector)));
        const material = new THREE.MeshBasicMaterial({
            colorWrite: false,
            side: THREE.DoubleSide,
        });

        materials.push(material);

        const holder = new THREE.Group();
        holder.position.y = sector.ceilingHeight;
        holder.rotation.x = -Math.PI / 2;

        const mesh = new THREE.Mesh(geometry, material);
        mesh.renderOrder = SKY_CEILING_ORDER;

        holder.add(mesh);
        group.add(holder);
        skyLids.push({ mesh: holder, room: sector.slug });
    };

    for (const sector of level.sectors) {
        buildFlat(
            sector,
            sector.floorHeight,
            sector.floorTexture,
            sector.isWater,
        );

        if (sector.isSky) {
            buildSkyCeiling(sector);
        } else {
            buildFlat(
                sector,
                sector.ceilingHeight,
                sector.ceilingTexture,
                false,
            );
        }
    }

    // A portal only counts once both of its walls are there; half a portal stays
    // an ordinary wall rather than a hole to nowhere. Walls are counted rather
    // than faces, since a wall between two rooms has a face each way and both of
    // them are the same mouth.
    const portalWalls = new Map<string, Set<string>>();
    const mouths: Edge[] = [];

    for (const edge of edgesOf(level.sectors)) {
        const link = portalLinkOf(edge);

        if (link === null) {
            continue;
        }

        const walls = portalWalls.get(link) ?? new Set<string>();

        walls.add(boundaryKey(edge.from, edge.to));
        portalWalls.set(link, walls);
    }

    const portalEnds = (link: string): number =>
        portalWalls.get(link)?.size ?? 0;

    /**
     * A mirror: the same pane as a portal, except that the camera drawing it is
     * the viewpoint reflected in the wall rather than carried through it, and
     * the plane it is clipped against is the wall itself.
     *
     * The camera is built by looking from the reflected eye rather than by a
     * reflection matrix, because a matrix with a flip in it reverses the winding
     * of every triangle in the scene and turns one-sided surfaces inside out.
     */
    const buildMirrorPane = (
        edge: Edge,
        centre: THREE.Vector3,
        normal: THREE.Vector3,
        length: number,
        height: number,
    ): void => {
        const geometry = track(new THREE.PlaneGeometry(length, height));

        const eye = new THREE.Vector3();
        const at = new THREE.Vector3();
        const up = new THREE.Vector3();
        const ahead = new THREE.Vector3();

        /**
         * A point reflected in the wall: measured from the middle of it,
         * bounced off, and put back. Reflecting the offset is the whole of it —
         * turning it round as well would leave the point where it started.
         */
        const across = (
            point: THREE.Vector3,
            into: THREE.Vector3,
        ): THREE.Vector3 =>
            into.copy(point).sub(centre).reflect(normal).add(centre);

        /** The viewpoint's eye, reflected in the wall. */
        const reflectEye = (from: THREE.PerspectiveCamera): THREE.Vector3 =>
            across(eye.setFromMatrixPosition(from.matrixWorld), eye);

        const surface = createPortalSurface({
            geometry,
            aim: (from, out) => {
                const behind = reflectEye(from).clone();

                // Where the viewpoint is looking, reflected as well.
                ahead
                    .set(0, 0, -1)
                    .applyQuaternion(from.quaternion)
                    .add(from.position);

                across(ahead, at);

                up.set(0, 1, 0)
                    .applyQuaternion(from.quaternion)
                    .reflect(normal);

                out.position.copy(behind);
                out.up.copy(up);
                out.lookAt(at);
                out.updateMatrix();
                out.matrixWorld.copy(out.matrix);
                out.matrixWorldInverse.copy(out.matrixWorld).invert();
            },
            viewerAt: (from) => {
                const behind = reflectEye(from);

                ahead.set(0, 0, -1).applyQuaternion(from.quaternion);
                ahead.reflect(normal);

                return {
                    x: behind.x,
                    z: behind.z,
                    yaw: Math.atan2(-ahead.x, -ahead.z),
                };
            },
            readByFarCamera: true,
            tint: new THREE.Color(MIRROR_TINT),
            exitPoint: centre,
            exitNormal: normal,
            textureWidth: MIRROR_TEXTURE_WIDTH,
            textureHeight: MIRROR_TEXTURE_HEIGHT,
            bounces: PORTAL_BOUNCES,
            home: edge.sector.slug,
            onto: seenFrom(edge.sector.slug),
        });

        // A mirror cannot see itself: the camera stands behind it.
        surface.partner = surface.mesh;

        surface.mesh.position.copy(centre);
        surface.mesh.rotation.y = Math.atan2(normal.x, normal.z);
        surface.mesh.updateMatrixWorld(true);
        surface.settle();

        group.add(surface.mesh);
        targets.push(surface.mesh);

        geometry.computeBoundingSphere();
        surface.bounds
            .copy(geometry.boundingSphere as THREE.Sphere)
            .applyMatrix4(surface.mesh.matrixWorld);

        mirrors.push(surface);
    };

    /**
     * Every edge by the room and corner it starts at, for finding the walls
     * that meet the ends of a portal mouth.
     */
    const edgeAt = new Map<string, Edge>();

    for (const edge of edgesOf(level.sectors)) {
        edgeAt.set(`${edge.sector.slug}#${edge.index}`, edge);
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
    const trimOf = (mouth: Edge): { back: number; front: number } => {
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
    };

    /** The pane for one mouth, showing what stands beyond the other. */
    const buildPortalPane = (entry: Edge, exit: Edge): PortalSurface => {
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
        const trim = trimOf(entry);

        const geometry = track(
            new THREE.PlaneGeometry(
                near.length - trim.back - trim.front,
                height,
            ),
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
                out.matrixWorld.decompose(
                    out.position,
                    out.quaternion,
                    out.scale,
                );
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
            onto: seenFrom(exit.sector.slug),
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

        group.add(surface.mesh);
        targets.push(surface.mesh);

        geometry.computeBoundingSphere();
        surface.bounds
            .copy(geometry.boundingSphere as THREE.Sphere)
            .applyMatrix4(surface.mesh.matrixWorld);

        return surface;
    };

    /**
     * For each room, itself and whatever can be seen from it through an open
     * doorway. Anything standing in one of those can turn up in a view of that
     * room, and has to be drawn for it — a mirror through a doorway that never
     * gets redrawn shows a reflection that never moves.
     */
    const roomsSeenFrom = new Map<string, string[]>();

    for (const sector of level.sectors) {
        roomsSeenFrom.set(sector.slug, [sector.slug]);
    }

    for (const edge of edgesOf(level.sectors)) {
        const { sector, beyond } = edge;

        if (
            beyond === null ||
            edge.from.blocks ||
            (edge.beyondFrom?.blocks ?? false)
        ) {
            continue;
        }

        for (const [from, to] of [
            [sector.slug, beyond.slug],
            [beyond.slug, sector.slug],
        ]) {
            const seen = roomsSeenFrom.get(from);

            if (seen !== undefined && !seen.includes(to)) {
                seen.push(to);
            }
        }
    }

    const seenFrom = (slug: string): string[] =>
        roomsSeenFrom.get(slug) ?? [slug];

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
                edge,
                sector.floorHeight,
                Math.max(sector.ceilingHeight, beyond?.ceilingHeight ?? 0),
                texture,
            );

            colliders.push({
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
            buildWall(edge, sector.floorHeight, sector.ceilingHeight, texture);
            colliders.push({
                kind: 'segment',
                x1: edge.from.x,
                z1: edge.from.z,
                x2: edge.to.x,
                z2: edge.to.z,
            });

            continue;
        }

        // The step up to the next room, and the drop from its ceiling.
        buildWall(edge, sector.floorHeight, beyond.floorHeight, texture);

        if (!(sector.isSky && beyond.isSky)) {
            buildWall(
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
            colliders.push({
                kind: 'segment',
                x1: edge.from.x,
                z1: edge.from.z,
                x2: edge.to.x,
                z2: edge.to.z,
            });
        }
    }

    const thingLineMaterials = new Map<string, THREE.LineBasicMaterial>();
    const thingMaterials = new Map<string, THREE.MeshBasicMaterial>();

    for (const thing of level.things) {
        if (thing.kind === 'actor') {
            continue;
        }

        const box = track(
            new THREE.BoxGeometry(thing.width, thing.height, thing.depth),
        );

        const holder = new THREE.Group();
        holder.position.set(
            thing.x,
            thing.elevation + thing.height / 2,
            thing.z,
        );
        holder.rotation.y = -THREE.MathUtils.degToRad(thing.angle);

        const map = textures.surface(thing.texture);

        if (map === null) {
            const lineMaterial = new THREE.LineBasicMaterial({
                color: accentColor,
            });
            materials.push(lineMaterial);
            thingLineMaterials.set(thing.slug, lineMaterial);

            const edges = track(new THREE.EdgesGeometry(box));
            const mesh = new THREE.Mesh(box, backing(accentColor));
            mesh.userData.thingSlug = thing.slug;

            holder.add(mesh, new THREE.LineSegments(edges, lineMaterial));
            targets.push(mesh);
        } else {
            const material = new THREE.MeshBasicMaterial({ map });
            materials.push(material);
            thingMaterials.set(thing.slug, material);

            tileUvs(box, Math.max(thing.width, thing.depth), thing.height);

            const mesh = new THREE.Mesh(box, material);
            mesh.userData.thingSlug = thing.slug;

            holder.add(mesh);
            targets.push(mesh);
        }

        group.add(holder);

        if (thing.isSolid) {
            colliders.push({
                kind: 'box',
                x: thing.x,
                z: thing.z,
                halfWidth: thing.width / 2,
                halfDepth: thing.depth / 2,
                angle: THREE.MathUtils.degToRad(thing.angle),
            });
        }
    }

    /**
     * Fills each portal mouth with a pane showing the far mouth's room. Both
     * mouths have to be known first, since each one's pane is drawn by a camera
     * standing behind the other.
     */
    /**
     * What a pane looking through this mouth must not draw: whatever the room
     * behind the mouth put on the camera's side of it.
     *
     * Only what is on that side, give or take the hair every wall is drawn past
     * its own corners. A room that wraps properly back past the plane of its own
     * wall — a mouth set in a notch, say — has parts that genuinely show through
     * the opening, and those stay in.
     */
    const standingIn = (mouth: Edge): THREE.Object3D[] => {
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

        for (const sector of level.sectors) {
            if (sector.slug === mouth.sector.slug) {
                continue;
            }

            const meets = sector.points.some(
                (point) =>
                    sameSpot(point, mouth.from) || sameSpot(point, mouth.to),
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
            (slug) => drawnByRoom.get(slug) ?? [],
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
    };

    const buildPortals = (found: Edge[]): void => {
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
                const surface = buildPortalPane(entry, exit);

                portals.push(surface);
                made.push(surface);
            }

            // Each pane is drawn by a camera standing behind the other one, in
            // the room whose wall closes that mouth off.
            made[0].partner = made[1].mesh;
            made[1].partner = made[0].mesh;

            made[0].behind = standingIn(pair[1]);
            made[1].behind = standingIn(pair[0]);
        }
    };

    const highlight = (slug: string | null): void => {
        for (const [candidate, material] of thingLineMaterials) {
            material.color.set(
                candidate === slug ? HIGHLIGHT_COLOR : accentColor,
            );
        }

        for (const [candidate, material] of thingMaterials) {
            material.color.set(candidate === slug ? '#ffd9a0' : '#ffffff');
        }
    };

    const dispose = (): void => {
        for (const mirror of mirrors) {
            mirror.dispose();
        }

        for (const portal of portals) {
            portal.dispose();
        }

        for (const geometry of geometries) {
            geometry.dispose();
        }

        for (const material of materials) {
            material.dispose();
        }
    };

    buildPortals(mouths);

    return {
        group,
        colliders,
        targets,
        mirrors,
        portals,
        skyLids,
        highlight,
        dispose,
    };
}

/** Things the engine draws as sprites rather than boxes. */
export function actorsOf(level: Level): LevelThing[] {
    return level.things.filter((thing) => thing.kind === 'actor');
}
