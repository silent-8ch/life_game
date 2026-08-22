import * as THREE from 'three';
import { HIGHLIGHT_COLOR } from '@/lib/engine/build/constants';
import type { BuildContext } from '@/lib/engine/build/context';
import { tileUvs } from '@/lib/engine/textures';
import type { LevelThing } from '@/types';

/**
 * Everything on the plan that is not a room and not a person.
 *
 * Three ways of drawing one:
 *
 * - `box` is what everything was before there were props: six faces of the
 *   thing's own size. Its `uvMode` decides whether the picture tiles across
 *   them or fits each of them exactly once.
 * - `billboard` is one quad turned to face whoever is looking. About y only —
 *   a prop leaning back as you look up reads as a bug rather than as a trick.
 * - `cross` is two or three quads standing in each other, evenly spaced and
 *   turned by the thing's own angle. Nothing moves at runtime, which is why a
 *   plant costs nothing across forty portal passes.
 */

/** How long a cutout pixel has to be to be drawn at all. */
const CUTOUT_ALPHA = 0.5;

/** The colour a looked-at thing's picture is washed with. */
const LOOKED_AT = '#ffd9a0';

export type PropSet = {
    /**
     * Turns every billboard to face a viewpoint.
     *
     * Must be called for **whichever camera is about to draw**, not once for
     * the player. The pane passes run with cameras somewhere else entirely, and
     * a billboard parked facing the player is edge-on or backwards in every
     * mirror and every portal. This is the same trap `.ai/rules/game.md`
     * records for the sky dome, and it is the reason billboards are turned in
     * `drawPane` alongside `sky.follow` rather than in the player's step.
     */
    faceViewer: (x: number, z: number) => void;
    /** Advances anything that animates. */
    update: (seconds: number) => void;
    /**
     * Which game flags are set, deciding which things show their alt picture.
     *
     * A light switch is a thing with `texture` off, `textureAlt` on and an
     * `altFlag` naming the flag its Use interaction sets. The flag lives on the
     * saved game, so a flipped switch survives a reload.
     */
    setFlags: (flags: ReadonlySet<string>) => void;
    highlight: (slug: string | null) => void;
};

/** Which picture a thing is showing: its own, or the one its flag turns on. */
function textureFor(
    thing: LevelThing,
    flags: ReadonlySet<string>,
): string | null {
    if (
        thing.altFlag !== null &&
        thing.textureAlt !== null &&
        flags.has(thing.altFlag)
    ) {
        return thing.textureAlt;
    }

    return thing.texture;
}

/**
 * The quads a cross prop is made of: evenly spaced about y, turned by the
 * thing's own angle.
 *
 * Two planes give 0° and 90°, three give 0°, 60° and 120°. Spacing over a half
 * turn rather than a whole one, because a quad drawn both sides is the same
 * quad turned round.
 */
function crossAngles(planes: number, angle: number): number[] {
    const count = planes === 3 ? 3 : 2;

    return Array.from(
        { length: count },
        (_, at) => angle + (at * Math.PI) / count,
    );
}

export function buildThings(ctx: BuildContext): PropSet {
    const { level, scene, materials, textures } = ctx;

    const thingLineMaterials = new Map<string, THREE.LineBasicMaterial>();
    const thingMaterials = new Map<string, THREE.MeshBasicMaterial>();

    /** Every billboard, so they can all be turned at once. */
    const billboards: THREE.Object3D[] = [];

    /** Everything that animates, with what it is showing and how fast. */
    const animated: {
        thing: LevelThing;
        material: THREE.MeshBasicMaterial;
        elapsed: number;
        frame: number;
    }[] = [];

    /** Everything whose picture depends on a flag. */
    const flagged: { thing: LevelThing; material: THREE.MeshBasicMaterial }[] =
        [];

    let flags: ReadonlySet<string> = new Set<string>();

    /**
     * A prop's picture.
     *
     * Cut out rather than blended: alpha testing writes depth and needs no
     * sorting, where blending would have every prop sorted against every other
     * one and against the portal panes — a class of bug not worth inviting for
     * a leaf. Drawn on both sides, because a cross and a billboard are both
     * seen from behind.
     */
    const cutout = (map: THREE.Texture): THREE.MeshBasicMaterial =>
        materials.keep(
            new THREE.MeshBasicMaterial({
                map,
                transparent: false,
                alphaTest: CUTOUT_ALPHA,
                side: THREE.DoubleSide,
            }),
        );

    /**
     * Which picture a thing is drawn with, and where it comes from.
     *
     * `uvMode` decides both. A thing that tiles is wearing one of the seamless
     * surface textures; a thing that fits its frame exactly once is wearing a
     * prop, which carries a silhouette. That is the same distinction as the two
     * folders, so it is the one the engine reads — and it is what stops a box
     * being drawn opaque over art with holes in it, which would show the holes
     * as solid colour.
     */
    const pictureFor = (
        thing: LevelThing,
    ): { map: THREE.Texture | null; cut: boolean } => {
        const name = textureFor(thing, flags);

        if (thing.render === 'box' && thing.uvMode === 'tile') {
            return { map: textures.surface(name), cut: false };
        }

        return {
            map: textures.prop(name, thing.animationFrames > 1 ? 1 : undefined),
            cut: true,
        };
    };

    for (const thing of level.things) {
        if (thing.kind === 'actor') {
            continue;
        }

        const holder = new THREE.Group();
        holder.position.set(
            thing.x,
            thing.elevation + thing.height / 2,
            thing.z,
        );
        holder.rotation.y = -THREE.MathUtils.degToRad(thing.angle);

        const { map, cut } = pictureFor(thing);

        if (map === null) {
            // No picture: the wireframe box it always fell back to, whatever
            // the thing thinks it is.
            const box = materials.track(
                new THREE.BoxGeometry(thing.width, thing.height, thing.depth),
            );
            const lineMaterial = materials.keep(
                new THREE.LineBasicMaterial({ color: materials.accentColor }),
            );
            thingLineMaterials.set(thing.slug, lineMaterial);

            const edges = materials.track(new THREE.EdgesGeometry(box));
            const mesh = new THREE.Mesh(
                box,
                materials.backing(materials.accentColor),
            );
            mesh.userData.thingSlug = thing.slug;

            holder.add(mesh, new THREE.LineSegments(edges, lineMaterial));
            scene.targets.push(mesh);
        } else if (thing.render === 'box') {
            const box = materials.track(
                new THREE.BoxGeometry(thing.width, thing.height, thing.depth),
            );
            const material = cut
                ? cutout(map)
                : materials.keep(new THREE.MeshBasicMaterial({ map }));

            thingMaterials.set(thing.slug, material);

            // A box comes out of three with UVs already running 0..1 across
            // each face, which is exactly what `fit` means. Only tiling has
            // anything to do.
            if (thing.uvMode === 'tile') {
                tileUvs(box, Math.max(thing.width, thing.depth), thing.height);
            }

            const mesh = new THREE.Mesh(box, material);
            mesh.userData.thingSlug = thing.slug;

            holder.add(mesh);
            scene.targets.push(mesh);
        } else {
            const material = cutout(map);
            thingMaterials.set(thing.slug, material);

            const angles =
                thing.render === 'cross'
                    ? crossAngles(thing.planeCount, 0)
                    : [0];

            for (const turn of angles) {
                const quad = materials.track(
                    new THREE.PlaneGeometry(thing.width, thing.height),
                );
                const mesh = new THREE.Mesh(quad, material);

                mesh.rotation.y = turn;
                mesh.userData.thingSlug = thing.slug;

                holder.add(mesh);
                scene.targets.push(mesh);
            }

            if (thing.render === 'billboard') {
                // Turned every pass rather than built facing anywhere, so the
                // holder's own angle is not what decides where it looks.
                billboards.push(holder);
            }
        }

        if (thing.animationFrames > 1) {
            const material = thingMaterials.get(thing.slug);

            if (material !== undefined) {
                animated.push({ thing, material, elapsed: 0, frame: 1 });
            }
        }

        if (thing.altFlag !== null && thing.textureAlt !== null) {
            const material = thingMaterials.get(thing.slug);

            if (material !== undefined) {
                flagged.push({ thing, material });
            }
        }

        scene.group.add(holder);

        if (thing.isSolid) {
            scene.colliders.push({
                kind: 'box',
                x: thing.x,
                z: thing.z,
                halfWidth: thing.width / 2,
                halfDepth: thing.depth / 2,
                angle: THREE.MathUtils.degToRad(thing.angle),
            });
        }
    }

    const faceViewer = (x: number, z: number): void => {
        for (const holder of billboards) {
            // About y only. Pitching a prop towards a camera looking down at it
            // makes it lean, and a leaning plant reads as broken geometry.
            holder.rotation.y = Math.atan2(
                x - holder.position.x,
                z - holder.position.z,
            );
        }
    };

    const update = (seconds: number): void => {
        for (const running of animated) {
            const each = 1 / Math.max(running.thing.animationFps, 0.01);

            running.elapsed += seconds;

            while (running.elapsed >= each) {
                running.elapsed -= each;
                running.frame =
                    (running.frame % running.thing.animationFrames) + 1;

                const map = textures.prop(
                    textureFor(running.thing, flags),
                    running.frame,
                );

                if (map !== null) {
                    running.material.map = map;
                    running.material.needsUpdate = true;
                }
            }
        }
    };

    const setFlags = (next: ReadonlySet<string>): void => {
        flags = next;

        for (const { thing, material } of flagged) {
            const map = textures.prop(
                textureFor(thing, flags),
                thing.animationFrames > 1 ? 1 : undefined,
            );

            if (map !== null && material.map !== map) {
                material.map = map;
                material.needsUpdate = true;
            }
        }
    };

    const highlight = (slug: string | null): void => {
        for (const [candidate, material] of thingLineMaterials) {
            material.color.set(
                candidate === slug ? HIGHLIGHT_COLOR : materials.accentColor,
            );
        }

        for (const [candidate, material] of thingMaterials) {
            material.color.set(candidate === slug ? LOOKED_AT : '#ffffff');
        }
    };

    return { faceViewer, update, setFlags, highlight };
}
