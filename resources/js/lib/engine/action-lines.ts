import { ACTION_LINE_PASSES } from '@/lib/engine/constants';
import type { Level, LevelThing } from '@/types';

/**
 * Lines drawn between things, and what runs along them.
 *
 * Paul: *I need to be able to chain items. I think we need to switch to manual
 * drawing of the redstone line.*
 *
 * The first version connected things by **sharing a name**. This one connects
 * them by a line drawn from one to the other, and the difference is not
 * cosmetic: a chain of three things is now two lines and nothing typed, where
 * before it was two names invented purely to join things that were already next
 * to each other on the plan.
 *
 * ## Every thing is a node
 *
 * There is no separate kind for a wire, a gate or a relay. A thing has an
 * **input** — how the lines drawn into it combine — and an **output**, and what
 * it is depends only on which of those it has anything to say about.
 *
 * - A **plate** has no lines in and an output that follows who is standing on
 *   it.
 * - A **door** has lines in and bindings that answer them.
 * - A **relay** has lines in and lines out and nothing else, so its output is
 *   its input and a chain passes through it.
 * - A **gate** is a thing with an opinion about how its inputs combine: `all`
 *   is an AND, `none` is a NOT, `any` is what a wire does anyway.
 * - A **listener** reads a flag into its output or writes its input to a flag,
 *   and is the only bridge between drawn wiring and the name namespace.
 *
 * That list is five names for one rule, which is the point: nothing in here
 * asks what sort of thing it is holding.
 *
 * ## What is cheap about it
 *
 * The graph is built once. Each pass reads the sources, walks the edges, and
 * stops when nothing changed — and what a pass costs is the number of lines,
 * not the number of things. A level with no lines drawn costs one comparison a
 * frame.
 */

/** Somebody who can stand on a plate. */
export type Stander = {
    x: number;
    z: number;
    /** Whether this is the player rather than one of the people. */
    isPlayer: boolean;
};

/** What the engine is asked to do when a thing's input changes. */
export type Responders = {
    turn: (slug: string, degrees: number) => void;
    block: (slug: string, blocking: boolean) => void;
};

export type ActionLines = {
    /** Whether a thing's output is on right now. */
    isOn: (slug: string) => boolean;
    /**
     * A lever thrown. Hands back the flag it should be remembered as, or null
     * when there is nothing to remember — which is most things.
     */
    use: (slug: string) => string | null;
    /**
     * Reads the sources, follows the lines, and tells the responders what
     * changed.
     *
     * Called **after** everything has moved, so a plate answers about where
     * people are this frame rather than where they were last.
     */
    settle: (standing: Stander[], responders: Responders) => void;
    /** Puts the latching things back to what a save says. */
    restore: (flags: ReadonlySet<string>) => void;
    /** Which flags the listeners are writing, for the save to be told. */
    writing: () => string[];
};

/** One thing, as the graph sees it. */
type Node = {
    thing: LevelThing;
    /** Things with a line drawn into this one. */
    from: string[];
    /** Whether it is holding its own output on: a lever that has been thrown. */
    held: boolean;
    /** Whether its output is on. */
    out: boolean;
};

/**
 * Whether a spot is inside a thing's footprint on the floor plan.
 *
 * The thing's own rectangle, turned by its own angle — the same shape its
 * collider is, so a plate covers exactly what it looks like it covers. A plate
 * is not solid, so nothing here is collision; it is the same arithmetic asked
 * for a different reason.
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

/** Whether this thing cares about whoever is standing there. */
function counts(thing: LevelThing, who: Stander): boolean {
    if (thing.triggeredBy === 'anyone') {
        return true;
    }

    // Paul's ruling: actors stand on plates and never throw levers. Standing on
    // something is physical; flipping a switch is a deliberate act.
    return thing.triggeredBy === 'player' ? who.isPlayer : !who.isPlayer;
}

export function createActionLines(level: Level): ActionLines {
    const nodes = new Map<string, Node>();

    for (const thing of level.things) {
        nodes.set(thing.slug, { thing, from: [], held: false, out: false });
    }

    for (const line of level.lines ?? []) {
        nodes.get(line.to)?.from.push(line.from);
    }

    /** Flags the level has been handed, for listeners that read one. */
    let known: ReadonlySet<string> = new Set<string>();

    /** How the lines drawn into a thing combine. */
    const inputOf = (node: Node): boolean => {
        const on = node.from.map((slug) => nodes.get(slug)?.out === true);

        if (node.thing.logic === 'all') {
            return on.length > 0 && on.every(Boolean);
        }

        if (node.thing.logic === 'none') {
            // A NOT of nothing is a thing that is always on, which redstone
            // calls a torch. Not a mistake, and somebody will find it before
            // anybody documents it.
            return !on.some(Boolean);
        }

        return on.some(Boolean);
    };

    /**
     * What a thing puts out.
     *
     * A source answers for itself and ignores whatever is drawn into it; a
     * lever is not made to change its mind by a wire. Everything else passes
     * its input on, which is what makes a plain thing a relay without being
     * told to be one.
     */
    const outputOf = (node: Node, standing: Stander[]): boolean => {
        if (node.thing.emitWhen === 'used') {
            return node.held;
        }

        if (node.thing.emitWhen === 'stood_on') {
            return standing.some(
                (who) =>
                    counts(node.thing, who) &&
                    standingOn(node.thing, who.x, who.z),
            );
        }

        if (node.thing.readsFlag !== null) {
            return known.has(node.thing.readsFlag);
        }

        return inputOf(node);
    };

    /** Puts one thing where its input being on or off asks it to be. */
    const answer = (node: Node, on: boolean, responders: Responders): void => {
        for (const binding of node.thing.bindings ?? []) {
            const value = on ? binding.on : binding.off;

            if (binding.response === 'rotate') {
                responders.turn(node.thing.slug, Number(value));

                continue;
            }

            responders.block(
                node.thing.slug,
                value === '1' || value === 'true',
            );
        }
    };

    return {
        isOn: (slug) => nodes.get(slug)?.out === true,

        use: (slug) => {
            const node = nodes.get(slug);

            if (node === undefined || node.thing.emitWhen !== 'used') {
                return null;
            }

            node.held = !node.held;

            // What the save is told is the flag a listener downstream writes,
            // not the lever — because a lever has no name any more. That is
            // worked out on the next settle; here we only say that something
            // moved.
            return slug;
        },

        settle: (standing, responders) => {
            // Passes rather than one sweep, because a line's far end may be the
            // near end of another and a chain has to reach its own end inside
            // one frame or it reads as lag.
            //
            // Bounded, and `resolveCollisions` bounds itself the same way for
            // the same reason: some arrangements never settle. A ring of things
            // driving each other is a redstone clock, which somebody will build
            // on purpose, and the honest answer is to stop after a fixed number
            // of passes and let it oscillate rather than to hang the tab
            // looking for a rest it does not have.
            for (let pass = 0; pass < ACTION_LINE_PASSES; pass++) {
                let moved = false;

                for (const node of nodes.values()) {
                    const was = node.out;
                    const now = outputOf(node, standing);

                    if (now === was) {
                        continue;
                    }

                    node.out = now;
                    moved = true;
                }

                if (!moved) {
                    return;
                }

                // Only things whose own input changed are told, and a thing
                // with no bindings is told nothing whatever its input does.
                for (const node of nodes.values()) {
                    if ((node.thing.bindings ?? []).length > 0) {
                        answer(node, inputOf(node), responders);
                    }
                }
            }
        },

        restore: (flags) => {
            known = flags;

            for (const node of nodes.values()) {
                // Only the latching ones. A plate is momentary: you are not
                // standing on it next session, and putting it back would open a
                // door in an empty room.
                if (node.thing.emitWhen === 'used') {
                    node.held = flags.has(`lever:${node.thing.slug}`);
                }
            }
        },

        writing: () =>
            [...nodes.values()]
                .filter(
                    (node) => node.thing.writesFlag !== null && inputOf(node),
                )
                .map((node) => node.thing.writesFlag as string),
    };
}
