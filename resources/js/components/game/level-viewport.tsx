import { useEffect, useRef, useState } from 'react';
import type { ReactNode } from 'react';
import * as THREE from 'three';
import { store } from '@/actions/App/Http/Controllers/DebugSnapshotController';
import { createActors } from '@/lib/engine/actors';
import type { Actors } from '@/lib/engine/actors';
import { buildLevel } from '@/lib/engine/build-level';
import { moveWithCollisions } from '@/lib/engine/collision';
import {
    BACKGROUND_COLOR,
    EYE_HEIGHT,
    FAR_PLANE,
    FIELD_OF_VIEW,
    MAX_FRAME_SECONDS,
    MAX_PITCH,
    MOUSE_SENSITIVITY,
    NEAR_PLANE,
    PIXEL_SCALE,
    PLAYER_RADIUS,
    REACH,
    RUN_SPEED,
    STEP_SMOOTHING,
    WADE_DEPTH,
    WALK_SPEED,
    PORTAL_BOUNCES,
    PORTAL_RENDER_BUDGET,
    PANE_CLEARANCE,
    TUNNEL_SHRINK,
} from '@/lib/engine/constants';
import { createHands, HELD_ITEMS } from '@/lib/engine/hands';
import type { HeldItem } from '@/lib/engine/hands';
import type { PortalSurface } from '@/lib/engine/portal-surface';
import { createPortals, crossPortal } from '@/lib/engine/portals';
import { boundsOf, sectorAt } from '@/lib/engine/sectors';
import { createSky } from '@/lib/engine/sky';
import type { SkyDome } from '@/lib/engine/sky';
import { describeSpot } from '@/lib/engine/snapshot';
import { createMagic } from '@/lib/engine/spells';
import {
    createSpriteActor,
    DEFAULT_PLAYER_HEIGHT,
    HEIGHTS,
} from '@/lib/engine/sprite-actor';
import type { SpriteActor } from '@/lib/engine/sprite-actor';
import { createTextureLibrary } from '@/lib/engine/textures';
import { createTouchControls, wantsTouchControls } from '@/lib/engine/touch';
import { cn } from '@/lib/utils';
import type { Level, LevelThing } from '@/types';

type LevelViewportProps = {
    level: Level;
    /** Whatever the crosshair is resting on, or null. */
    onFocus: (thing: LevelThing | null) => void;
    onExamine: (thing: LevelThing) => void;
    onLockChange: (locked: boolean) => void;
    /** Anything the level wants to tell the player, such as a snapshot saving. */
    onMessage?: (text: string) => void;
    /**
     * Stops the level taking keys and turning the view, without letting go of
     * the pointer — for while something on top of it, such as the verb menu, is
     * waiting on an answer.
     */
    paused?: boolean;
    children?: ReactNode;
};

/** The one of them who can do magic. */
const WIZARD = 'william';

const FOG_NEAR = 8;
const FOG_FAR = 60;

const FORWARD_KEYS = ['KeyW', 'ArrowUp'];
const BACKWARD_KEYS = ['KeyS', 'ArrowDown'];
const LEFT_KEYS = ['KeyA', 'ArrowLeft'];
const RIGHT_KEYS = ['KeyD', 'ArrowRight'];

/**
 * Every pane in the level, drawn before the main render begins.
 *
 * A mirror and a portal are the same thing here: a surface showing the room as
 * some other camera sees it. A mirror's camera is the player reflected in the
 * wall; a portal's is the player carried through to the far mouth. Both are the
 * only passes that draw the player's own body — you cannot see yourself, only
 * what a mirror or a portal makes of you.
 *
 * These have to happen before the main render rather than during it. A render
 * nested inside another loses the rest of the outer pass, and everything
 * transparent that was still to be drawn — the people — drops out of the frame.
 *
 * @returns a function that refreshes every pane for the coming frame.
 */
function prepareReflections(
    mirrors: PortalSurface[],
    portals: PortalSurface[],
    playerSprite: SpriteActor,
    actors: Actors,
    camera: THREE.PerspectiveCamera,
    sky: SkyDome | null,
): (renderer: THREE.WebGLRenderer, scene: THREE.Scene) => void {
    // A mirror is a pane like any other; only the camera that draws it differs.
    const panes = [...portals, ...mirrors];

    /**
     * Draws one pane's view. The player's own body is shown as well: a mirror
     * has them in plain sight, and so does a pair of portals hung so that one
     * mouth looks back towards the other.
     */
    const drawPane = (
        pane: PortalSurface,
        renderer: THREE.WebGLRenderer,
        scene: THREE.Scene,
        from: THREE.PerspectiveCamera,
        depth: number,
    ): void => {
        const at = pane.viewerAt(from);

        playerSprite.faceViewer(at.x, at.z, at.yaw);
        actors.faceViewer(at.x, at.z, at.yaw);
        playerSprite.object.visible = true;

        // The sky is drawn around whoever is looking, and this pass is looked
        // at from somewhere else entirely. Left where the player is, it hangs
        // in the far room as slabs of hillside a few metres across, in front of
        // everything — which is what a portal full of grass was.
        sky?.follow(at.x, from.position.y, at.z);

        pane.render(renderer, scene, from, depth);

        playerSprite.object.visible = false;
    };

    const frustum = new THREE.Frustum();
    const seen = new THREE.Matrix4();

    /** Whether a pane is anywhere in what a camera can see. */
    const inViewOf = (
        pane: PortalSurface,
        from: THREE.PerspectiveCamera,
    ): boolean => {
        seen.multiplyMatrices(from.projectionMatrix, from.matrixWorldInverse);
        frustum.setFromProjectionMatrix(seen);

        return frustum.intersectsSphere(pane.bounds);
    };

    return (renderer, scene) => {
        // Whatever was pulled in front of the player last frame goes back where
        // it belongs before anything is drawn, or every other pane's camera
        // finds a wall-sized sheet hanging in the middle of the room.
        for (const portal of portals) {
            portal.release();
        }

        let spent = 0;

        /**
         * Draws a pane as seen from a viewpoint. Going deeper draws whatever
         * panes this one's own camera can see one level further in first, and
         * only then this one — by which time those panes are showing the view
         * from here rather than the view from the player. That is what puts one
         * mirror inside another, and one portal inside the last.
         */
        const deepen = (
            pane: PortalSurface,
            from: THREE.PerspectiveCamera,
            depth: number,
            allowed: number,
        ): void => {
            if (depth < allowed && spent < PORTAL_RENDER_BUDGET) {
                const inner = pane.aim(from);

                for (const other of panes) {
                    // The far mouth is taken out of this view, so drawing what
                    // it holds is work for nobody. Skipping it is most of the
                    // saving: for an ordinary pair, the only pane in the room
                    // beyond is the partner, so that whole branch disappears
                    // and the budget goes where it can be seen — a portal hung
                    // to look back at itself.
                    if (other.mesh === pane.partner) {
                        continue;
                    }

                    // And only what stands in a room this pane can see into. A
                    // frustum knows nothing of walls, so without this every pane
                    // in the level that happened to fall in the cone would be
                    // drawn, and the depth would go on rooms that are not on the
                    // other side of this one at all. A doorway counts: a mirror
                    // one room further on is still in the picture, and if it is
                    // never drawn for this view its reflection sits frozen.
                    if (
                        pane.onto.includes(other.home) &&
                        inViewOf(other, inner)
                    ) {
                        deepen(other, inner, depth + 1, allowed);
                    }
                }
            }

            const deepest = depth >= allowed;

            for (const other of panes) {
                if (!deepest) {
                    other.mesh.visible = true;
                    other.show(depth + 1);

                    continue;
                }

                // The tunnel has run out of levels. Rather than leave a hole at
                // the end of it — which shows the sky, a mouth having nothing
                // behind it — the panes are given the view from one level out,
                // pulled in from the edges so it reads as a room further away.
                // It is last frame's, this frame not having drawn it yet, and
                // at the far end of a corridor of portals nobody is going to
                // catch it lagging.
                //
                // Only from the second level down: at the first there is no
                // level out to borrow, so the pane goes instead, since a
                // texture cannot be read and written at once.
                if (depth >= 1) {
                    other.mesh.visible = true;
                    other.show(depth - 1, TUNNEL_SHRINK);
                } else {
                    other.mesh.visible = false;
                }
            }

            spent++;

            drawPane(pane, renderer, scene, from, depth);

            for (const other of panes) {
                other.mesh.visible = true;
            }
        };

        for (const pane of panes) {
            // A pane the player cannot see still needs its own view drawn, in
            // case another pane is looking at it, but it is not worth spending
            // the frame's depth on. What is in front of them gets that.
            deepen(
                pane,
                camera,
                0,
                inViewOf(pane, camera) ? PORTAL_BOUNCES : 0,
            );
        }

        // Back around the player, for the view they actually get.
        sky?.follow(camera.position.x, camera.position.y, camera.position.z);

        // What the player is about to be shown.
        for (const pane of panes) {
            pane.show(0);
        }

        // Last of all, and only for the view the player gets: a pane they have
        // walked right up to squares up to the screen, so the near plane cannot
        // cut a hole in it. Left until now because a pane held in front of the
        // player's face has no business turning up in another pane's view.
        for (const portal of portals) {
            portal.hug(camera, PANE_CLEARANCE);
        }

        // Put everyone back the way the main pass needs them.
        actors.faceViewer(
            camera.position.x,
            camera.position.z,
            camera.rotation.y,
        );
    };
}

/**
 * Owns the render loop. React draws the frame around it and is told what the
 * player is looking at; everything inside the canvas is imperative three.js.
 */
export default function LevelViewport({
    level,
    onFocus,
    onExamine,
    onLockChange,
    onMessage,
    paused = false,
    children,
}: LevelViewportProps) {
    const containerRef = useRef<HTMLDivElement>(null);
    const [fullscreen, setFullscreen] = useState(false);

    // A phone has no pointer to lock, so playing is a state of its own there,
    // and the frame fills the screen itself rather than asking the browser to.
    const [touch] = useState(wantsTouchControls);
    const [playing, setPlaying] = useState(false);

    // Held in a ref so that re-rendering the frame never restarts the level.
    const callbacks = useRef({ onFocus, onExamine, onLockChange, onMessage });
    const held = useRef(paused);

    useEffect(() => {
        callbacks.current = { onFocus, onExamine, onLockChange, onMessage };
        held.current = paused;
    });

    useEffect(() => {
        const container = containerRef.current;

        if (container === null) {
            return;
        }

        const scene = new THREE.Scene();
        scene.background = new THREE.Color(BACKGROUND_COLOR);
        scene.fog = new THREE.Fog(BACKGROUND_COLOR, FOG_NEAR, FOG_FAR);

        const textures = createTextureLibrary();
        const built = buildLevel(level, textures);
        scene.add(built.group);

        // How far the camera has to be able to see. FAR_PLANE is what an
        // ordinary level needs, and it is kept as tight as that on purpose:
        // walls sit a centimetre apart where they are inset, and the further
        // the far plane goes the less depth there is to tell them apart with.
        //
        // But somebody who makes a person a hundred metres tall would rather
        // see all of them than keep the precision, and the far plane is what
        // was cutting the top off. So it opens up exactly as far as the level
        // asks and no further.
        const reach = (() => {
            const bounds = boundsOf(level.sectors);
            const across = Math.hypot(
                bounds.maxX - bounds.minX,
                bounds.maxZ - bounds.minZ,
            );
            const tallest = level.things.reduce(
                (most, thing) => Math.max(most, thing.height),
                0,
            );
            const highest = level.sectors.reduce(
                (most, sector) => Math.max(most, sector.ceilingHeight),
                0,
            );

            return Math.max(FAR_PLANE, across + tallest * 1.2 + highest + 10);
        })();

        const camera = new THREE.PerspectiveCamera(
            FIELD_OF_VIEW,
            1,
            NEAR_PLANE,
            reach,
        );
        camera.rotation.order = 'YXZ';

        const sky = level.sky === null ? null : createSky(level.sky);

        if (sky !== null) {
            scene.add(sky.object);
        }

        const actors = createActors(level);

        for (const object of actors.objects) {
            scene.add(object);
            built.targets.push(object);
        }

        // Your own body: never drawn for your own camera, only inside mirrors.
        const playerSprite = createSpriteActor(
            level.playerSprite,
            HEIGHTS[level.playerSprite] ?? DEFAULT_PLAYER_HEIGHT,
            level.spriteStyle,
        );
        playerSprite.object.visible = false;
        scene.add(playerSprite.object);
        const refreshReflections = prepareReflections(
            built.mirrors,
            built.portals,
            playerSprite,
            actors,
            camera,
            sky,
        );

        const thingsBySlug = new Map(
            level.things.map((thing) => [thing.slug, thing]),
        );

        // Smoothing happens inside the small buffer, before it is blown up.
        // The picture stays as coarse as it was — the edges within it just stop
        // climbing in steps. Turning it off is a matter of PIXEL_SCALE, which
        // is what decides how coarse the picture is in the first place.
        const renderer = new THREE.WebGLRenderer({
            antialias: true,
            // Depth kept as a logarithm rather than spread evenly. The far
            // plane opens up as far as a level asks — somebody a thousand
            // metres tall wants a thousand metres of it — and spread evenly
            // there is not enough left over to tell two walls a centimetre
            // apart from each other. It costs the early depth test, which is
            // a fair price for walls that do not shimmer.
            logarithmicDepthBuffer: true,
        });
        renderer.setPixelRatio(1 / PIXEL_SCALE);
        renderer.domElement.style.width = '100%';
        renderer.domElement.style.height = '100%';
        renderer.domElement.style.imageRendering = 'pixelated';
        renderer.domElement.style.display = 'block';
        container.appendChild(renderer.domElement);

        const spawnSector = sectorAt(
            level.sectors,
            level.spawn.x,
            level.spawn.z,
        );

        const player = {
            x: level.spawn.x,
            z: level.spawn.z,
            yaw: -THREE.MathUtils.degToRad(level.spawn.angle),
            pitch: 0,
            eye: (spawnSector?.floorHeight ?? 0) + EYE_HEIGHT,
        };

        // The player's own hands, hung off the camera. The camera goes into the
        // scene for them: a child of something outside it is never drawn.
        const hands = createHands(level.playerSprite);

        camera.add(hands.object);
        scene.add(camera);

        const portals = createPortals(level.sectors);

        // Only William knows the words. Nobody else gets so much as the keys.
        const magic = level.playerSprite === WIZARD ? createMagic() : null;

        if (magic !== null) {
            scene.add(magic.object);
        }

        const pressed = new Set<string>();
        const raycaster = new THREE.Raycaster();
        raycaster.far = REACH;
        const screenCenter = new THREE.Vector2(0, 0);

        let focusedSlug: string | null = null;
        let walked = 0;
        let frame = 0;
        let lastTime = performance.now();

        // On a phone there is no pointer to lock, so playing is just a flag,
        // set by the tap that starts the level and cleared by the Stop button.
        let started = false;

        /** Whether the controls are currently stood down for something on top. */
        let waiting = false;

        const isLocked = (): boolean =>
            touch ? started : document.pointerLockElement === container;

        const resize = (): void => {
            const { clientWidth, clientHeight } = container;

            if (clientWidth === 0 || clientHeight === 0) {
                return;
            }

            renderer.setSize(clientWidth, clientHeight, false);
            camera.aspect = clientWidth / clientHeight;
            camera.updateProjectionMatrix();
        };

        const setFocus = (slug: string | null): void => {
            if (slug === focusedSlug) {
                return;
            }

            focusedSlug = slug;
            built.highlight(slug);
            callbacks.current.onFocus(
                slug === null ? null : (thingsBySlug.get(slug) ?? null),
            );
        };

        const lookedAtSlug = (): string | null => {
            raycaster.setFromCamera(screenCenter, camera);

            const [hit] = raycaster.intersectObjects(built.targets, false);

            if (hit === undefined) {
                return null;
            }

            const slug: unknown = hit.object.userData.thingSlug;

            return typeof slug === 'string' ? slug : null;
        };

        const step = (seconds: number): void => {
            // Whatever is on top of the level has the keys while it is open.
            // Let go of anything still held down and throw away any drag, so
            // that the player does not walk off while they are reading. The
            // level itself carries on: people keep walking, spells keep burning.
            const holding = held.current;

            if (holding !== waiting) {
                waiting = holding;

                // The stick and the buttons sit over the level, so they would
                // swallow the taps meant for whatever is asking.
                controls.show(started && !holding);
            }

            if (holding) {
                pressed.clear();
                controls.takeLook();
            }

            const pushed = holding
                ? { forward: 0, strafe: 0 }
                : controls.walk();
            const turned = holding ? { yaw: 0, pitch: 0 } : controls.takeLook();

            if (turned.yaw !== 0 || turned.pitch !== 0) {
                player.yaw += turned.yaw;
                player.pitch = THREE.MathUtils.clamp(
                    player.pitch + turned.pitch,
                    -MAX_PITCH,
                    MAX_PITCH,
                );
            }

            const running =
                pressed.has('ShiftLeft') ||
                pressed.has('ShiftRight') ||
                controls.running();

            const speed = running ? RUN_SPEED : WALK_SPEED;

            // A stick reads anywhere between nothing and all the way over; a key
            // is only ever one or the other. Whichever is pushed harder wins.
            const pick = (keyed: number, pushed: number): number =>
                Math.abs(pushed) > Math.abs(keyed) ? pushed : keyed;

            const forward = pick(
                (FORWARD_KEYS.some((key) => pressed.has(key)) ? 1 : 0) -
                    (BACKWARD_KEYS.some((key) => pressed.has(key)) ? 1 : 0),
                pushed.forward,
            );
            const strafe = pick(
                (RIGHT_KEYS.some((key) => pressed.has(key)) ? 1 : 0) -
                    (LEFT_KEYS.some((key) => pressed.has(key)) ? 1 : 0),
                pushed.strafe,
            );

            if (forward !== 0 || strafe !== 0) {
                const sin = Math.sin(player.yaw);
                const cos = Math.cos(player.yaw);

                let moveX = forward * -sin + strafe * cos;
                let moveZ = forward * -cos + strafe * -sin;

                // A stick half over is a walk half as fast. A key is all the
                // way over or not at all, so this changes nothing for one.
                const throttle = Math.min(1, Math.hypot(forward, strafe));

                const length = Math.hypot(moveX, moveZ);
                moveX = (moveX / length) * speed * throttle * seconds;
                moveZ = (moveZ / length) * speed * throttle * seconds;

                const moved = moveWithCollisions(
                    player,
                    moveX,
                    moveZ,
                    built.colliders,
                    PLAYER_RADIUS,
                );

                // A portal is asked about before the floor plan is, because
                // walking into one leaves the room by design: the step that
                // crosses it lands outside every sector until it is carried
                // through to the far mouth.
                const through = crossPortal(
                    portals,
                    player.x,
                    player.z,
                    moved.x,
                    moved.z,
                    player.yaw,
                );

                const next =
                    through !== null &&
                    sectorAt(level.sectors, through.x, through.z) !== null
                        ? through
                        : moved;

                if (sectorAt(level.sectors, next.x, next.z) !== null) {
                    walked += Math.hypot(moveX, moveZ);
                    player.x = next.x;
                    player.z = next.z;

                    if (next === through) {
                        player.yaw = through.yaw;
                    }
                }
            }

            const standingIn = sectorAt(level.sectors, player.x, player.z);

            // A lid belongs to the room it covers and to nobody else.
            for (const lid of built.skyLids) {
                lid.mesh.visible = lid.room === standingIn?.slug;
            }

            const floor = standingIn?.floorHeight ?? 0;
            const wading = standingIn?.isWater === true ? WADE_DEPTH : 0;

            // The eye catches up with the floor rather than jumping to it.
            player.eye +=
                (floor + EYE_HEIGHT - wading - player.eye) *
                Math.min(1, STEP_SMOOTHING * seconds);

            hands.update(seconds, walked, running);

            playerSprite.place(player.x, floor, player.z, player.yaw, walked);
            actors.update(seconds, built.colliders);
            actors.faceViewer(player.x, player.z, player.yaw);

            camera.position.set(player.x, player.eye, player.z);
            camera.rotation.y = player.yaw;
            camera.rotation.x = player.pitch;

            // Mirrors and portals are drawn from cameras derived from this one,
            // and they run before the main render — which is where three would
            // otherwise get round to working its world matrix out.
            camera.updateMatrixWorld(true);

            sky?.follow(player.x, player.eye, player.z);

            textures.tick(seconds);
            magic?.update(seconds);
        };

        const tick = (now: number): void => {
            frame = requestAnimationFrame(tick);

            const seconds = Math.min(
                (now - lastTime) / 1000,
                MAX_FRAME_SECONDS,
            );
            lastTime = now;

            step(seconds);
            setFocus(lookedAtSlug());
            // Out of the way of every other camera in the level. A mirror shows
            // the player's whole body, and a pair of hands hanging in the air
            // in front of it as well would be one pair too many.
            hands.object.visible = false;
            refreshReflections(renderer, scene);
            hands.object.visible = true;

            renderer.render(scene, camera);
        };

        /**
         * What the player can do, apart from walking and looking. A key does
         * each of these, and on a phone so does a button, so they are written
         * once here rather than inside the key handler.
         */
        const takeInHand = (item: HeldItem | null): void => {
            hands.hold(item !== null && hands.holding() === item ? null : item);
        };

        const markHere = (): void => {
            if (magic === null) {
                return;
            }

            const standingIn = sectorAt(level.sectors, player.x, player.z);

            magic.mark({
                x: player.x,
                y: standingIn?.floorHeight ?? 0,
                z: player.z,
            });
        };

        const recall = (): void => {
            const home = magic?.marked() ?? null;

            if (magic === null || home === null) {
                return;
            }

            // A handful thrown where they were, and another where they arrive,
            // so it reads as going rather than as blinking.
            const standingIn = sectorAt(level.sectors, player.x, player.z);

            magic.burst({
                x: player.x,
                y: (standingIn?.floorHeight ?? 0) + 0.6,
                z: player.z,
            });

            player.x = home.x;
            player.z = home.z;
            walked = 0;

            magic.burst({ x: home.x, y: home.y + 0.6, z: home.z });
        };

        /**
         * Writes down where the player is standing, for coming back to a spot
         * that looks wrong instead of guessing at it. Numbers only — nothing
         * here needs the renderer.
         */
        const takeSnapshot = (): void => {
            const spot = describeSpot({
                level,
                x: player.x,
                z: player.z,
                eye: player.eye,
                yaw: player.yaw,
                pitch: player.pitch,
                lookingAt: focusedSlug,
                holding: hands.holding(),
                running:
                    pressed.has('ShiftLeft') ||
                    pressed.has('ShiftRight') ||
                    controls.running(),
                screen: {
                    width: container.clientWidth,
                    height: container.clientHeight,
                    pixelRatio: window.devicePixelRatio,
                    touch,
                },
                takenAt: new Date().toISOString(),
            });

            // Always to the console as well, so a snapshot is never lost to a
            // server that is not listening. Copying it out of there beats
            // taking it again, since the thing being caught may not come back.
            console.log('[snapshot]', JSON.stringify(spot));

            callbacks.current.onMessage?.('Saving a snapshot…');

            const failed = (why: string): void => {
                callbacks.current.onMessage?.(
                    `The snapshot would not save (${why}). It is in the browser console instead.`,
                );
            };

            // Laravel wants the forgery token, and this page carries none in
            // its markup — only the cookie it sets on every response. Read it
            // back out and hand it over the way Laravel expects.
            const guard = document.cookie
                .split('; ')
                .find((crumb) => crumb.startsWith('XSRF-TOKEN='));

            void fetch(store().url, {
                method: 'POST',
                credentials: 'same-origin',
                headers: {
                    'Content-Type': 'application/json',
                    Accept: 'application/json',
                    ...(guard === undefined
                        ? {}
                        : {
                              'X-XSRF-TOKEN': decodeURIComponent(
                                  guard.slice('XSRF-TOKEN='.length),
                              ),
                          }),
                },
                body: JSON.stringify(spot),
            })
                .then(async (answer) => {
                    if (!answer.ok) {
                        failed(`the server said ${answer.status}`);

                        return;
                    }

                    const said: unknown = await answer.json().catch(() => null);
                    const name =
                        said !== null &&
                        typeof said === 'object' &&
                        'saved' in said
                            ? String((said as { saved: unknown }).saved)
                            : 'a snapshot';

                    callbacks.current.onMessage?.(`Snapshot saved as ${name}.`);
                })
                .catch(() => failed('the server did not answer'));
        };

        const examine = (): void => {
            const thing =
                focusedSlug === null
                    ? undefined
                    : thingsBySlug.get(focusedSlug);

            if (thing !== undefined) {
                callbacks.current.onExamine(thing);
            }
        };

        const handleKeyDown = (event: KeyboardEvent): void => {
            if (!isLocked() || held.current) {
                return;
            }

            pressed.add(event.code);

            if (event.code.startsWith('Arrow') || event.code === 'Space') {
                event.preventDefault();
            }

            if (event.code === 'Digit0' && !event.repeat) {
                takeInHand(null);
            }

            HELD_ITEMS.forEach((item, index) => {
                if (event.code === `Digit${index + 1}` && !event.repeat) {
                    takeInHand(item);
                }
            });

            if (event.code === 'KeyM' && !event.repeat) {
                markHere();
            }

            if (event.code === 'KeyR' && !event.repeat) {
                recall();
            }

            if (event.code === 'KeyE' && !event.repeat) {
                examine();
            }

            if (event.code === 'KeyF' && !event.repeat) {
                takeSnapshot();
            }
        };

        const handleKeyUp = (event: KeyboardEvent): void => {
            pressed.delete(event.code);
        };

        const handleMouseMove = (event: MouseEvent): void => {
            if (!isLocked() || held.current) {
                return;
            }

            player.yaw -= event.movementX * MOUSE_SENSITIVITY;
            player.pitch = THREE.MathUtils.clamp(
                player.pitch - event.movementY * MOUSE_SENSITIVITY,
                -MAX_PITCH,
                MAX_PITCH,
            );
        };

        const handleBlur = (): void => {
            pressed.clear();
        };

        const isFullscreen = (): boolean =>
            document.fullscreenElement === container;

        /** Play and full screen are the same state, so they come and go together. */
        const handleLockChange = (): void => {
            const locked = isLocked();

            if (!locked) {
                pressed.clear();

                if (isFullscreen()) {
                    void document.exitFullscreen().catch(() => undefined);
                }
            }

            callbacks.current.onLockChange(locked);
        };

        const handleFullscreenChange = (): void => {
            const full = isFullscreen();

            setFullscreen(full);

            // Escape leaves full screen first; let go of the mouse as well.
            if (!full && isLocked()) {
                document.exitPointerLock();
            }
        };

        /** Puts the phone down: back to the page, controls off. */
        const stop = (): void => {
            started = false;
            controls.show(false);
            setPlaying(false);
            pressed.clear();
            callbacks.current.onLockChange(false);

            if (isFullscreen()) {
                void document.exitFullscreen().catch(() => undefined);
            }
        };

        const handleClick = (): void => {
            if (isLocked()) {
                return;
            }

            if (touch) {
                // No pointer to lock and, on a phone, often no full screen to
                // ask for either. The frame fills the screen itself instead.
                started = true;
                controls.show(true);
                setPlaying(true);
                callbacks.current.onLockChange(true);
            } else {
                try {
                    const request: unknown = container.requestPointerLock();

                    if (request instanceof Promise) {
                        // A refused lock is the browser's business, not an app
                        // error.
                        request.catch(() => undefined);
                    }
                } catch {
                    // Older browsers throw here instead of rejecting.
                }
            }

            if (!isFullscreen() && container.requestFullscreen !== undefined) {
                // Refused full screen is no reason to stop the player playing.
                void container.requestFullscreen().catch(() => undefined);
            }
        };

        /**
         * The keys, as buttons. Only built on a touch screen; on anything else
         * createTouchControls hands back a set that draws nothing.
         */
        const controls = createTouchControls({
            container,
            buttons: [
                {
                    label: 'Look',
                    title: 'Examine what you are looking at',
                    press: examine,
                },
                ...(magic === null
                    ? []
                    : [
                          {
                              label: 'Mark',
                              title: 'Leave a mark here',
                              press: markHere,
                          },
                          {
                              label: 'Recall',
                              title: 'Go back to the mark',
                              press: recall,
                          },
                      ]),
                ...HELD_ITEMS.map((item) => ({
                    label: item,
                    title: `Take the ${item}`,
                    press: () => takeInHand(item),
                })),
                {
                    label: 'Empty',
                    title: 'Empty your hands',
                    press: () => takeInHand(null),
                },
                {
                    label: 'Snap',
                    title: 'Save a snapshot of this spot',
                    press: takeSnapshot,
                },
                { label: 'Stop', title: 'Stop playing', press: stop },
            ],
        });

        container.addEventListener('click', handleClick);
        window.addEventListener('keydown', handleKeyDown);
        window.addEventListener('keyup', handleKeyUp);
        window.addEventListener('blur', handleBlur);
        document.addEventListener('mousemove', handleMouseMove);
        document.addEventListener('pointerlockchange', handleLockChange);
        document.addEventListener('fullscreenchange', handleFullscreenChange);

        const observer = new ResizeObserver(resize);
        observer.observe(container);

        resize();
        frame = requestAnimationFrame(tick);

        return () => {
            cancelAnimationFrame(frame);
            observer.disconnect();
            container.removeEventListener('click', handleClick);
            window.removeEventListener('keydown', handleKeyDown);
            window.removeEventListener('keyup', handleKeyUp);
            window.removeEventListener('blur', handleBlur);
            document.removeEventListener('mousemove', handleMouseMove);
            document.removeEventListener('pointerlockchange', handleLockChange);
            document.removeEventListener(
                'fullscreenchange',
                handleFullscreenChange,
            );

            if (isLocked()) {
                document.exitPointerLock();
            }

            if (document.fullscreenElement === container) {
                void document.exitFullscreen().catch(() => undefined);
            }

            controls.dispose();
            actors.dispose();
            playerSprite.dispose();
            sky?.dispose();
            magic?.dispose();
            hands.dispose();
            built.dispose();
            textures.dispose();
            renderer.dispose();
            renderer.domElement.remove();
        };
        // touch is settled once, when the component first mounts, so listing it
        // here never restarts the level.
    }, [level, touch]);

    return (
        <div
            ref={containerRef}
            className={cn(
                'relative cursor-crosshair overflow-hidden bg-[#05070a] select-none',
                fullscreen && 'h-screen w-screen',
                playing && !fullscreen && 'fixed inset-0 z-40 h-dvh w-screen',
                !fullscreen &&
                    !playing &&
                    'aspect-video w-full rounded-lg border border-white/10 shadow-2xl',
                // A drag across the view turns it; it must not scroll the page.
                touch && 'touch-none',
            )}
        >
            {children}
        </div>
    );
}
