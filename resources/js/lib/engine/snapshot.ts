import {
    boundaryKey,
    ceilingAt,
    edgesOf,
    floorAt,
    sectorAt,
} from '@/lib/engine/sectors';
import type { Level } from '@/types';

/**
 * A written account of where the player is standing and what is around them.
 *
 * For sending back a spot that looks wrong, so it can be stood on again exactly
 * rather than guessed at. Everything here is a number or a name: no pictures,
 * nothing that needs the renderer, and nothing that cannot be read in a
 * terminal.
 *
 * The part that earns its keep is `edgesNearby` — how far the eye is from each
 * room boundary within arm's reach, and which rooms are either side of it. A
 * flicker at a doorway is almost always something deciding differently from one
 * frame to the next about which side of a line the eye is on, and that is the
 * list that shows it.
 */

/** How far around the player to bother reporting boundaries, in metres. */
const NEARBY = 1.5;

export type NearbyEdge = {
    /** Signed: positive on the side the first room lies. */
    distance: number;
    from: { x: number; z: number };
    to: { x: number; z: number };
    rooms: string[];
    /** Whether the player could walk across it, as far as the flags go. */
    open: boolean;
};

export type Snapshot = {
    takenAt: string;
    level: { slug: string; name: string };
    /** Where the eye is, and which way it points. Angles in degrees. */
    at: { x: number; z: number; eye: number; yaw: number; pitch: number };
    standingIn: {
        slug: string;
        name: string;
        /**
         * The floor and ceiling **under this spot**, not the room's base
         * heights. On a sloped room those are the same number only along the
         * hinge wall, and a snapshot exists to report where somebody actually
         * stood.
         */
        floorHeight: number;
        ceilingHeight: number;
        isSky: boolean;
        isWater: boolean;
        wallTexture: string | null;
        floorTexture: string | null;
        ceilingTexture: string | null;
    } | null;
    edgesNearby: NearbyEdge[];
    /** What the crosshair was resting on, if anything. */
    lookingAt: string | null;
    holding: string | null;
    running: boolean;
    screen: {
        width: number;
        height: number;
        pixelRatio: number;
        touch: boolean;
    };
    note: string;
};

function round(value: number, places = 4): number {
    return Number(value.toFixed(places));
}

export type SpotToDescribe = {
    level: Level;
    x: number;
    z: number;
    eye: number;
    /** Radians, as the engine holds them. */
    yaw: number;
    pitch: number;
    lookingAt: string | null;
    holding: string | null;
    running: boolean;
    screen: {
        width: number;
        height: number;
        pixelRatio: number;
        touch: boolean;
    };
    note?: string;
    takenAt: string;
};

export function describeSpot(spot: SpotToDescribe): Snapshot {
    const { level, x, z } = spot;
    const room = sectorAt(level.sectors, x, z);

    // Every boundary within reach, nearest first, each named once however many
    // sectors share it.
    const seen = new Map<string, NearbyEdge>();

    for (const edge of edgesOf(level.sectors)) {
        const spanX = edge.to.x - edge.from.x;
        const spanZ = edge.to.z - edge.from.z;
        const length = Math.hypot(spanX, spanZ);

        if (length < 1e-6) {
            continue;
        }

        const fraction =
            ((x - edge.from.x) * spanX + (z - edge.from.z) * spanZ) /
            (length * length);

        if (fraction < 0 || fraction > 1) {
            continue;
        }

        const away =
            ((x - edge.from.x) * -spanZ + (z - edge.from.z) * spanX) / length;

        if (Math.abs(away) > NEARBY) {
            continue;
        }

        const key = boundaryKey(edge.from, edge.to);
        const already = seen.get(key);

        if (already !== undefined) {
            already.rooms = [...new Set([...already.rooms, edge.sector.slug])];
            already.open =
                already.open &&
                !edge.from.blocks &&
                !(edge.beyondFrom?.blocks ?? false);

            continue;
        }

        seen.set(key, {
            distance: round(away),
            from: { x: edge.from.x, z: edge.from.z },
            to: { x: edge.to.x, z: edge.to.z },
            rooms: [
                edge.sector.slug,
                ...(edge.beyond === null ? [] : [edge.beyond.slug]),
            ],
            open:
                edge.beyond !== null &&
                !edge.from.blocks &&
                !(edge.beyondFrom?.blocks ?? false),
        });
    }

    return {
        takenAt: spot.takenAt,
        level: { slug: level.slug, name: level.name },
        at: {
            x: round(x),
            z: round(z),
            eye: round(spot.eye),
            yaw: round((spot.yaw * 180) / Math.PI, 2),
            pitch: round((spot.pitch * 180) / Math.PI, 2),
        },
        standingIn:
            room === null
                ? null
                : {
                      slug: room.slug,
                      name: room.name,
                      floorHeight: round(floorAt(room, spot.x, spot.z), 3),
                      ceilingHeight: round(ceilingAt(room, spot.x, spot.z), 3),
                      isSky: room.isSky,
                      isWater: room.isWater,
                      wallTexture: room.wallTexture,
                      floorTexture: room.floorTexture,
                      ceilingTexture: room.ceilingTexture,
                  },
        edgesNearby: [...seen.values()].sort(
            (a, b) => Math.abs(a.distance) - Math.abs(b.distance),
        ),
        lookingAt: spot.lookingAt,
        holding: spot.holding,
        running: spot.running,
        screen: spot.screen,
        note: spot.note ?? '',
    };
}

/**
 * The few lines of a snapshot worth showing back to the person who took it.
 *
 * Taking one is otherwise silent, and a snapshot of the wrong spot is worse
 * than none: it sends somebody to look at a place where nothing is wrong. The
 * nearest boundary is the line that earns its place — almost every fault worth
 * snapping is a doorway or a portal mouth, and how far off it the reading was
 * taken is what makes the spot reproducible.
 */
export function readingOf(snapshot: Snapshot): string[] {
    const closest = snapshot.edgesNearby[0];

    return [
        snapshot.standingIn === null
            ? 'Outside any room'
            : `${snapshot.standingIn.name} (${snapshot.standingIn.slug})`,
        `x ${snapshot.at.x}  z ${snapshot.at.z}  eye ${snapshot.at.eye}`,
        `yaw ${snapshot.at.yaw}\u00b0  pitch ${snapshot.at.pitch}\u00b0`,
        closest === undefined
            ? 'No boundary within reach'
            : `${(closest.distance * 100).toFixed(1)} cm from ${closest.rooms.join(' | ')}${closest.open ? '' : ' (blocked)'}`,
    ];
}

/** What became of a snapshot that was sent somewhere. */
/**
 * The CSRF header this app's session cookie asks for, or nothing.
 *
 * Shared rather than written twice: a snapshot and a ticket both post to a
 * session-guarded route, and two copies of a cookie-reading loop is two places
 * for it to go quietly wrong when only one of them is under test.
 *
 * Absent rather than empty when there is no cookie, so a request from
 * somewhere without a session goes unguarded rather than guarded with nothing
 * — an empty token reads as a forged one rather than as no token.
 */
export function guardHeaders(): Record<string, string> {
    const guard = document.cookie
        .split('; ')
        .find((crumb) => crumb.startsWith('XSRF-TOKEN='));

    return guard === undefined
        ? {}
        : {
              'X-XSRF-TOKEN': decodeURIComponent(
                  guard.slice('XSRF-TOKEN='.length),
              ),
          };
}

export type SnapshotSaved = { saved: string } | { failed: string };

/**
 * Sends a snapshot to the server and says what happened.
 *
 * It never throws: a snapshot that could not be saved is still in the console,
 * and the caller's job is to say so rather than to handle an exception. The
 * thing being caught may not come back, so losing the reading to a server that
 * is not listening would be the worst of the outcomes.
 *
 * Laravel wants the forgery token, and this page carries none in its markup —
 * only the cookie it sets on every response. Read it back out and hand it over
 * the way Laravel expects.
 */
export async function postSnapshot(
    spot: Snapshot,
    url: string,
): Promise<SnapshotSaved> {
    let answer: Response;

    try {
        answer = await fetch(url, {
            method: 'POST',
            credentials: 'same-origin',
            headers: {
                'Content-Type': 'application/json',
                Accept: 'application/json',
                ...guardHeaders(),
            },
            body: JSON.stringify(spot),
        });
    } catch {
        return { failed: 'the server did not answer' };
    }

    if (!answer.ok) {
        return { failed: `the server said ${answer.status}` };
    }

    const said: unknown = await answer.json().catch(() => null);

    return {
        saved:
            said !== null && typeof said === 'object' && 'saved' in said
                ? String((said as { saved: unknown }).saved)
                : 'a snapshot',
    };
}
