import { guardHeaders } from '@/lib/engine/snapshot';

/**
 * What the save file is told about the wiring.
 *
 * A drawn line is worked out again from scratch every frame, so almost none of
 * it is worth remembering: a plate is not still being stood on next session,
 * and a door that opened because of one opens again the moment somebody steps
 * back on it. The exception is a **listener** — the one node that writes a flag
 * — because a flag is how the rest of the game finds out anything happened, and
 * a flag outlives the frame.
 *
 * So this watches one list: the flags the listeners are currently writing. When
 * it changes, the change is sent, and nothing else ever is.
 *
 * ## Why the diff and not the list
 *
 * The endpoint takes one flag and whether it is on, deliberately: it is the
 * narrowest thing the browser is allowed to say, and a whole-list endpoint
 * would be a flag-setting endpoint by another name. Sending the difference is
 * also what keeps a lever to one request — the list is recomputed sixty times a
 * second and is the same list every time.
 */
export type LineSaver = {
    /**
     * The flags the listeners are writing this frame. Sends whatever changed
     * since the last frame, and nothing at all when nothing did — which is
     * every frame but the ones somebody did something in.
     */
    push: (writing: readonly string[]) => void;
};

/** What a saver does with each change, so a test need not have a network. */
export type SendFlag = (flag: string, on: boolean) => void;

/**
 * Watches the written flags and reports the changes.
 *
 * `already` is what the save file says right now, so a level loaded with a
 * lamp already lit sends nothing until somebody turns it off. Only the flags
 * this level's listeners could write are worth passing in: a flag set by an
 * interaction is not this list's business, and treating one as though it were
 * would switch it off the first time the level was walked into.
 */
export function createLineSaver(
    already: ReadonlySet<string>,
    send: SendFlag,
): LineSaver {
    let sent = new Set(already);

    return {
        push: (writing) => {
            const now = new Set(writing);

            for (const flag of now) {
                if (!sent.has(flag)) {
                    send(flag, true);
                }
            }

            for (const flag of sent) {
                if (!now.has(flag)) {
                    send(flag, false);
                }
            }

            sent = now;
        },
    };
}

/**
 * Posts one flag change to the save file.
 *
 * Never throws, for the reason every other post from inside the game does not:
 * this is called from the frame loop, and an exception escaping it would take
 * the level down over a lamp. A change that could not be sent is lost, which is
 * the right loss — the alternative is a queue that replays a stale flag over
 * whatever the player did next.
 */
export function postFlag(url: string, level: string): SendFlag {
    return (flag, on) => {
        void fetch(url, {
            method: 'POST',
            credentials: 'same-origin',
            headers: {
                'Content-Type': 'application/json',
                Accept: 'application/json',
                ...guardHeaders(),
            },
            body: JSON.stringify({ level, flag, on }),
        }).catch(() => undefined);
    };
}
