import type { Level } from '@/types';

/**
 * The editor's way back. A draft is a whole level, so the history is simply a
 * bounded run of drafts as they stood before each edit: undo hands back the
 * one before, redo hands back the one that was undone away.
 *
 * The draft in hand is not kept here — the editor holds it — so a history is
 * only ever asked to step, and is given the present to put on the other pile.
 */

/**
 * How many drafts back the editor can go. A level of any size serialises to
 * tens of kilobytes, so forty of them is a couple of megabytes at worst, and
 * forty steps is well past the point where a bad carve is easier to redraw
 * than to unpick.
 */
export const HISTORY_LIMIT = 40;

export type LevelHistory = {
    /** Drafts as they were before each edit, oldest first. */
    past: Level[];
    /** Drafts undone away, the nearest one first. */
    future: Level[];
};

export const EMPTY_HISTORY: LevelHistory = { past: [], future: [] };

/**
 * What kind of edit a change is, so that the ones made a bit at a time — a
 * corner dragged across the plan, a name typed into a field — land in the
 * history as one step rather than one per pointer move or keystroke.
 */
export type EditKind =
    | 'carve'
    | 'corner'
    | 'delete'
    | 'duplicate'
    | 'field'
    | 'heights'
    | 'line'
    | 'nudge'
    | 'place'
    | 'revert'
    | 'spawn'
    | 'split'
    | 'stairs'
    | 'thing';

/** The kinds that arrive as a stream of small changes rather than in one go. */
const CONTINUOUS: ReadonlySet<EditKind> = new Set<EditKind>([
    'corner',
    'field',
    'heights',
    'nudge',
    'spawn',
    'thing',
]);

export function isContinuousEdit(kind: EditKind): boolean {
    return CONTINUOUS.has(kind);
}

/**
 * Files the draft as it was before an edit, and drops the redo pile: once the
 * level has been taken somewhere new there is nothing to go forward to.
 */
export function remember(
    history: LevelHistory,
    before: Level,
    limit: number = HISTORY_LIMIT,
): LevelHistory {
    const past = [...history.past, before];

    return { past: limit > 0 ? past.slice(-limit) : [], future: [] };
}

/** A step taken: the history after it, and the draft it puts back in hand. */
export type HistoryStep = { history: LevelHistory; level: Level };

export function undo(
    history: LevelHistory,
    present: Level,
): HistoryStep | null {
    const previous = history.past.at(-1);

    if (previous === undefined) {
        return null;
    }

    return {
        history: {
            past: history.past.slice(0, -1),
            future: [present, ...history.future],
        },
        level: previous,
    };
}

export function redo(
    history: LevelHistory,
    present: Level,
): HistoryStep | null {
    const [next, ...rest] = history.future;

    if (next === undefined) {
        return null;
    }

    return {
        history: { past: [...history.past, present], future: rest },
        level: next,
    };
}
