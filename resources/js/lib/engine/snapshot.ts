import { boundaryKey, edgesOf, sectorAt } from '@/lib/engine/sectors';
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
                      floorHeight: room.floorHeight,
                      ceilingHeight: room.ceilingHeight,
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
