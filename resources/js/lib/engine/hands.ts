import * as THREE from 'three';

/**
 * The player's own hands, held at the bottom of the view, and whatever they
 * are holding.
 *
 * Two cards hung off the camera. One of them is the drawing as it was made and
 * the other is that drawing turned round, so a person needs two pictures rather
 * than four. Both are seen edge on, the way your own hands are as they swing:
 * flat with the fingers straight while walking, a fist while running or while
 * gripping something.
 *
 * They swing on the same tally of metres walked that picks the sprite's walk
 * frame, so the hands and the body a mirror shows are always in step.
 */

const HANDS_PATH = '/sprites/hands';

/**
 * A file per pose rather than cells on a sheet, so another pose is another file
 * and nothing depends on the order they were cut in.
 */
const POSES = { walk: 'edge-open', run: 'edge' } as const;

type Pose = keyof typeof POSES;

/** How wide a card is, in metres, held at arm's length. */
const HAND_SIZE = 0.42;

/** How far in front of the eye they are held, and how far out and down. */
const HAND_REACH = 0.6;
const HAND_SPREAD = 0.26;
const HAND_DROP = 0.3;

/** One full swing of the arms, in metres walked. */
const STRIDE = 1.1;

/** How far the hands rise and fall over a stride, in metres. */
const SWING = 0.045;

/** How quickly a hand opens or closes when the pace changes. */
const CLENCH_RATE = 9;

/** How long an item takes to come up or go down, in seconds. */
const RAISE_RATE = 7;

/**
 * Which side of the screen each drawing belongs on. 1 is the right of the view,
 * -1 the left; the other hand is the same picture turned round.
 *
 * The thumb ends up on the outside. Edge on you are seeing the back of the hand,
 * with the thumb away from you and the little finger nearest — so it reads the
 * other way to a palm held up, which is what made the first guess backwards.
 *
 * There is an entry per pose because the art does not agree with itself. The
 * files were generated a person and a pose at a time, and Paul's and Wade's
 * fists came back facing the opposite way to their own open hands. Get it wrong
 * and both hands are wrong at once, since they are mirror images.
 *
 * Read off the artwork by measuring which side carries the finger outlines; the
 * thumb is the other one. An edge-on hand hides most of its thumb, so treat
 * these as the first thing to suspect if a hand looks inside out.
 */
const DRAWN: Record<string, Record<Pose, 1 | -1>> = {
    paul: { walk: 1, run: -1 },
    krystal: { walk: 1, run: 1 },
    luna: { walk: 1, run: 1 },
    wade: { walk: 1, run: -1 },
    luke: { walk: 1, run: 1 },
    william: { walk: -1, run: -1 },
};

/** What to assume for a person nobody has measured: the commoner of the two. */
const DRAWN_BY_DEFAULT: Record<Pose, 1 | -1> = { walk: 1, run: 1 };

export type HeldItem = 'wand' | 'pistol' | 'tablet' | 'phone';

export const HELD_ITEMS: HeldItem[] = ['wand', 'pistol', 'tablet', 'phone'];

/**
 * Where each item sits in the right hand, and how big. The art is a plain
 * side-on picture of the thing rather than a picture of it being held, so it
 * has to be placed against the fist by hand. These are the numbers to change
 * when one of them does not look gripped.
 *
 * `x` and `y` are metres from the hand it belongs to, `turn` is radians.
 */
const HOLD: Record<
    HeldItem,
    { x: number; y: number; size: number; turn: number }
> = {
    // Held up and angled back, the way you would carry a wand rather than
    // point it — the art already runs corner to corner.
    wand: { x: -0.02, y: 0.13, size: 0.62, turn: -0.35 },
    // The grip is low and left in the picture, so it sits low and left of the
    // fist for the fist to be around it.
    pistol: { x: -0.05, y: 0.09, size: 0.48, turn: -0.12 },
    // Both of these are held flat and up, to be looked at.
    tablet: { x: -0.12, y: 0.1, size: 0.62, turn: -0.18 },
    phone: { x: -0.06, y: 0.11, size: 0.42, turn: -0.16 },
};

export type Hands = {
    /** Hung off the camera, so it goes wherever the view goes. */
    object: THREE.Object3D;
    /** What is in the right hand, or null for nothing. */
    hold: (item: HeldItem | null) => void;
    holding: () => HeldItem | null;
    /**
     * @param  walked  Metres walked so far, for the swing.
     * @param  running  Whether the fists should be up.
     */
    update: (seconds: number, walked: number, running: boolean) => void;
    dispose: () => void;
};

function load(url: string): THREE.Texture {
    const texture = new THREE.TextureLoader().load(url);

    texture.colorSpace = THREE.SRGBColorSpace;
    texture.magFilter = THREE.NearestFilter;
    texture.minFilter = THREE.NearestFilter;

    return texture;
}

/**
 * @param  sprite  Whose hands they are. Each person has drawings of their own.
 */
export function createHands(sprite: string): Hands {
    const object = new THREE.Group();

    const drawing: Record<Pose, THREE.Texture> = {
        walk: load(`${HANDS_PATH}/${sprite}-${POSES.walk}.png`),
        run: load(`${HANDS_PATH}/${sprite}-${POSES.run}.png`),
    };

    const geometry = new THREE.PlaneGeometry(HAND_SIZE, HAND_SIZE);
    const drawn = DRAWN[sprite] ?? DRAWN_BY_DEFAULT;

    /** Whether this side shows the drawing as it was made, or turned round. */
    const facing = (side: number, pose: Pose): 1 | -1 =>
        side === drawn[pose] ? 1 : -1;

    const sides = [-1, 1].map((side) => {
        const material = new THREE.MeshBasicMaterial({
            map: drawing.walk,
            transparent: true,
            alphaTest: 0.4,
            depthTest: false,
            fog: false,
            side: THREE.DoubleSide,
        });

        const mesh = new THREE.Mesh(geometry, material);

        mesh.position.set(HAND_SPREAD * side, -HAND_DROP, -HAND_REACH);

        // Turning the card rather than the picture keeps it pixel for pixel the
        // same. Which way it is turned depends on the pose, so it is settled
        // again every frame rather than once here.
        mesh.scale.x = facing(side, 'walk');
        mesh.rotation.z = 0.14 * -side;

        // Nothing in the world may draw over them.
        mesh.renderOrder = 1000;
        mesh.frustumCulled = false;

        object.add(mesh);

        return { mesh, material, side, rest: mesh.position.y };
    });

    const right = sides[1];

    // Every item is loaded once and kept, so swapping between them is instant.
    const items = new Map<HeldItem, THREE.Mesh>();
    const itemGeometry = new THREE.PlaneGeometry(1, 1);

    for (const name of HELD_ITEMS) {
        const material = new THREE.MeshBasicMaterial({
            map: load(`${HANDS_PATH}/overlays/${name}.png`),
            transparent: true,
            alphaTest: 0.4,
            depthTest: false,
            fog: false,
            side: THREE.DoubleSide,
        });

        const mesh = new THREE.Mesh(itemGeometry, material);
        const place = HOLD[name];

        mesh.scale.setScalar(place.size);
        mesh.rotation.z = place.turn;

        // Under the hand, so the fist reads as being around it.
        mesh.renderOrder = 999;
        mesh.frustumCulled = false;
        mesh.visible = false;

        object.add(mesh);
        items.set(name, mesh);
    }

    let held: HeldItem | null = null;
    let clenched = 0;
    let raised = 0;

    return {
        object,

        hold: (item) => {
            held = item;
        },

        holding: () => held,

        update: (seconds, walked, running) => {
            // A hand holding something is shut around it whatever the pace.
            const wanted = running || held !== null ? 1 : 0;

            clenched +=
                (wanted - clenched) * Math.min(1, CLENCH_RATE * seconds);
            raised +=
                ((held === null ? 0 : 1) - raised) *
                Math.min(1, RAISE_RATE * seconds);

            const pose: Pose = clenched > 0.5 ? 'run' : 'walk';
            const shown = drawing[pose];

            for (const hand of sides) {
                if (hand.material.map !== shown) {
                    hand.material.map = shown;
                    hand.material.needsUpdate = true;
                }

                hand.mesh.scale.x = facing(hand.side, pose);

                // The two arms swing opposite each other, the way they do.
                const swing = Math.sin(
                    (walked / STRIDE) * Math.PI * 2 +
                        (hand.side > 0 ? Math.PI : 0),
                );

                hand.mesh.position.y = hand.rest + swing * SWING;

                // Running brings them up and in as well as shutting them.
                hand.mesh.position.x =
                    HAND_SPREAD * hand.side * (1 - clenched * 0.16);
                hand.mesh.position.z = -HAND_REACH + clenched * 0.05;
                hand.mesh.rotation.z =
                    0.14 * -hand.side + swing * 0.04 * hand.side;
            }

            for (const [name, mesh] of items) {
                mesh.visible = name === held && raised > 0.01;

                if (!mesh.visible) {
                    continue;
                }

                const place = HOLD[name];

                // Rides with the right hand, and comes up from below rather
                // than appearing all at once.
                mesh.position.set(
                    right.mesh.position.x + place.x,
                    right.mesh.position.y + place.y - (1 - raised) * 0.4,
                    right.mesh.position.z - 0.01,
                );
            }
        },

        dispose: () => {
            geometry.dispose();
            itemGeometry.dispose();
            drawing.walk.dispose();
            drawing.run.dispose();

            for (const hand of sides) {
                hand.material.dispose();
            }

            for (const mesh of items.values()) {
                const material = mesh.material as THREE.MeshBasicMaterial;

                material.map?.dispose();
                material.dispose();
            }
        },
    };
}
