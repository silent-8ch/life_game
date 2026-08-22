/**
 * Everything the level makes a noise about: the player's own footsteps, and the
 * loop a room plays under them.
 *
 * Two decisions shape the whole module.
 *
 * The first is that footsteps are counted in metres, not in seconds. The same
 * tally of distance walked that picks the sprite's walk frame and swings the
 * hands (`hands.ts`, `STRIDE`) decides when a foot lands, so a step is heard
 * exactly when a step is drawn — at any pace, on a stuttering frame, and after
 * a teleport. A timer would drift away from the picture the moment the player
 * changed speed.
 *
 * The second is that nothing here is required to exist. There are no audio
 * files in the repository at the time of writing: a name that resolves to
 * nothing is remembered as silent and never asked for again, so a level with no
 * sound behaves exactly as it did before, with no errors in the console and no
 * cost per step.
 *
 * Files live under public/audio, the same way textures live under
 * public/sprites/textures — drop one in and it is found by name:
 *
 *   public/audio/steps/{surface}.mp3   one per surface in SURFACES, plus default
 *   public/audio/ambience/{name}.mp3   whatever a room's `ambience` names
 *
 * Plain `Audio` elements rather than the Web Audio API. What is wanted is a
 * short sample now and a loop underneath it; a graph of nodes buys nothing for
 * that and costs a decode step and a context to keep alive.
 */

/**
 * One full swing of the arms, in metres walked. The same figure as `STRIDE` in
 * hands.ts, and it has to stay the same figure: a step heard off the swing it
 * belongs to reads as somebody else walking in the room.
 */
const STRIDE = 1.1;

/** A stride is two footfalls, one per foot. */
export const STEP_METRES = STRIDE / 2;

/**
 * Where in the swing a foot lands, in metres.
 *
 * The hands are at their furthest forward and back a quarter of a stride either
 * side of the middle, and that is the moment the opposite foot is planted. Land
 * the sound on the crossings instead and it falls between the strides.
 */
const STEP_OFFSET = STRIDE / 4;

/** How loud, out of 1. Quiet enough to leave a conversation audible over it. */
const STEP_VOLUME = 0.32;
const AMBIENCE_VOLUME = 0.22;

/** How long a room's loop takes to come up, or the last one to go, in seconds. */
const FADE_SECONDS = 1.2;

/**
 * How many copies of one step sound are kept, so two footfalls close together
 * do not cut each other off. One element cannot play over itself.
 */
const STEP_VOICES = 3;

/** How much the pitch of a step wanders, either way, as a fraction. */
const STEP_WOBBLE = 0.08;

const STEPS_PATH = '/audio/steps';
const AMBIENCE_PATH = '/audio/ambience';

/** What a surface is called when its floor texture names nothing recognised. */
export const DEFAULT_SURFACE = 'default';

/**
 * Which sound a floor texture walks like, by the words in its name.
 *
 * A file per texture would be sixty-five recordings of somebody walking, most
 * of them indistinguishable; a file per surface is eleven, and the ear cannot
 * tell oak from parquet anyway. The first entry whose word appears in the
 * texture's name wins, so the order matters where a name carries two of them —
 * `dock-planks` is wood before it is a path, and `pool-water` is water before
 * it is anything else.
 *
 * A texture nobody has classified falls to DEFAULT_SURFACE rather than being
 * silent, so a new texture is still walked on audibly.
 */
const SURFACES: [word: string, surface: string][] = [
    ['water', 'water'],
    ['snow', 'snow'],
    ['ice', 'snow'],
    ['plank', 'wood'],
    ['deck', 'wood'],
    ['wood', 'wood'],
    ['parquet', 'wood'],
    ['oak', 'wood'],
    ['carpet', 'carpet'],
    ['rug', 'carpet'],
    ['blanket', 'carpet'],
    ['grass', 'grass'],
    ['clover', 'grass'],
    ['moss', 'grass'],
    ['leaves', 'grass'],
    ['needles', 'grass'],
    ['flower', 'grass'],
    ['thatch', 'grass'],
    ['gravel', 'gravel'],
    ['pebble', 'gravel'],
    ['soil', 'soil'],
    ['mud', 'soil'],
    ['garden', 'soil'],
    ['tile', 'tile'],
    ['linoleum', 'tile'],
    ['mosaic', 'tile'],
    ['metal', 'metal'],
    ['brick', 'stone'],
    ['stone', 'stone'],
    ['slate', 'stone'],
    ['marble', 'stone'],
    ['concrete', 'stone'],
    ['asphalt', 'stone'],
    ['path', 'stone'],
];

/** Every step sound a level can ask for, which is the list of files to supply. */
export const SURFACE_SOUNDS: string[] = [
    DEFAULT_SURFACE,
    ...new Set(SURFACES.map(([, surface]) => surface)),
];

/**
 * What the floor underfoot sounds like.
 *
 * @param  texture  The sector's floor texture, or null where it has none.
 */
export function surfaceOf(texture: string | null): string {
    if (texture === null) {
        return DEFAULT_SURFACE;
    }

    const name = texture.toLowerCase();
    const found = SURFACES.find(([word]) => name.includes(word));

    return found?.[1] ?? DEFAULT_SURFACE;
}

/**
 * Counts footfalls off the tally of metres walked.
 *
 * Kept apart from anything that makes a noise so the timing can be tested: it
 * is arithmetic on one number, and everything that could go wrong with it —
 * firing twice for one step, missing one across a long frame, letting a
 * teleport back to the start fire a burst of them — is arithmetic too.
 */
export type Pace = {
    /**
     * @param  walked  Total metres walked, as the engine has counted them.
     * @returns  How many feet landed since the last call. Usually 0 or 1; more
     *   only if the caller skipped a long way, and never less than 0.
     */
    advance: (walked: number) => number;
    /** Back to the start, without pretending a stride happened. */
    reset: () => void;
};

export function createPace(
    metres: number = STEP_METRES,
    offset: number = STEP_OFFSET,
): Pace {
    /** Which footfall the tally has reached, counting from the start. */
    const at = (walked: number): number =>
        Math.floor((walked - offset) / metres);

    let taken = at(0);

    return {
        advance: (walked) => {
            const due = at(walked);

            // Walking backwards past a footfall is not a footfall, and the
            // tally is reset outright when the player is carried somewhere
            // else — so this catches up quietly rather than firing.
            if (due <= taken) {
                taken = due;

                return 0;
            }

            const fell = due - taken;
            taken = due;

            return fell;
        },

        reset: () => {
            taken = at(0);
        },
    };
}

export type GameAudio = {
    /** Whether the player has turned the sound off. */
    muted: () => boolean;
    /** @returns  Whether it is now muted, for whoever wants to say so. */
    toggleMute: () => boolean;
    /**
     * Let sound start. Browsers refuse to play anything until the page has been
     * interacted with, so this belongs on the click that locks the pointer and
     * nowhere earlier.
     */
    start: () => void;
    /** The player has stopped playing: the room goes quiet. */
    stop: () => void;
    /**
     * @param  seconds  Since the last frame, for the fade between rooms.
     * @param  walked  Metres walked, the same tally the hands swing on.
     * @param  floorTexture  What is underfoot, for which step to play.
     * @param  ambience  The room's loop, or null where it has none.
     */
    update: (
        seconds: number,
        walked: number,
        floorTexture: string | null,
        ambience: string | null,
    ) => void;
    dispose: () => void;
};

/** Where the choice is remembered between visits. */
const MUTED_KEY = 'life-game:muted';

function wasMuted(): boolean {
    try {
        return window.localStorage.getItem(MUTED_KEY) === '1';
    } catch {
        // Private windows and locked-down browsers throw rather than answer.
        return false;
    }
}

function rememberMuted(muted: boolean): void {
    try {
        window.localStorage.setItem(MUTED_KEY, muted ? '1' : '0');
    } catch {
        // Nothing to be done, and nothing that needs saying.
    }
}

/**
 * One sound, loaded once and played from more than one element so that it can
 * overlap itself. A file that is not there marks itself dead on the first
 * failure and is never asked for again.
 */
type Voices = {
    play: (volume: number, rate: number) => void;
    dispose: () => void;
};

function loadVoices(url: string, copies: number): Voices {
    let alive = true;

    const elements: HTMLAudioElement[] = [];

    for (let index = 0; index < copies; index += 1) {
        const element = new Audio(url);

        element.preload = 'auto';
        // A file that is not there is a level with no footsteps, not an error.
        element.addEventListener('error', () => {
            alive = false;
        });

        elements.push(element);
    }

    let next = 0;

    return {
        play: (volume, rate) => {
            if (!alive) {
                return;
            }

            const element = elements[next];
            next = (next + 1) % elements.length;

            // Everything here can throw on an element whose source never
            // arrived, and there is exactly one sensible answer to that: this
            // sound does not exist, so stop asking for it.
            try {
                element.volume = volume;
                element.playbackRate = rate;
                element.currentTime = 0;

                const started: unknown = element.play();

                if (started instanceof Promise) {
                    started.catch(() => {
                        alive = false;
                    });
                }
            } catch {
                alive = false;
            }
        },

        dispose: () => {
            alive = false;

            for (const element of elements) {
                element.pause();
                element.removeAttribute('src');
            }
        },
    };
}

/** A room's loop, and how far up it currently is. */
type Loop = {
    element: HTMLAudioElement;
    /** 0 silent, 1 all the way up. Faded rather than switched. */
    level: number;
    alive: boolean;
};

export function createAudio(): GameAudio {
    const pace = createPace();
    const steps = new Map<string, Voices>();
    const loops = new Map<string, Loop>();

    let muted = wasMuted();
    let playing = false;
    /** Which room's loop is meant to be up. Everything else fades away. */
    let wanted: string | null = null;
    /** Which foot: the two are pitched a hair apart so a walk is not a metronome. */
    let foot = 0;

    const stepFor = (surface: string): Voices => {
        const found = steps.get(surface);

        if (found !== undefined) {
            return found;
        }

        const made = loadVoices(`${STEPS_PATH}/${surface}.mp3`, STEP_VOICES);
        steps.set(surface, made);

        return made;
    };

    const loopFor = (name: string): Loop => {
        const found = loops.get(name);

        if (found !== undefined) {
            return found;
        }

        const element = new Audio(`${AMBIENCE_PATH}/${name}.mp3`);

        element.loop = true;
        element.preload = 'auto';
        element.volume = 0;

        const made: Loop = { element, level: 0, alive: true };

        element.addEventListener('error', () => {
            made.alive = false;
        });

        loops.set(name, made);

        return made;
    };

    /** Bring the wanted loop up and everything else down, a frame at a time. */
    const fade = (seconds: number): void => {
        // A straight ramp rather than an ease towards it: an ease never quite
        // arrives, and a room tone that takes five seconds to become properly
        // inaudible is still there under the next room's.
        const by = seconds / FADE_SECONDS;

        for (const [name, loop] of loops) {
            const up = playing && !muted && name === wanted ? 1 : 0;

            loop.level =
                up > loop.level
                    ? Math.min(up, loop.level + by)
                    : Math.max(up, loop.level - by);

            if (!loop.alive) {
                continue;
            }

            if (loop.level < 0.01) {
                loop.level = 0;

                if (!loop.element.paused) {
                    loop.element.pause();
                }

                continue;
            }

            loop.element.volume = loop.level * AMBIENCE_VOLUME;

            if (loop.element.paused) {
                const started: unknown = loop.element.play();

                if (started instanceof Promise) {
                    started.catch(() => {
                        loop.alive = false;
                    });
                }
            }
        }
    };

    const silence = (): void => {
        for (const loop of loops.values()) {
            loop.level = 0;
            loop.element.pause();
        }
    };

    return {
        muted: () => muted,

        toggleMute: () => {
            muted = !muted;
            rememberMuted(muted);

            if (muted) {
                silence();
            }

            return muted;
        },

        start: () => {
            playing = true;
        },

        stop: () => {
            playing = false;
            silence();
        },

        update: (seconds, walked, floorTexture, ambience) => {
            // Counted whether or not anything is heard, so that unmuting or
            // starting again picks up mid-stride rather than firing at once.
            const fell = pace.advance(walked);

            wanted = ambience;

            // Loaded the first time a room that has one is walked into, and not
            // before: a level with a loop in a room nobody visits never fetches
            // it, and a muted game never fetches any of them.
            if (ambience !== null && playing && !muted) {
                loopFor(ambience);
            }

            fade(seconds);

            if (fell < 1 || !playing || muted) {
                return;
            }

            foot = 1 - foot;

            // However many were missed, one foot lands. A frame long enough to
            // cover two strides is a stall, and a stall should not be answered
            // with a clatter of footsteps.
            stepFor(surfaceOf(floorTexture)).play(
                STEP_VOLUME,
                1 + (foot === 0 ? -STEP_WOBBLE : STEP_WOBBLE),
            );
        },

        dispose: () => {
            playing = false;

            for (const voices of steps.values()) {
                voices.dispose();
            }

            for (const loop of loops.values()) {
                loop.element.pause();
                loop.element.removeAttribute('src');
            }

            steps.clear();
            loops.clear();
        },
    };
}
