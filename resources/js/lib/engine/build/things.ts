import * as THREE from 'three';
import { HIGHLIGHT_COLOR } from '@/lib/engine/build/constants';
import type { BuildContext } from '@/lib/engine/build/context';
import type { BoxCollider } from '@/lib/engine/collision';
import { sectorAt } from '@/lib/engine/sectors';
import { tileUvs } from '@/lib/engine/textures';
import type { LevelThing } from '@/types';

/**
 * Everything on the plan that is not a room and not a person.
 *
 * Four ways of drawing one:
 *
 * - `box` is what everything was before there were props: six faces of the
 *   thing's own size. Its `uvMode` decides whether the picture tiles across
 *   them or fits each of them exactly once.
 * - `billboard` is one quad turned to face whoever is looking. About y only —
 *   a prop leaning back as you look up reads as a bug rather than as a trick.
 * - `cross` is two or three quads standing in each other, evenly spaced and
 *   turned by the thing's own angle. Nothing moves at runtime, which is why a
 *   plant costs nothing across forty portal passes.
 * - `flat` is one quad at the thing's own angle, which never turns. A window, a
 *   picture, a sign, a door.
 *
 * `flat` is a billboard that is not told to face anybody, and that is the whole
 * of it in code — one quad, the same cutout material, left in the holder the
 * thing's `angle` already turns. It is called out rather than left to fall
 * through the same branch by accident, because *not being in the list of things
 * that turn* is the entire behaviour and a reader should not have to notice its
 * absence to find that out.
 *
 * Both sides come free and so does the mirroring: props are drawn
 * `DoubleSide`, and the back face of a quad shows its own UVs the other way
 * round, so the back is the front flipped. Paul's ruling for a door, arrived at
 * by not doing anything.
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
    /** Everything an interaction can move, and how to move it. */
    moving: MovedThings;
};

export type MovedThings = {
    /** Whether there is a thing by this name that anything can move. */
    has: (slug: string) => boolean;
    /** How far it is turned about its hinge, in degrees. */
    turnOf: (slug: string) => number;
    /** Whether it is stopping anybody walking through it. */
    blocking: (slug: string) => boolean;
    /**
     * Turns a thing about its hinge, to an angle rather than by one.
     *
     * Absolute so that firing it twice leaves the thing where firing it once
     * did: a door you Use twice should be open, not open twice. It eases there
     * rather than snapping, because a door that changes angle between two
     * frames reads as a glitch and not as a door.
     */
    turn: (slug: string, degrees: number) => boolean;
    /**
     * Puts a thing's collider in or out of the set, **on this call**.
     *
     * Never tied to how far the animation has got. A collider that waited for
     * the swing can close on somebody standing in the doorway and leave them
     * inside it, which is a far worse fault than a doorway that is walkable a
     * few frames early. `plan-doors.md` called this out when a door was still a
     * kind of its own, and it survives the redesign unchanged because it was
     * never about doors.
     */
    block: (slug: string, blocking: boolean) => boolean;
    /**
     * Slides a thing, in metres from where it was drawn.
     *
     * Relative to the drawn spot rather than to wherever it currently is, for
     * the same reason `turn` is absolute: firing it twice must leave the thing
     * where firing it once did. It eases there, like the swing does.
     */
    move: (slug: string, x: number, z: number, up: number) => boolean;
    /**
     * Shows a named picture, or the one the thing was drawn with when empty.
     *
     * A name rather than a yes or a no. It was the latter at first, reusing the
     * `textureAlt` a light switch already carries — but that made the editor
     * offer a tick box where the thing being chosen is a picture, and left the
     * picture itself to be set somewhere else entirely. Paul: *selected shows
     * alt pic, should see a texture picker.*
     */
    swap: (slug: string, texture: string) => boolean;
    /** Whether it is drawn. Collision is `block`, and deliberately separate. */
    show: (slug: string, shown: boolean) => boolean;
    /** What a save should be told, for everything anything has moved. */
    moved: () => Record<string, { turned: number; blocking: boolean }>;
};

/**
 * A thing something can move, and where it has got to.
 *
 * There is no door here and that is the design. Paul: *a door is just a solid
 * sprite that has a hinge with an action.* A door is a flat thing hinged on one
 * side whose `Use` turns it ninety degrees and stops it blocking — authored in
 * the interaction panel like any other interaction, out of parts that never
 * have to know they are making a door. A drawbridge hinges at the bottom, a
 * hatch at the top, a window that swings out is the same with other numbers.
 *
 * It replaced a `Door` with a `swing`, an `openAngle`, an `openSeconds` and an
 * `isOpen`, which between them could make exactly one thing.
 */
type Movable = {
    thing: LevelThing;
    /** The part that moves. The holder stays put; this turns inside it. */
    leaf: THREE.Group;
    /** Whether the hinge turns it about y or about x. */
    swings: boolean;
    /** Null for a thing with `isSolid` off, which has nothing to switch. */
    collider: BoxCollider | null;
    /** Where it is going, in degrees. */
    want: number;
    holder: THREE.Object3D;
    /** Where its hinge sits along its own width, in metres from the middle. */
    hingeX: number;
    /** The angle it was drawn at, in radians. */
    drawnAngle: number;
    /** Where it has been slid to, and where it is heading, from where drawn. */
    shifted: THREE.Vector3;
    heading: THREE.Vector3;
    /** Where it was drawn, so an offset is always measured from the same spot. */
    drawnAt: THREE.Vector3;
    /** Where it has got to. */
    at: number;
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

/** How fast a hinged thing swings, in degrees a second. */
const TURN_RATE = 220;

/** How fast a thing slides when a binding moves it, in metres a second. */
const SLIDE_RATE = 1.6;

/**
 * How thick a flat thing is to walk into, in metres.
 *
 * Paul: *the clipping test for a flat image should be the image itself.* A
 * picture has no depth, so its `depth` is only ever what somebody typed, and a
 * door drawn 0.8m deep stopped you 40cm short of it on both sides. Not nought,
 * because a box with no thickness is one a fast walker can step over in a
 * single frame; five centimetres is thin enough to read as the picture and
 * thick enough to catch anybody.
 */
const FLAT_DEPTH = 0.05;

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

    /** Everything that opens, by slug. */
    const movable = new Map<string, Movable>();

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

        // A hinged thing turns about an edge rather than about its middle, or
        // it reads as a revolving door. The holder stays where the thing was
        // authored and a leaf hangs inside it, moved out to the hinge with its
        // picture moved back in by the same half — so the thing is exactly
        // where it was drawn while it is shut, and turns about the right line
        // when something opens it.
        //
        // Four edges, because a hinge is authored rather than assumed. A door
        // hinges at a side, a drawbridge at the bottom, a hatch at the top; the
        // engine does not need to know which of those it is holding, which is
        // the whole point of the hinge being a property of the thing.
        // Read defensively rather than by type alone, the same way the slope
        // fields are. The column is new, so a payload written before it existed
        // carries no value for it — and `undefined !== null` is true, which
        // would make every thing in every old level hinged on nothing and hang
        // its picture off a leaf that never turns.
        const leaf = new THREE.Group();
        const hinge = thing.hinge ?? null;
        const hinged = hinge !== null;
        const swings = hinge === 'left' || hinge === 'right';

        if (hinged) {
            leaf.position.x =
                hinge === 'left'
                    ? -thing.width / 2
                    : hinge === 'right'
                      ? thing.width / 2
                      : 0;
            leaf.position.y =
                hinge === 'bottom'
                    ? -thing.height / 2
                    : hinge === 'top'
                      ? thing.height / 2
                      : 0;

            holder.add(leaf);
        }

        /** Where the picture sits inside the leaf: back where it was drawn. */
        const offset = {
            x: -leaf.position.x,
            y: -leaf.position.y,
        };

        // Everything a thing is drawn with goes on the part that moves, which
        // for anything unhinged is the thing itself.
        const parent = hinged ? leaf : holder;

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

            mesh.position.set(offset.x, offset.y, 0);
            parent.add(mesh, new THREE.LineSegments(edges, lineMaterial));
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
            mesh.position.set(offset.x, offset.y, 0);

            parent.add(mesh);
            scene.targets.push(mesh);
        } else {
            const material = cutout(map);
            thingMaterials.set(thing.slug, material);

            // A cross is a star of them; a billboard and a flat are one each,
            // and differ only in whether anybody turns them afterwards.
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
                mesh.position.set(offset.x, offset.y, 0);

                parent.add(mesh);
                scene.targets.push(mesh);
            }

            if (thing.render === 'billboard') {
                // Turned every pass rather than built facing anywhere, so the
                // holder's own angle is not what decides where it looks. A
                // `flat` is the same quad left out of this list, which is what
                // makes its angle mean something.
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

        // Anything standing in a room the camera sees through is not drawn
        // either. Settled here rather than every frame because a thing does not
        // move — a person does, and `actors.ts` asks the same question of them
        // as they walk.
        holder.visible =
            sectorAt(level.sectors, thing.x, thing.z)?.isInvisible !== true;

        scene.group.add(holder);

        const collider: BoxCollider | null = thing.isSolid
            ? {
                  kind: 'box',
                  x: thing.x,
                  z: thing.z,
                  halfWidth: thing.width / 2,
                  // A flat thing is a picture, and a picture has no depth. Its
                  // authored one is only ever what somebody typed.
                  halfDepth:
                      thing.render === 'flat'
                          ? FLAT_DEPTH / 2
                          : thing.depth / 2,
                  angle: THREE.MathUtils.degToRad(thing.angle),
                  slug: thing.slug,
              }
            : null;

        if (collider !== null) {
            scene.colliders.push(collider);
        }

        // Anything with a hinge can be moved, whatever it was drawn as and
        // whatever the author had in mind for it.
        if (hinged) {
            movable.set(thing.slug, {
                thing,
                holder,
                leaf,
                swings,
                collider,
                hingeX: leaf.position.x,
                drawnAngle: THREE.MathUtils.degToRad(thing.angle),
                want: 0,
                at: 0,
                shifted: new THREE.Vector3(),
                heading: new THREE.Vector3(),
                drawnAt: holder.position.clone(),
            });
        }
    }

    /**
     * Puts a hinged thing's footprint where its picture has got to.
     *
     * Paul: *it still blocks the way when it is open.* The collider was built
     * once from the angle the thing was drawn at and never moved again — only
     * switched on and off — so a door swung flat against the wall went on
     * filling the doorway. Nothing about it was wrong except that it stayed
     * still.
     *
     * Worked out from the swing rather than read off the leaf, because the
     * collider lives on the floor plan in two dimensions and the leaf lives in
     * the scene graph in three. The leaf sits on the hinge edge and the picture
     * hangs back off it, so the picture's middle swings round the hinge on a
     * circle of half the thing's width.
     *
     * `holder.rotation.y` is the negative of the drawn angle while the
     * collider's is the angle itself, so the two turns subtract rather than
     * add. That sign is the whole of what makes an open door stop blocking the
     * doorway instead of blocking a different part of it.
     *
     * Only side hinges move the footprint. A hatch or a drawbridge turns about
     * a horizontal edge, so it leaves the floor rather than sweeping across it,
     * and what it covers on the plan is very nearly what it covered shut.
     */
    const settleFootprint = (moving: Movable): void => {
        const collider = moving.collider;

        if (collider === null) {
            return;
        }

        const drawn = moving.drawnAngle;

        if (!moving.swings) {
            collider.x = moving.thing.x + moving.shifted.x;
            collider.z = moving.thing.z + moving.shifted.z;
            collider.angle = drawn;

            return;
        }

        const turned = THREE.MathUtils.degToRad(moving.at);
        const hinge = moving.hingeX;

        // The picture's middle, in the holder's own frame: it starts over the
        // holder's origin and swings round the hinge as the leaf turns.
        const localX = hinge * (1 - Math.cos(turned));
        const localZ = hinge * Math.sin(turned);

        const cos = Math.cos(drawn);
        const sin = Math.sin(drawn);

        collider.x =
            moving.thing.x + localX * cos - localZ * sin + moving.shifted.x;
        collider.z =
            moving.thing.z + localX * sin + localZ * cos + moving.shifted.z;
        collider.angle = drawn - turned;
    };

    /** Puts a hinged thing where it has got to, this frame. */
    const place = (moving: Movable): void => {
        const turned = THREE.MathUtils.degToRad(moving.at);

        // A side hinge turns about the upright; a top or bottom one turns about
        // the edge itself, which is horizontal. The leaf is already sitting on
        // whichever edge it is, so the axis is the whole of the difference.
        moving.leaf.rotation.set(
            moving.swings ? 0 : turned,
            moving.swings ? turned : 0,
            0,
        );

        settleFootprint(moving);
    };

    for (const moving of movable.values()) {
        place(moving);
    }

    const moved: MovedThings = {
        has: (slug) => movable.has(slug),

        turnOf: (slug) => movable.get(slug)?.want ?? 0,

        blocking: (slug) => {
            const collider = movable.get(slug)?.collider;

            return collider !== null && collider !== undefined
                ? collider.enabled !== false
                : false;
        },

        turn: (slug, degrees) => {
            const moving = movable.get(slug);

            if (moving === undefined || moving.want === degrees) {
                return false;
            }

            moving.want = degrees;

            return true;
        },

        block: (slug, blocking) => {
            const moving = movable.get(slug);

            if (moving === undefined || moving.collider === null) {
                return false;
            }

            // On this call, not when the swing catches up.
            moving.collider.enabled = blocking;

            return true;
        },

        move: (slug, x, z, up) => {
            const moving = movable.get(slug);

            if (moving === undefined) {
                return false;
            }

            if (
                moving.heading.x === x &&
                moving.heading.y === up &&
                moving.heading.z === z
            ) {
                return false;
            }

            moving.heading.set(x, up, z);

            return true;
        },

        swap: (slug, texture) => {
            const moving = movable.get(slug);
            const material = thingMaterials.get(slug);

            if (moving === undefined || material === undefined) {
                return false;
            }

            // Empty means the picture it was drawn with, which is what makes
            // an `off` value of nothing read as *put it back*.
            const map = textures.prop(
                texture === '' ? moving.thing.texture : texture,
                1,
            );

            if (map === null || material.map === map) {
                return false;
            }

            material.map = map;
            material.needsUpdate = true;

            return true;
        },

        show: (slug, shown) => {
            const moving = movable.get(slug);

            if (moving === undefined || moving.holder.visible === shown) {
                return false;
            }

            moving.holder.visible = shown;

            return true;
        },

        moved: () =>
            Object.fromEntries(
                [...movable.entries()].map(([slug, moving]) => [
                    slug,
                    {
                        turned: moving.want,
                        blocking: moving.collider?.enabled !== false,
                    },
                ]),
            ),
    };

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
        for (const moving of movable.values()) {
            if (moving.at === moving.want) {
                continue;
            }

            // A rate rather than a duration, so a thing told to turn ten
            // degrees takes a tenth as long as one told to turn a hundred, and
            // one caught halfway and sent back takes half of what it had left.
            // A duration would make a nudge and a full swing take the same
            // time, which reads as two different mechanisms.
            const step = TURN_RATE * seconds;

            moving.at =
                moving.want > moving.at
                    ? Math.min(moving.want, moving.at + step)
                    : Math.max(moving.want, moving.at - step);

            place(moving);
        }

        // Sliding, eased the same way and for the same reason: a thing that
        // changes place between two frames reads as a glitch, not as a door.
        for (const moving of movable.values()) {
            if (moving.shifted.equals(moving.heading)) {
                continue;
            }

            const step = SLIDE_RATE * seconds;
            const left = moving.heading.distanceTo(moving.shifted);

            if (left <= step) {
                moving.shifted.copy(moving.heading);
            } else {
                moving.shifted.addScaledVector(
                    moving.heading
                        .clone()
                        .sub(moving.shifted)
                        .divideScalar(left),
                    step,
                );
            }

            moving.holder.position.copy(moving.drawnAt).add(moving.shifted);
            settleFootprint(moving);
        }

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

    return { faceViewer, update, setFlags, highlight, moving: moved };
}
