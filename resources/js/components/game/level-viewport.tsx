import { useEffect, useRef, useState } from 'react';
import type { ReactNode } from 'react';
import * as THREE from 'three';
import { store } from '@/actions/App/Http/Controllers/DebugSnapshotController';
import { createActors } from '@/lib/engine/actors';
import type { PropSet } from '@/lib/engine/build/things';
import { buildLevel } from '@/lib/engine/build-level';
import { captureShots } from '@/lib/engine/capture';
import { MAX_FRAME_SECONDS, REACH } from '@/lib/engine/constants';
import { createHands } from '@/lib/engine/hands';
import type { HeldItem } from '@/lib/engine/hands';
import { createInput } from '@/lib/engine/input';
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
    spotFromSearch,
    wantsProbeBackdrop,
} from '@/lib/engine/probe-backdrop';
import { prepareReflections } from '@/lib/engine/reflections';
import {
    armConsoleScan,
    publishScan,
    readNow,
    readPane,
    scanRowsOf,
    wantsScan,
} from '@/lib/engine/scan';
import { floorAt, sectorAt } from '@/lib/engine/sectors';
import { createSky } from '@/lib/engine/sky';
import { describeSpot, postSnapshot, readingOf } from '@/lib/engine/snapshot';
import { createMagic } from '@/lib/engine/spells';
import {
    createSpriteActor,
    DEFAULT_PLAYER_HEIGHT,
    HEIGHTS,
} from '@/lib/engine/sprite-actor';
import { createTextureLibrary } from '@/lib/engine/textures';
import type { TicketFields } from '@/lib/engine/ticket';
import { postTicket, ticketFromSpot } from '@/lib/engine/ticket';
import { wantsTouchControls } from '@/lib/engine/touch';
import { createView } from '@/lib/engine/view';
import { cn } from '@/lib/utils';
import type { Flags, Level, LevelThing } from '@/types';

type LevelViewportProps = {
    level: Level;
    /**
     * Which flags the saved game has set, by name.
     *
     * Only flags that have been set are here at all, so `name in flags` is the
     * honest test — a flag set to an empty string and a flag never set are
     * different states and would otherwise read alike.
     *
     * They arrive again after every interaction while the level object stays
     * the one the browser already had, which is the whole point of the closure
     * the payload puts it behind. So this is watched for a change rather than
     * read once at build.
     *
     * Optional because the map editor's preview has no saved game behind it and
     * no flags to speak of, rather than because a game might not send them.
     */
    flags?: Flags;
    /** Whatever the crosshair is resting on, or null. */
    onFocus: (thing: LevelThing | null) => void;
    onExamine: (thing: LevelThing) => void;
    onLockChange: (locked: boolean) => void;
    /** Anything the level wants to tell the player, such as a snapshot saving. */
    onMessage?: (text: string) => void;
    /**
     * Where to post "something here is wrong", or absent for no report control
     * at all — the map editor's preview has no game behind it to report about.
     */
    reportTo?: string;
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
    flags = {},
    onFocus,
    onExamine,
    onLockChange,
    onMessage,
    reportTo,
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

    /**
     * A report waiting on the one thing the game cannot capture: what upset
     * them.
     *
     * The pictures and the spot are taken the instant the key goes down, so
     * they are of the fault rather than of wherever the player drifted to while
     * finding the words. The sentence arrives afterwards.
     */
    const [report, setReport] = useState<{
        fields: TicketFields;
        shots: Record<string, Blob>;
    } | null>(null);
    const [note, setNote] = useState('');
    const [sending, setSending] = useState(false);

    // The level must stop taking keys while somebody is typing into the box,
    // or WASD walks them away from the thing they are describing. A ref
    // because the input layer reads it every frame.
    const reporting = useRef(false);

    useEffect(() => {
        reporting.current = report !== null;
    }, [report]);

    /**
     * The engine's own capture, reachable from the button.
     *
     * It lives inside the render-loop effect because it needs the renderer, the
     * scene and the player, none of which React has. A ref is how the two
     * halves meet without rebuilding the level every time a piece of state
     * changes.
     */
    const askForReport = useRef<(() => void) | null>(null);

    const reportNow = (): void => askForReport.current?.();

    const cancelReport = (): void => {
        setReport(null);
        setNote('');
    };

    const sendReport = (): void => {
        if (report === null || reportTo === undefined || sending) {
            return;
        }

        setSending(true);

        void postTicket(
            { ...report.fields, note: note.trim() },
            report.shots,
            reportTo,
        ).then((what) => {
            setSending(false);
            setReport(null);
            setNote('');

            callbacks.current.onMessage?.(
                'failed' in what
                    ? `That did not send — ${what.failed}. Please tell somebody.`
                    : what.sent,
            );
        });
    };

    /**
     * The props, once the level is built, and the flags they are showing.
     *
     * Flags arrive again after every interaction while the level object stays
     * the one the browser already had — that closure exists so a partial reload
     * never rebuilds the geometry. So a flipped switch reaches the renderer by
     * being handed to it, not by anything being made again.
     */
    const propSet = useRef<PropSet | null>(null);
    const flagsSet = useRef<ReadonlySet<string>>(new Set(Object.keys(flags)));

    useEffect(() => {
        // Only flags that have been set are in the payload at all, so presence
        // is the whole test: a flag set to an empty string and one never set
        // are different states and would otherwise read alike.
        const set = new Set(Object.keys(flags));

        flagsSet.current = set;
        propSet.current?.setFlags(set);
    }, [flags]);

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

        // Whatever the save already says, before the first frame is drawn.
        built.props.setFlags(flagsSet.current);
        propSet.current = built.props;
        scene.add(built.group);

        const legend = probe === null ? [] : paintWalls(built.group);

        if (probe !== null) {
            armConsoleScan(renderer.domElement, legend);
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
            built.props,
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

        const raycaster = new THREE.Raycaster();
        raycaster.far = REACH;
        const screenCenter = new THREE.Vector2(0, 0);

        let focusedSlug: string | null = null;
        let frame = 0;
        let lastTime = performance.now();

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
            const { push, turned } = input.read();

            turnPlayer(player, turned);

            walkPlayer(
                player,
                push,
                { sectors: level.sectors, colliders: built.colliders, portals },
                seconds,
            );

            const standingIn = sectorAt(level.sectors, player.x, player.z);

            // A lid belongs to the room it covers and to nobody else.
            for (const lid of built.skyLids) {
                lid.mesh.visible = lid.room === standingIn?.slug;
            }

            settleEye(player, standingIn, seconds);

            const floor =
                standingIn === null
                    ? 0
                    : floorAt(standingIn, player.x, player.z);

            // People wander towards a spot picked with Math.random(), so a scan
            // that let them walk would read back a different picture every run
            // — a column or two of somebody's shoulder, which is exactly the
            // size of difference the scan exists to catch. They stand where
            // they were authored instead, and everything else runs as usual on
            // its fixed timestep.
            const moving = scanning ? 0 : seconds;

            // Last frame's answer, deliberately. The raycast that finds what
            // the crosshair is on needs the camera already aimed, and aiming
            // happens further down this same function — so asking now would
            // read a camera one frame out of date to avoid an answer one frame
            // out of date. The hands take about a ninth of a second to change
            // pose anyway, which is nine frames to hide one.
            hands.update(
                seconds,
                player.walked,
                push.running,
                focusedSlug !== null,
            );

            playerSprite.place(
                player.x,
                floor,
                player.z,
                player.yaw,
                player.walked,
            );
            actors.update(moving, built.colliders);
            actors.faceViewer(player.x, player.z, player.yaw);
            built.props.update(seconds);
            built.props.faceViewer(player.x, player.z);

            aimCamera(camera, player);

            sky?.follow(player.x, player.eye, player.z);

            textures.tick(seconds);
            magic?.update(moving, built.colliders);
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
        const scan = async (): Promise<void> => {
            resize();

            // Before anything is drawn, not after: a frame read back with half
            // its textures still in flight is a different frame from the same
            // spot a second later, and which one you get is decided by the disk
            // cache. That makes a diff of two captures worth nothing, and it
            // fails intermittently, which is worse than failing.
            await textures.settled();

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
                // `?panes` also reads back what each portal is holding, which
                // is a different picture from the one on the screen whenever a
                // pane is hugged.
                ...(new URLSearchParams(window.location.search).has('panes')
                    ? {
                          panes: built.portals.flatMap((pane) =>
                              [0.06, 0.5, 0.84].map((at) => ({
                                  home: pane.home,
                                  onto: pane.onto,
                                  reading: readPane(
                                      renderer,
                                      pane,
                                      legend,
                                      0,
                                      at,
                                  ),
                              })),
                          ),
                      }
                    : {}),
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

        /**
         * A fireball, thrown from the eye along the way the player is looking.
         *
         * Only the wizard, and only with the wand in hand: the spell is the
         * staff's, not the person's, and nobody else gets so much as the keys.
         */
        const castFireball = (): void => {
            if (magic === null || hands.holding() !== 'wand') {
                return;
            }

            magic.cast(
                { x: player.x, y: player.eye - 0.15, z: player.z },
                player.yaw,
            );
        };

        const markHere = (): void => {
            if (magic === null) {
                return;
            }

            const standingIn = sectorAt(level.sectors, player.x, player.z);

            magic.mark({
                x: player.x,
                y:
                    standingIn === null
                        ? 0
                        : floorAt(standingIn, player.x, player.z),
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
                y:
                    (standingIn === null
                        ? 0
                        : floorAt(standingIn, player.x, player.z)) + 0.6,
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
                running: input.running(),
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

            void postSnapshot(spot, store().url).then((what) => {
                if ('failed' in what) {
                    failed(what.failed);

                    return;
                }

                show(`Saved as ${what.saved}`);
                callbacks.current.onMessage?.(
                    `Snapshot saved as ${what.saved}.`,
                );
            });
        };

        /**
         * "Something here is wrong."
         *
         * Takes the pictures and the spot **now**, at the moment the player
         * decided something was wrong, and asks for the words afterwards. The
         * other way round — box first, capture on submit — photographs
         * wherever they drifted to while typing, which is not the thing they
         * were looking at.
         *
         * Letting go of the mouse is part of the action rather than something
         * the box does when it appears: the player has to be able to type, and
         * a pointer lock that outlives the decision to report swallows the
         * first few letters.
         */
        const reportFault = (): void => {
            if (reportTo === undefined || reporting.current) {
                return;
            }

            const spot = describeSpot({
                level,
                x: player.x,
                z: player.z,
                eye: player.eye,
                yaw: player.yaw,
                pitch: player.pitch,
                lookingAt: focusedSlug,
                holding: hands.holding(),
                running: input.running(),
                screen: {
                    width: container.clientWidth,
                    height: container.clientHeight,
                    pixelRatio: window.devicePixelRatio,
                    touch,
                },
                takenAt: new Date().toISOString(),
            });

            document.exitPointerLock();

            void captureShots(renderer, scene, camera, built.group)
                .then(({ shots, legend }) => {
                    setReport({
                        fields: ticketFromSpot(spot, '', legend),
                        shots,
                    });
                })
                .catch(() => {
                    // A report with no pictures still carries the spot, the
                    // room and its textures, which is most of what diagnoses
                    // one. Losing the whole report because a readback failed
                    // would be the worse outcome by far.
                    setReport({
                        fields: ticketFromSpot(spot, '', null),
                        shots: {},
                    });
                });
        };

        askForReport.current = reportFault;

        const examine = (): void => {
            const thing =
                focusedSlug === null
                    ? undefined
                    : thingsBySlug.get(focusedSlug);

            if (thing !== undefined) {
                callbacks.current.onExamine(thing);
            }
        };

        // Keys, mouse, pointer lock, full screen, and the buttons a phone gets
        // instead of all of it. The level tells it what those mean; it does not
        // know itself.
        const input = createInput(container, touch, {
            examine,
            markHere: magic === null ? null : markHere,
            recall: magic === null ? null : recall,
            takeInHand,
            takeSnapshot,
            reportFault,
            fire: castFireball,
            look: (turned) => turnPlayer(player, turned),
            held: () => held.current || reporting.current,
            onLockChange: (locked) => callbacks.current.onLockChange(locked),
            onPlaying: setPlaying,
            onFullscreen: setFullscreen,
        });

        const observer = new ResizeObserver(resize);
        observer.observe(container);

        resize();

        // A scan is a measurement, not a game: it draws its own frames, reads
        // them back and stops. Nothing else runs.
        if (scanning) {
            void scan();
        } else {
            frame = requestAnimationFrame(tick);
        }

        return () => {
            cancelAnimationFrame(frame);
            observer.disconnect();
            propSet.current = null;
            input.dispose();
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
        // here never restarts the level. `reportTo` is a string built from the
        // game's slug, so it compares equal on every render and does not
        // either — it is listed because the capture closes over it, not
        // because it is expected to change.
    }, [level, touch, reportTo]);

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

            {/*
             * A button, not only a key.
             *
             * The children played for over an hour with F bound to the
             * snapshot and pressed it exactly nought times. A key you have to
             * be told about has already been tested here and scored zero, so
             * the report control is a thing on the screen that says what it
             * does. R still works for anybody who wants it.
             */}
            {reportTo !== undefined && playing && report === null && (
                <button
                    type="button"
                    onClick={reportNow}
                    title="Tell somebody something here is wrong (R)"
                    className="absolute right-3 bottom-3 z-50 rounded-full border border-amber-300/40 bg-black/60 px-4 py-2 text-xs font-semibold text-amber-100 shadow-lg backdrop-blur-sm hover:border-amber-300 hover:bg-black/80"
                >
                    Something&rsquo;s wrong
                </button>
            )}

            {report !== null && (
                <div className="absolute inset-0 z-50 flex items-end justify-center bg-black/50 p-4 sm:items-center">
                    <div className="w-full max-w-md rounded-lg border border-white/15 bg-[#0b0f16] p-4 shadow-2xl">
                        <h2 className="text-sm font-semibold text-amber-100">
                            What&rsquo;s wrong here?
                        </h2>
                        {/*
                         * Plain words, and the box says so. The people this is
                         * for are children who can see the fault and cannot
                         * name the room — and the room, the spot and the
                         * textures have already been captured, so nothing here
                         * needs them to describe *where* they are.
                         */}
                        <p className="mt-1 text-xs text-white/50">
                            Say it however you like. We already know where you
                            were standing.
                        </p>

                        <textarea
                            autoFocus
                            value={note}
                            onChange={(event) => setNote(event.target.value)}
                            rows={3}
                            maxLength={2000}
                            placeholder="The floor has a hole in it…"
                            className="mt-3 w-full rounded border border-white/15 bg-black/40 px-3 py-2 text-sm text-white/90 outline-none focus:border-amber-300/60"
                        />

                        <div className="mt-3 flex items-center justify-end gap-2">
                            <button
                                type="button"
                                onClick={cancelReport}
                                disabled={sending}
                                className="rounded border border-white/15 px-3 py-1.5 text-xs text-white/70 hover:border-white/35 disabled:opacity-40"
                            >
                                Never mind
                            </button>
                            <button
                                type="button"
                                onClick={sendReport}
                                disabled={sending || note.trim() === ''}
                                className="rounded border border-amber-300/50 bg-amber-300/10 px-3 py-1.5 text-xs font-semibold text-amber-100 hover:border-amber-300 disabled:opacity-40"
                            >
                                {sending ? 'Sending…' : 'Send'}
                            </button>
                        </div>
                    </div>
                </div>
            )}
        </div>
    );
}
