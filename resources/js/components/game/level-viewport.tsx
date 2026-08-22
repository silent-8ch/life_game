import { useEffect, useRef, useState } from 'react';
import type { ReactNode } from 'react';
import * as THREE from 'three';
import { store } from '@/actions/App/Http/Controllers/DebugSnapshotController';
import { createActors } from '@/lib/engine/actors';
import { buildLevel } from '@/lib/engine/build-level';
import {
    MAX_FRAME_SECONDS,
    MAX_PITCH,
    MOUSE_SENSITIVITY,
    REACH,
} from '@/lib/engine/constants';
import { createHands, HELD_ITEMS } from '@/lib/engine/hands';
import type { HeldItem } from '@/lib/engine/hands';
import {
    aimCamera,
    settleEye,
    spawnPlayer,
    turnPlayer,
    walkPlayer,
} from '@/lib/engine/player';
import { createPortals } from '@/lib/engine/portals';
import {
    createProbeBackdrop,
    paintWalls,
    scanRow,
    spotFromSearch,
    wantsProbeBackdrop,
} from '@/lib/engine/probe-backdrop';
import { prepareReflections } from '@/lib/engine/reflections';
import {
    afterAFreshFrame,
    publishScan,
    readNow,
    scanRowsOf,
    wantsScan,
} from '@/lib/engine/scan';
import { sectorAt } from '@/lib/engine/sectors';
import { createSky } from '@/lib/engine/sky';
import { describeSpot, readingOf } from '@/lib/engine/snapshot';
import { createMagic } from '@/lib/engine/spells';
import {
    createSpriteActor,
    DEFAULT_PLAYER_HEIGHT,
    HEIGHTS,
} from '@/lib/engine/sprite-actor';
import { createTextureLibrary } from '@/lib/engine/textures';
import { createTouchControls, wantsTouchControls } from '@/lib/engine/touch';
import { createView } from '@/lib/engine/view';
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

const FORWARD_KEYS = ['KeyW', 'ArrowUp'];
const BACKWARD_KEYS = ['KeyS', 'ArrowDown'];
const LEFT_KEYS = ['KeyA', 'ArrowLeft'];
const RIGHT_KEYS = ['KeyD', 'ArrowRight'];

/**
 * Owns the render loop. React draws the frame around it and is told what the
 * player is looking at; everything inside the canvas is imperative three.js.
 */
/** How long a snapshot's reading stays on screen, in milliseconds. */
const SNAPSHOT_SHOWN_FOR = 6000;

/**
 * The fixed timestep a `?scan` runs on, and the frame it reads back at. Sixty a
 * second for half a second: far enough in that the eye has settled onto the
 * floor and every pane has been drawn, and identical on any machine.
 */
const SCAN_STEP_SECONDS = 1 / 60;
const SCAN_FRAMES = 30;

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

    /**
     * What the last snapshot recorded, shown over the view for a moment.
     *
     * Taking one is otherwise silent, and a snapshot of the wrong spot is worse
     * than none: it sends somebody to look at a place where nothing is wrong.
     * Showing the numbers back means the person who pressed the key can see
     * they caught the thing they meant to.
     */
    const [flash, setFlash] = useState<{
        /** Bumped every time, so the same reading still restarts the fade. */
        id: number;
        lines: string[];
        status: string;
    } | null>(null);

    // Held in a ref so that re-rendering the frame never restarts the level.
    const callbacks = useRef({ onFocus, onExamine, onLockChange, onMessage });
    const held = useRef(paused);

    useEffect(() => {
        callbacks.current = { onFocus, onExamine, onLockChange, onMessage };
        held.current = paused;
    });

    /** How long the reading stays up, in milliseconds. */
    useEffect(() => {
        if (flash === null) {
            return;
        }

        const fade = window.setTimeout(
            () => setFlash(null),
            SNAPSHOT_SHOWN_FOR,
        );

        return () => window.clearTimeout(fade);
    }, [flash]);

    useEffect(() => {
        const container = containerRef.current;

        if (container === null) {
            return;
        }

        // `?scan` reads the frame back as data rather than playing the level.
        // It needs everything debug mode sets up — the painted walls to name
        // surfaces by, and a drawing buffer that survives being read — so it
        // turns debug on rather than asking for both in the address.
        const scanning = wantsScan(window.location.search);

        // `?debug` swaps the backdrop for a magenta and green check, so that
        // anything showing through a seam is a colour the art never uses. The
        // fog goes with it: fog fades a leak towards the wall colour, which is
        // the one thing that makes a sliver hard to be sure of.
        const probe =
            scanning || wantsProbeBackdrop(window.location.search)
                ? createProbeBackdrop()
                : null;

        // The scene, the camera and the renderer, and how far this level has to
        // be seen across. All of it follows from the level and from whether
        // this is a debug run.
        const {
            scene,
            camera,
            renderer,
            resize,
            dispose: disposeView,
        } = createView(level, container, probe);

        const textures = createTextureLibrary();
        const built = buildLevel(level, textures);
        scene.add(built.group);

        const legend = probe === null ? [] : paintWalls(built.group);

        if (probe !== null) {
            // The legend goes to the console rather than on screen: it is one
            // line per wall in the level, which is far too much to read over
            // the view, and it only has to be looked up once a sliver has been
            // caught in a picture.
            console.log(
                `[debug] ${legend.length} walls painted. Read a colour off the picture, round each channel to the nearest of 0/51/102/153/204/255, and look it up here.`,
            );
            console.table(
                legend.map((wall) => ({
                    css: wall.css,
                    room: wall.sector,
                    beyond: wall.beyond,
                    corner: wall.index,
                    from: `${wall.from.x},${wall.from.z}`,
                    to: `${wall.to.x},${wall.to.z}`,
                })),
            );

            // Hung on the window on purpose. Reading a row back names every
            // surface across the view and the columns each one holds, which
            // settles an argument about a two-pixel sliver that no amount of
            // squinting at a screenshot will.
            (
                window as unknown as {
                    scanRow?: (row?: number) => unknown;
                }
            ).scanRow = async (row?: number) => {
                // The drawing buffer is only guaranteed to hold this frame's
                // picture immediately after it was drawn. Read it at any other
                // moment and it can come back as whatever was last composited,
                // which reads as one flat colour across the whole row and looks
                // exactly like a wall filling the view. Wait for a fresh frame.
                // Raced against a timer, because the level stops drawing while
                // it is paused and waiting on a frame that will never come
                // would hang whoever asked rather than telling them.
                await afterAFreshFrame();

                return scanRow(
                    renderer.domElement,
                    legend,
                    row ?? Math.floor(renderer.domElement.height / 2),
                )
                    .filter((run) => run.to - run.from > 1)
                    .map((run) => ({
                        columns: `${run.from}-${run.to}`,
                        width: run.to - run.from,
                        css: run.css,
                        wall:
                            run.wall === null
                                ? 'unpainted (floor, ceiling, sprite or pane)'
                                : `${run.wall.sector} #${run.wall.index} -> ${run.wall.beyond ?? 'outside'} (${run.wall.from.x},${run.wall.from.z})-(${run.wall.to.x},${run.wall.to.z})`,
                    }));
            };
        }

        const sky =
            level.sky === null || probe !== null ? null : createSky(level.sky);

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

        // Now that there is a renderer to ask, turn on anisotropic filtering.
        textures.useRenderer(renderer);

        // A spot named in the address wins over the level's own spawn, so a
        // reported snapshot can be stood on again exactly.
        // A spot named in the address wins over the level's own spawn, so a
        // reported snapshot can be stood on again exactly.
        const player = spawnPlayer(
            level,
            spotFromSearch(window.location.search),
        );

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
        let frame = 0;
        let lastTime = performance.now();

        // On a phone there is no pointer to lock, so playing is just a flag,
        // set by the tap that starts the level and cleared by the Stop button.
        let started = false;

        /** Whether the controls are currently stood down for something on top. */
        let waiting = false;

        const isLocked = (): boolean =>
            touch ? started : document.pointerLockElement === container;

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

            turnPlayer(player, turned);

            const running =
                pressed.has('ShiftLeft') ||
                pressed.has('ShiftRight') ||
                controls.running();

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

            walkPlayer(
                player,
                { forward, strafe, running },
                { sectors: level.sectors, colliders: built.colliders, portals },
                seconds,
            );

            const standingIn = sectorAt(level.sectors, player.x, player.z);

            // A lid belongs to the room it covers and to nobody else.
            for (const lid of built.skyLids) {
                lid.mesh.visible = lid.room === standingIn?.slug;
            }

            settleEye(player, standingIn, seconds);

            const floor = standingIn?.floorHeight ?? 0;

            hands.update(seconds, player.walked, running);

            playerSprite.place(
                player.x,
                floor,
                player.z,
                player.yaw,
                player.walked,
            );
            actors.update(seconds, built.colliders);
            actors.faceViewer(player.x, player.z, player.yaw);

            aimCamera(camera, player);

            sky?.follow(player.x, player.eye, player.z);

            textures.tick(seconds);
            magic?.update(seconds);
        };

        /** One frame: move everything on, draw every pane, then draw the view. */
        const drawFrame = (seconds: number): void => {
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

        const tick = (now: number): void => {
            frame = requestAnimationFrame(tick);

            const seconds = Math.min(
                (now - lastTime) / 1000,
                MAX_FRAME_SECONDS,
            );

            lastTime = now;

            drawFrame(seconds);
        };

        /**
         * Draws the level to a standstill and reads the picture back as data.
         *
         * Its own frames, on a fixed timestep, rather than the browser's: a
         * readback has to be the same picture every time it is taken, and real
         * seconds would put the people a few centimetres further along their
         * walk on every run — a diff of two captures would then be full of
         * differences that are only the passage of time. Drawing them here also
         * means it works in a tab nobody is looking at, which gets no animation
         * frames at all and would otherwise wait for one for ever.
         *
         * The frame left on screen at the end is the frame that was read, so a
         * screenshot and the JSON are the same picture.
         */
        const scan = (): void => {
            resize();

            for (let drawn = 0; drawn < SCAN_FRAMES; drawn++) {
                drawFrame(SCAN_STEP_SECONDS);
            }

            publishScan({
                level: level.slug,
                spot:
                    new URLSearchParams(window.location.search).get('at') ??
                    'spawn',
                width: renderer.domElement.width,
                height: renderer.domElement.height,
                readings: readNow(
                    renderer.domElement,
                    legend,
                    scanRowsOf(
                        window.location.search,
                        renderer.domElement.height,
                    ),
                ),
            });
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
            // The tally the walk frame and the hands ride on. A recall is not a
            // stride, so it does not count as one.
            player.walked = 0;

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

            const reading = readingOf(spot);

            const show = (status: string): void =>
                setFlash((was) => ({
                    id: (was?.id ?? 0) + 1,
                    lines: reading,
                    status,
                }));

            show('Saving…');
            callbacks.current.onMessage?.('Saving a snapshot…');

            const failed = (why: string): void => {
                show(`Not saved — ${why}. It is in the console.`);
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

                    show(`Saved as ${name}`);
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

        // A scan is a measurement, not a game: it draws its own frames, reads
        // them back and stops. Nothing else runs.
        if (scanning) {
            scan();
        } else {
            frame = requestAnimationFrame(tick);
        }

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
            probe?.dispose();
            magic?.dispose();
            hands.dispose();
            built.dispose();
            textures.dispose();
            disposeView();
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

            {flash !== null && (
                <div
                    key={flash.id}
                    className="animate-in fade-in pointer-events-none absolute top-3 left-3 z-50 max-w-[min(20rem,70vw)] rounded-md border border-white/15 bg-black/70 px-3 py-2 font-mono text-[11px] leading-tight text-white/85 shadow-lg backdrop-blur-sm"
                >
                    <div className="mb-1 font-sans text-[10px] font-semibold tracking-widest text-emerald-300 uppercase">
                        Snapshot
                    </div>
                    {flash.lines.map((line) => (
                        <div key={line}>{line}</div>
                    ))}
                    <div className="mt-1 text-white/55">{flash.status}</div>
                </div>
            )}
        </div>
    );
}
