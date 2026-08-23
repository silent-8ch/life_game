import { ACTION_LINE_PASSES } from '@/lib/engine/constants';
import type { Level, LevelThing } from '@/types';

/**
 * Named lines that are on or off, the things that put them on, and the things
 * that do something while they are.
 *
 * Paul: *maybe we steal from redstone. Have an interactions level where things
 * trigger other things. Invisible non-solid things triggering more complex
 * things. All lines are either on or off and things interact with it being on
 * or off.*
 *
 * ## An action line is a flag
 *
 * One namespace, not two beside each other. A game flag is already a named line
 * that is on or off — `SetFlag` writes one, `FlagIs` reads one, and presence is
 * already the whole test. Making an action line the same name means `alt_flag` lights
 * a lamp while a line is on with nothing written for it, and an interaction can
 * be gated on an action line for free.
 *
 * The cost is one namespace with two writers, and the ruling is that **the
 * engine owns it while the level is being played**. A plate flips its line this
 * frame, not a round trip later. The save is told afterwards, and only about
 * the lines worth remembering.
 *
 * ## What is cheap about this
 *
 * Two indexes, built once. Emitters are a flat list, and there are a handful in
 * a level. Bindings are a map from line name to the things that answer it —
 * which is how the frame finds the things that care: it does not look, it is
 * told. Only action lines that **changed** are looked up, so a frame in which
 * nothing moves costs the emitter reads and one comparison.
 */

/** Somebody who can stand on a plate. */
export type Stander = {
    x: number;
    z: number;
    /** Whether this is the player rather than one of the people. */
    isPlayer: boolean;
};

/** What an action line asks of one thing, on each side. */
export type Binding = {
    /** The thing that answers, by slug. */
    slug: string;
    response: 'rotate' | 'blocking';
    on: string;
    off: string;
};

/** What the engine is asked to do when a line changes. */
export type Responders = {
    turn: (slug: string, degrees: number) => void;
    block: (slug: string, blocking: boolean) => void;
};

export type ActionLines = {
    /** Whether a line is on right now. */
    isOn: (line: string) => boolean;
    /** Every line that is on, for anything that wants the set. */
    live: () => ReadonlySet<string>;
    /**
     * A lever thrown. Returns the line it moved, or null if this thing is not
     * a lever — so the caller knows whether there is anything to tell the save.
     */
    use: (slug: string) => string | null;
    /**
     * Reads the emitters, works out what changed, and tells the responders.
     *
     * Called **after** everything has moved, so a plate answers about where
     * people are this frame rather than where they were last.
     */
    settle: (standing: Stander[], responders: Responders) => void;
    /** Puts the latching lines back to what a save says. */
    restore: (flags: ReadonlySet<string>) => void;
};

/** A thing that puts a line on, and the test for whether it is. */
type Emitter = {
    thing: LevelThing;
    line: string;
    /** A lever holds what it was last set to; a plate is asked every frame. */
    latching: boolean;
    /** Only meaningful for a lever: what it was last set to. */
    held: boolean;
};

/**
 * Whether a spot is inside a thing's footprint on the floor plan.
 *
 * The thing's own rectangle, turned by its own angle — the same shape its
 * collider is, so a plate covers exactly what it looks like it covers. A plate
 * is not solid, so nothing about this is collision; it is the same arithmetic
 * asked for a different reason.
 */
function standingOn(thing: LevelThing, x: number, z: number): boolean {
    const angle = (thing.angle * Math.PI) / 180;
    const cos = Math.cos(angle);
    const sin = Math.sin(angle);

    const offsetX = x - thing.x;
    const offsetZ = z - thing.z;

    return (
        Math.abs(offsetX * cos + offsetZ * sin) <= thing.width / 2 &&
        Math.abs(-offsetX * sin + offsetZ * cos) <= thing.depth / 2
    );
}

/** Whether this emitter cares about whoever is standing there. */
function counts(thing: LevelThing, who: Stander): boolean {
    if (thing.triggeredBy === 'anyone') {
        return true;
    }

    // Paul's ruling: actors work plates and never levers, because standing on
    // something is physical and flipping a switch is a deliberate act.
    return thing.triggeredBy === 'player' ? who.isPlayer : !who.isPlayer;
}

export function createActionLines(level: Level): ActionLines {
    const emitters: Emitter[] = level.things
        .filter(
            (thing) =>
                (thing.emits ?? null) !== null &&
                (thing.emitWhen ?? null) !== null,
        )
        .map((thing) => ({
            thing,
            line: thing.emits as string,
            latching: thing.emitWhen === 'used',
            held: false,
        }));

    /** Signal name to the things that answer it. Built once, read on change. */
    const answering = new Map<string, Binding[]>();

    for (const thing of level.things) {
        for (const binding of thing.bindings ?? []) {
            answering.set(binding.line, [
                ...(answering.get(binding.line) ?? []),
                {
                    slug: thing.slug,
                    response: binding.response,
                    on: binding.on,
                    off: binding.off,
                },
            ]);
        }
    }

    let live = new Set<string>();

    /** Puts one thing where a line being on or off asks it to be. */
    const answer = (
        binding: Binding,
        on: boolean,
        responders: Responders,
    ): void => {
        const value = on ? binding.on : binding.off;

        if (binding.response === 'rotate') {
            responders.turn(binding.slug, Number(value));

            return;
        }

        responders.block(binding.slug, value === '1' || value === 'true');
    };

    /** Every line that is on, given who is standing where. */
    const read = (standing: Stander[]): Set<string> => {
        const on = new Set<string>();

        for (const emitter of emitters) {
            const lit = emitter.latching
                ? emitter.held
                : standing.some(
                      (who) =>
                          counts(emitter.thing, who) &&
                          standingOn(emitter.thing, who.x, who.z),
                  );

            if (lit) {
                on.add(emitter.line);
            }
        }

        return on;
    };

    return {
        isOn: (line) => live.has(line),

        live: () => live,

        use: (slug) => {
            const lever = emitters.find(
                (emitter) => emitter.latching && emitter.thing.slug === slug,
            );

            if (lever === undefined) {
                return null;
            }

            lever.held = !lever.held;

            return lever.line;
        },

        settle: (standing, responders) => {
            // Settled in passes rather than once, because a thing that answers
            // a line may put another line on, and a chain has to reach the end
            // of itself inside one frame or it reads as lag.
            //
            // Bounded, and `resolveCollisions` bounds itself the same way for
            // the same reason: some arrangements never settle. A ring of things
            // driving each other is a redstone clock, which is a thing somebody
            // will build on purpose, and the honest answer to one is to stop
            // after a fixed number of passes and let it oscillate rather than
            // to hang the tab looking for a resting state it does not have.
            for (let pass = 0; pass < ACTION_LINE_PASSES; pass++) {
                const now = read(standing);

                const changed = [
                    ...[...now].filter((line) => !live.has(line)),
                    ...[...live].filter((line) => !now.has(line)),
                ];

                live = now;

                if (changed.length === 0) {
                    return;
                }

                // Only what changed, which is the whole reason this is cheap
                // enough to run every frame. Nothing looks through the level
                // for things that might care.
                for (const line of changed) {
                    for (const binding of answering.get(line) ?? []) {
                        answer(binding, now.has(line), responders);
                    }
                }
            }
        },

        restore: (flags) => {
            for (const emitter of emitters) {
                // Only the latching ones. A plate is momentary and you are not
                // standing on it next session; putting it back would open a
                // door in an empty room.
                if (emitter.latching) {
                    emitter.held = flags.has(emitter.line);
                }
            }
        },
    };
}
