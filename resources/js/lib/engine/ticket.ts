import type { WallPaint } from '@/lib/engine/probe-backdrop';
import { guardHeaders } from '@/lib/engine/snapshot';
import type { Snapshot } from '@/lib/engine/snapshot';

/**
 * "This is wrong", sent from inside the game or the editor.
 *
 * A snapshot already says **where** somebody was standing to the centimetre and
 * has never once said **what upset them**. That is the whole gap this closes:
 * the reports that matter come from children who can see the fault and cannot
 * name the room, and until now the only way their words reached anybody was
 * somebody standing behind them relaying it out loud — which costs an adult the
 * whole session and fails silently the moment a child snaps something and says
 * nothing.
 *
 * So a ticket is a snapshot plus a sentence plus the pictures. The snapshot half
 * is not gathered again here: `describeSpot()` assembles it for the debug
 * snapshot already, and a second gatherer would be a second thing to keep in
 * step with the engine.
 */

/** What the editor was doing, which is its version of "where you were". */
export type EditorState = {
    tool?: string;
    selection?: string | null;
    rooms?: number;
    history?: number;
    /**
     * Whether there were changes the server had not seen.
     *
     * The most useful single fact on a UI report: the same complaint means a
     * different thing said against unsaved work than against what was saved.
     */
    unsaved?: boolean;
    saving?: boolean;
    drawing?: boolean;
    grid?: number;
};

export type TicketSent = { sent: string } | { failed: string };

/**
 * A ticket's fields, as the endpoint names them.
 *
 * `standingIn` is the room object rather than its slug, because a ticket that
 * records a room's *name* and not what it is *made of* cannot diagnose a wrong
 * or missing surface — and a wrong surface is what an evening went into
 * chasing. `nearby` is the snapshot's `edgesNearby` under the name the server
 * uses; that rename is the one place the two shapes disagree, and it is done
 * here rather than left for whoever wires the next client.
 */
export type TicketFields = {
    source: 'play' | 'editor';
    level: string | null;
    note: string;
    at: Snapshot['at'] | null;
    standingIn: Snapshot['standingIn'];
    lookingAt: string | null;
    holding: string | null;
    running: boolean;
    screen: Snapshot['screen'];
    nearby: Snapshot['edgesNearby'];
    legend: WallPaint[] | null;
    editorState: EditorState | null;
};

/**
 * A ticket built from the spot the engine already described.
 *
 * Everything the endpoint wants about a position, a room and its surroundings
 * is in a `Snapshot` — this only renames `edgesNearby` and attaches the note
 * and the legend.
 */
export function ticketFromSpot(
    spot: Snapshot,
    note: string,
    legend: WallPaint[] | null = null,
): TicketFields {
    return {
        source: 'play',
        level: spot.level.slug,
        note,
        at: spot.at,
        standingIn: spot.standingIn,
        lookingAt: spot.lookingAt,
        holding: spot.holding,
        running: spot.running,
        screen: spot.screen,
        nearby: spot.edgesNearby,
        legend,
        editorState: null,
    };
}

/**
 * Writes one value into a form under PHP's bracket notation.
 *
 * The reason this is not `JSON.stringify` under a single key, which is the
 * obvious thing and is wrong: a multipart body carries strings, and the
 * endpoint's rules say `'at' => ['nullable', 'array']`. A JSON string is not an
 * array, so every nested field would fail validation — and it would fail at the
 * worst possible moment, after somebody has stopped playing, typed out what was
 * wrong and pressed send. `at[x]=2.5` is what PHP reassembles into an array.
 *
 * Nulls are **left out entirely** rather than written. `FormData` has one type,
 * string, so a null becomes the four characters `null` — and a player standing
 * outside every room would report standing in a room *called* null.
 */
function put(form: FormData, key: string, value: unknown): void {
    if (value === null || value === undefined) {
        return;
    }

    if (Array.isArray(value)) {
        value.forEach((item, index) => put(form, `${key}[${index}]`, item));

        return;
    }

    if (typeof value === 'object') {
        for (const [name, item] of Object.entries(value)) {
            put(form, `${key}[${name}]`, item);
        }

        return;
    }

    // Booleans as 1 and 0, not "true" and "false": PHP's boolean validation
    // takes the former, and the string "false" is truthy in every language
    // that would read it by accident.
    form.set(
        key,
        typeof value === 'boolean' ? (value ? '1' : '0') : String(value),
    );
}

/**
 * Flattens a ticket into a form, because it carries files.
 */
export function ticketForm(
    fields: TicketFields,
    shots: Record<string, Blob>,
): FormData {
    const form = new FormData();

    for (const [key, value] of Object.entries(fields)) {
        put(form, key, value);
    }

    for (const [kind, blob] of Object.entries(shots)) {
        // Named for the view rather than numbered, because the server files
        // them by name and the admin panel reads them back the same way.
        form.set(`shots[${kind}]`, blob, `${kind}.png`);
    }

    return form;
}

/**
 * Sends a ticket and says what happened.
 *
 * Never throws, for the same reason `postSnapshot` does not: the person who
 * raised it has already stopped playing to tell us something, and an exception
 * escaping into the render loop would take the level down over a report about
 * a wall. A ticket that could not be sent is worth saying so about; it is not
 * worth losing the game to.
 */
export async function postTicket(
    fields: TicketFields,
    shots: Record<string, Blob>,
    url: string,
): Promise<TicketSent> {
    let answer: Response;

    try {
        answer = await fetch(url, {
            method: 'POST',
            credentials: 'same-origin',
            // No Content-Type: the browser sets it, and it has to include the
            // multipart boundary it just generated. Setting it by hand here is
            // the classic way to make every upload arrive empty.
            headers: { Accept: 'application/json', ...guardHeaders() },
            body: ticketForm(fields, shots),
        });
    } catch {
        return { failed: 'the server did not answer' };
    }

    if (!answer.ok) {
        return {
            failed:
                answer.status === 422
                    ? 'the server would not take it'
                    : `the server said ${answer.status}`,
        };
    }

    return { sent: 'Thank you — somebody will look at it.' };
}
