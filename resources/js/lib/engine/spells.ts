import * as THREE from 'three';
import { moveWithCollisions } from '@/lib/engine/collision';
import type { Collider } from '@/lib/engine/collision';

/**
 * Mark and Recall.
 *
 * Mark leaves a runic circle burning on the floor where it is cast. Recall
 * takes you back to it from anywhere in the level, and leaves the circle where
 * it is, so one mark can be returned to as often as you like.
 *
 * Both are drawn rather than loaded: the circle's runes are painted onto a
 * canvas when the level starts, and the motes are one buffer of points moved
 * about by hand. Nothing here needs an artist, which is the point — it can be
 * changed by whoever is reading this without opening anything else.
 */

/** How wide the circle lies on the floor, in metres. */
const CIRCLE_SIZE = 2.2;

/** Clear of the floor, so the two do not fight over which is on top. */
const CIRCLE_LIFT = 0.02;

/** A full turn of the circle takes this long, in seconds. */
const CIRCLE_TURN = 14;

/** How many motes there are to go round. */
const MOTES = 220;

/** How long a mote lives once it is let go, in seconds. */
const MOTE_LIFE = 1.6;

/** The colour of the whole business. */
/** How fast a fireball flies, in metres a second. */
const FIRE_SPEED = 9;

/** How long it lives before it burns out on its own. */
const FIRE_LIFE = 2.2;

/** How big the ball is drawn. */
const FIRE_SIZE = 0.45;

const FIRE_COLOUR = '#ffb457';

const SPELL_COLOUR = '#8ef0ff';
const SPELL_GLOW = '#ffffff';

export type Mark = { x: number; y: number; z: number };

export type Magic = {
    /** Everything the spells draw, to be added to the scene. */
    object: THREE.Object3D;
    /** Lays the circle down at a spot on the floor. */
    mark: (at: Mark) => void;
    /** Where the circle is, or null if none has been laid. */
    marked: () => Mark | null;
    /** Throws a handful of motes into the air at a spot. */
    burst: (at: Mark, many?: number) => void;
    /**
     * Throws a fireball from a spot, along a yaw. It flies until it meets
     * something or burns out, trailing motes, and bursts where it stops.
     */
    cast: (from: Mark, yaw: number) => void;
    update: (seconds: number, colliders?: Collider[]) => void;
    dispose: () => void;
};

/**
 * The runes. Drawn once onto a canvas: two rings with a band of marks between
 * them, and a star inside. They are not any real alphabet and are not meant to
 * be — they only have to look like they mean something.
 */
function runeTexture(): THREE.Texture {
    const size = 512;
    const canvas = document.createElement('canvas');
    canvas.width = size;
    canvas.height = size;

    const context = canvas.getContext('2d');

    if (context === null) {
        return new THREE.Texture();
    }

    const middle = size / 2;
    const outer = size * 0.46;
    const inner = size * 0.34;

    context.clearRect(0, 0, size, size);
    context.strokeStyle = SPELL_COLOUR;
    context.fillStyle = SPELL_COLOUR;
    context.lineCap = 'round';

    const ring = (radius: number, width: number): void => {
        context.lineWidth = width;
        context.beginPath();
        context.arc(middle, middle, radius, 0, Math.PI * 2);
        context.stroke();
    };

    ring(outer, 5);
    ring(outer * 0.94, 2);
    ring(inner, 3);

    // The band of marks between the rings. Each is a few strokes hung off a
    // spoke, turned to face out of the circle like writing round a coin.
    const marks = 16;

    for (let index = 0; index < marks; index++) {
        const turn = (index / marks) * Math.PI * 2;

        context.save();
        context.translate(
            middle + Math.cos(turn) * ((outer + inner) / 2),
            middle + Math.sin(turn) * ((outer + inner) / 2),
        );
        context.rotate(turn + Math.PI / 2);
        context.lineWidth = 3;

        const tall = size * 0.035;
        const wide = size * 0.018;

        context.beginPath();
        context.moveTo(0, -tall);
        context.lineTo(0, tall);

        // A different flourish every few marks, so it does not read as a
        // repeating pattern the way a stencil would.
        if (index % 4 === 0) {
            context.moveTo(-wide, -tall * 0.4);
            context.lineTo(wide, -tall * 0.4);
        } else if (index % 4 === 1) {
            context.moveTo(0, 0);
            context.lineTo(wide, tall * 0.6);
        } else if (index % 4 === 2) {
            context.moveTo(-wide, tall * 0.5);
            context.lineTo(0, 0);
            context.lineTo(wide, tall * 0.5);
        } else {
            context.moveTo(-wide, -tall);
            context.lineTo(wide, tall);
        }

        context.stroke();
        context.restore();
    }

    // A star inside, drawn in one line the way they are in storybooks.
    const points = 5;
    const step = 2;

    context.lineWidth = 3;
    context.beginPath();

    for (let index = 0; index <= points; index++) {
        const turn = ((index * step) / points) * Math.PI * 2 - Math.PI / 2;
        const x = middle + Math.cos(turn) * inner * 0.86;
        const y = middle + Math.sin(turn) * inner * 0.86;

        if (index === 0) {
            context.moveTo(x, y);
        } else {
            context.lineTo(x, y);
        }
    }

    context.stroke();

    const texture = new THREE.CanvasTexture(canvas);
    texture.colorSpace = THREE.SRGBColorSpace;

    return texture;
}

/** A soft dot for the motes, so they read as light rather than as squares. */
function moteTexture(): THREE.Texture {
    const size = 64;
    const canvas = document.createElement('canvas');
    canvas.width = size;
    canvas.height = size;

    const context = canvas.getContext('2d');

    if (context === null) {
        return new THREE.Texture();
    }

    const glow = context.createRadialGradient(
        size / 2,
        size / 2,
        0,
        size / 2,
        size / 2,
        size / 2,
    );

    glow.addColorStop(0, SPELL_GLOW);
    glow.addColorStop(0.35, SPELL_COLOUR);
    glow.addColorStop(1, 'rgba(142, 240, 255, 0)');

    context.fillStyle = glow;
    context.fillRect(0, 0, size, size);

    const texture = new THREE.CanvasTexture(canvas);
    texture.colorSpace = THREE.SRGBColorSpace;

    return texture;
}

export function createMagic(): Magic {
    const object = new THREE.Group();

    // The fireball itself: one quad, always facing the camera, lit by nothing.
    const fireTexture = moteTexture();
    const fireMaterial = new THREE.SpriteMaterial({
        map: fireTexture,
        color: new THREE.Color(FIRE_COLOUR),
        transparent: true,
        depthWrite: false,
        blending: THREE.AdditiveBlending,
        fog: false,
    });
    const fireball = new THREE.Sprite(fireMaterial);
    fireball.scale.setScalar(FIRE_SIZE);
    fireball.visible = false;
    object.add(fireball);

    /** Where the ball is and where it is going, or null when nothing is cast. */
    let flying: {
        x: number;
        y: number;
        z: number;
        dx: number;
        dz: number;
        life: number;
    } | null = null;

    // Nothing here is lit, and none of it should hide anything behind it.
    const runes = runeTexture();
    const circleMaterial = new THREE.MeshBasicMaterial({
        map: runes,
        transparent: true,
        depthWrite: false,
        blending: THREE.AdditiveBlending,
        side: THREE.DoubleSide,
        fog: false,
    });

    const circleGeometry = new THREE.PlaneGeometry(CIRCLE_SIZE, CIRCLE_SIZE);
    const circle = new THREE.Mesh(circleGeometry, circleMaterial);
    circle.rotation.x = -Math.PI / 2;
    circle.visible = false;
    object.add(circle);

    // The motes: one buffer, every one of them dead until it is thrown.
    const dots = moteTexture();
    const positions = new Float32Array(MOTES * 3);
    const velocities = new Float32Array(MOTES * 3);
    const lives = new Float32Array(MOTES);
    const sizes = new Float32Array(MOTES);

    // Well out of the way until one is thrown, or the whole handful sits in a
    // heap at the middle of the level waiting to be used.
    for (let index = 0; index < MOTES; index++) {
        positions[index * 3 + 1] = -1000;
    }

    const moteGeometry = new THREE.BufferGeometry();
    moteGeometry.setAttribute(
        'position',
        new THREE.BufferAttribute(positions, 3),
    );
    moteGeometry.setAttribute('size', new THREE.BufferAttribute(sizes, 1));

    // A shader rather than PointsMaterial, which draws every point the same
    // size and the same strength. A mote that does not shrink and fade as it
    // goes reads as a speck of dirt on the screen.
    const moteMaterial = new THREE.ShaderMaterial({
        uniforms: { dot: { value: dots } },
        transparent: true,
        depthWrite: false,
        blending: THREE.AdditiveBlending,
        vertexShader: `
            #include <common>
            #include <logdepthbuf_pars_vertex>

            attribute float size;
            varying float vLeft;

            void main() {
                vLeft = size;

                vec4 seen = modelViewMatrix * vec4(position, 1.0);

                // Smaller with distance, the way everything else is.
                gl_PointSize = 26.0 * size / max(-seen.z, 0.2);
                gl_Position = projectionMatrix * seen;

                #include <logdepthbuf_vertex>
            }
        `,
        fragmentShader: `
            #include <common>
            #include <logdepthbuf_pars_fragment>

            uniform sampler2D dot;
            varying float vLeft;

            void main() {
                #include <logdepthbuf_fragment>

                gl_FragColor = texture2D(dot, gl_PointCoord) * vLeft;
            }
        `,
    });

    const motes = new THREE.Points(moteGeometry, moteMaterial);
    motes.frustumCulled = false;
    object.add(motes);

    let at: Mark | null = null;
    let age = 0;
    let next = 0;

    /** Throws one mote from a spot, drifting up and outwards. */
    const throwOne = (from: Mark, spread: number, lift: number): void => {
        const index = next;
        next = (next + 1) % MOTES;

        const turn = Math.random() * Math.PI * 2;
        const away = Math.random() * spread;

        positions[index * 3] = from.x + Math.cos(turn) * away;
        positions[index * 3 + 1] = from.y + Math.random() * 0.1;
        positions[index * 3 + 2] = from.z + Math.sin(turn) * away;

        velocities[index * 3] = Math.cos(turn) * 0.25 * Math.random();
        velocities[index * 3 + 1] = lift * (0.6 + Math.random() * 0.8);
        velocities[index * 3 + 2] = Math.sin(turn) * 0.25 * Math.random();

        lives[index] = MOTE_LIFE;
        sizes[index] = 0.5 + Math.random() * 0.5;
    };

    return {
        object,

        mark: (spot) => {
            at = spot;
            age = 0;

            circle.position.set(spot.x, spot.y + CIRCLE_LIFT, spot.z);
            circle.visible = true;

            for (let index = 0; index < 90; index++) {
                throwOne(spot, CIRCLE_SIZE / 2, 1.4);
            }
        },

        marked: () => at,

        cast: (from, yaw) => {
            // Forward is (-sin yaw, -cos yaw), the same convention the player
            // walks by, so a fireball goes where the caster is looking.
            flying = {
                x: from.x,
                y: from.y,
                z: from.z,
                dx: -Math.sin(yaw),
                dz: -Math.cos(yaw),
                life: FIRE_LIFE,
            };

            fireball.position.set(from.x, from.y, from.z);
            fireball.visible = true;
        },

        burst: (spot, many = 70) => {
            for (let index = 0; index < many; index++) {
                throwOne(spot, 0.5, 2.2);
            }
        },

        update: (seconds, colliders = []) => {
            age += seconds;

            if (flying !== null) {
                const step = FIRE_SPEED * seconds;
                const moved = moveWithCollisions(
                    flying,
                    flying.dx * step,
                    flying.dz * step,
                    colliders,
                    FIRE_SIZE / 2,
                );

                // Short of where it was aimed means it met something. The
                // solver slides along a wall, so distance covered is the test
                // rather than whether it stopped dead.
                const covered = Math.hypot(
                    moved.x - flying.x,
                    moved.z - flying.z,
                );

                flying.x = moved.x;
                flying.z = moved.z;
                flying.life -= seconds;

                fireball.position.set(flying.x, flying.y, flying.z);

                // A trail, so it reads as thrown rather than as sliding.
                throwOne({ x: flying.x, y: flying.y, z: flying.z }, 0.12, 0.2);

                if (covered < step * 0.6 || flying.life <= 0) {
                    for (let index = 0; index < 60; index++) {
                        throwOne(
                            { x: flying.x, y: flying.y, z: flying.z },
                            0.45,
                            1.8,
                        );
                    }

                    fireball.visible = false;
                    flying = null;
                }
            }

            if (circle.visible) {
                circle.rotation.z += (Math.PI * 2 * seconds) / CIRCLE_TURN;

                // Breathing, so it never sits quite still.
                circleMaterial.opacity = 0.55 + Math.sin(age * 1.8) * 0.18;

                // And a mote or two wandering up out of it, always.
                if (at !== null && Math.random() < seconds * 22) {
                    throwOne(at, CIRCLE_SIZE / 2, 0.7);
                }
            }

            let alive = false;

            for (let index = 0; index < MOTES; index++) {
                if (lives[index] <= 0) {
                    continue;
                }

                alive = true;
                lives[index] -= seconds;

                positions[index * 3] += velocities[index * 3] * seconds;
                positions[index * 3 + 1] += velocities[index * 3 + 1] * seconds;
                positions[index * 3 + 2] += velocities[index * 3 + 2] * seconds;

                // They slow as they rise, and fade as they go.
                velocities[index * 3 + 1] -= seconds * 0.9;

                const left = Math.max(lives[index] / MOTE_LIFE, 0);
                sizes[index] = left;

                if (lives[index] <= 0) {
                    // Out of the way rather than lingering at the last spot.
                    positions[index * 3 + 1] = -1000;
                }
            }

            if (alive) {
                moteGeometry.attributes.position.needsUpdate = true;
                moteGeometry.attributes.size.needsUpdate = true;
            }
        },

        dispose: () => {
            fireMaterial.dispose();
            fireTexture.dispose();
            circleGeometry.dispose();
            circleMaterial.dispose();
            moteGeometry.dispose();
            moteMaterial.dispose();
            runes.dispose();
            dots.dispose();
        },
    };
}
