import type { Level, LevelThing, Sector, SectorPoint } from '@/types';

/**
 * The floor plan as the editor works with it: metres in, pixels out, and the
 * small edits that keep a level consistent while it is being drawn.
 */

export type Point = { x: number; z: number };

/** What is currently picked: a whole room, or one side of one. */
export type Selection = { sector: number; edge: number | null } | null;

/** How the floor plan is laid over the canvas. */
export type View = {
    /** Pixels per metre. */
    scale: number;
    /** The metre position drawn at the top left of the canvas. */
    originX: number;
    originZ: number;
};

export const MIN_SCALE = 8;
export const MAX_SCALE = 160;

/** Corners within this many metres of each other are the same corner. */
export const CORNER_EPSILON = 0.001;

/** Heights snap to this, in metres, however they are changed. */
export const HEIGHT_STEP = 0.1;

/** No room is ever squashed thinner than this. */
export const MIN_ROOM_HEIGHT = 0.2;

/** How far a duplicated room lands from the one it was copied from. */
export const DUPLICATE_OFFSET = 1;

export function toScreen(view: View, x: number, z: number): [number, number] {
    return [(x - view.originX) * view.scale, (z - view.originZ) * view.scale];
}

export function toWorld(view: View, x: number, y: number): Point {
    return {
        x: x / view.scale + view.originX,
        z: y / view.scale + view.originZ,
    };
}

export function snap(value: number, grid: number): number {
    return grid <= 0 ? value : Math.round(value / grid) * grid;
}

export function samePoint(a: Point, b: Point): boolean {
    return (
        Math.abs(a.x - b.x) < CORNER_EPSILON &&
        Math.abs(a.z - b.z) < CORNER_EPSILON
    );
}

/** A view that fits the whole level, with a margin, into a canvas. */
export function fitView(
    level: Level,
    width: number,
    height: number,
    margin = 40,
): View {
    const points = level.sectors.flatMap((sector) => sector.points);

    if (points.length === 0) {
        return { scale: 40, originX: -2, originZ: -2 };
    }

    const minX = Math.min(...points.map((point) => point.x));
    const maxX = Math.max(...points.map((point) => point.x));
    const minZ = Math.min(...points.map((point) => point.z));
    const maxZ = Math.max(...points.map((point) => point.z));

    const scale = Math.min(
        Math.max(
            Math.min(
                (width - margin * 2) / Math.max(maxX - minX, 1),
                (height - margin * 2) / Math.max(maxZ - minZ, 1),
            ),
            MIN_SCALE,
        ),
        MAX_SCALE,
    );

    return {
        scale,
        originX: minX - (width - (maxX - minX) * scale) / 2 / scale,
        originZ: minZ - (height - (maxZ - minZ) * scale) / 2 / scale,
    };
}

/** The middle of a sector, for hanging its name off. */
export function centroid(sector: Sector): Point {
    const total = sector.points.reduce(
        (sum, point) => ({ x: sum.x + point.x, z: sum.z + point.z }),
        { x: 0, z: 0 },
    );

    return {
        x: total.x / sector.points.length,
        z: total.z / sector.points.length,
    };
}

export function pointInSector(sector: Sector, at: Point): boolean {
    let inside = false;

    sector.points.forEach((point, index) => {
        const next = sector.points[(index + 1) % sector.points.length];
        const straddles = point.z > at.z !== next.z > at.z;

        if (!straddles) {
            return;
        }

        const crossingX =
            point.x +
            ((at.z - point.z) / (next.z - point.z)) * (next.x - point.x);

        if (at.x < crossingX) {
            inside = !inside;
        }
    });

    return inside;
}

/** The spot on an edge that lies nearest to somewhere else. */
export function closestOnEdge(from: Point, to: Point, at: Point): Point {
    const spanX = to.x - from.x;
    const spanZ = to.z - from.z;
    const lengthSquared = spanX * spanX + spanZ * spanZ;

    const along =
        lengthSquared < 1e-9
            ? 0
            : Math.min(
                  Math.max(
                      ((at.x - from.x) * spanX + (at.z - from.z) * spanZ) /
                          lengthSquared,
                      0,
                  ),
                  1,
              );

    return { x: from.x + spanX * along, z: from.z + spanZ * along };
}

/** How far a spot is from an edge, in metres. */
export function distanceToEdge(
    from: SectorPoint,
    to: SectorPoint,
    at: Point,
): number {
    const on = closestOnEdge(from, to, at);

    return Math.hypot(at.x - on.x, at.z - on.z);
}

/**
 * Moving a corner moves it for every room that meets there, which is what keeps
 * a doorway attached to both of its rooms.
 */
export function moveCorner(level: Level, from: Point, to: Point): Level {
    return {
        ...level,
        sectors: level.sectors.map((sector) => ({
            ...sector,
            points: sector.points.map((point) =>
                samePoint(point, from) ? { ...point, x: to.x, z: to.z } : point,
            ),
        })),
    };
}

/**
 * Adds a corner partway along a wall. Every room that meets along that same
 * wall gets the corner too, so a shared boundary stays matched corner for
 * corner and the doorway between them survives. Both halves keep the texture
 * and the solid and mirror flags the wall already had, so splitting a wall on
 * its own changes nothing about the level — except a portal link, which belongs
 * to a whole wall and to one partner, so it does not survive being halved.
 */
export function splitEdge(
    level: Level,
    from: Point,
    to: Point,
    at: Point,
): Level {
    if (samePoint(at, from) || samePoint(at, to)) {
        return level;
    }

    return {
        ...level,
        sectors: level.sectors.map((sector) => {
            const points = [...sector.points];

            // Backwards, so a splice cannot disturb an index not yet looked at.
            for (let index = points.length - 1; index >= 0; index--) {
                const start = points[index];
                const end = points[(index + 1) % points.length];

                const isTheWall =
                    (samePoint(start, from) && samePoint(end, to)) ||
                    (samePoint(start, to) && samePoint(end, from));

                if (!isTheWall) {
                    continue;
                }

                points.splice(index + 1, 0, {
                    ...start,
                    x: at.x,
                    z: at.z,
                    portalLink: null,
                });
                points[index] = { ...start, portalLink: null };
            }

            return { ...sector, points };
        }),
    };
}

/** A rectangle on the floor plan, however it was dragged out. */
export type Box = { minX: number; minZ: number; maxX: number; maxZ: number };

export function boxBetween(from: Point, to: Point): Box {
    return {
        minX: Math.min(from.x, to.x),
        minZ: Math.min(from.z, to.z),
        maxX: Math.max(from.x, to.x),
        maxZ: Math.max(from.z, to.z),
    };
}

/**
 * The rooms a swept box takes: those whose middle falls inside it. Judging by
 * the middle rather than by any overlap means a box drawn across a wing takes
 * the rooms of that wing and not every corridor that happens to graze it.
 */
export function roomsWithin(level: Level, box: Box): number[] {
    return level.sectors
        .map((sector, index) => ({ at: centroid(sector), index }))
        .filter(
            ({ at }) =>
                at.x >= box.minX &&
                at.x <= box.maxX &&
                at.z >= box.minZ &&
                at.z <= box.maxZ,
        )
        .map(({ index }) => index);
}

/** Adds a room to a selection, or takes it out if it is already there. */
export function toggleRoom(rooms: number[], index: number): number[] {
    return rooms.includes(index)
        ? rooms.filter((held) => held !== index)
        : [...rooms, index];
}

export function updateSector(
    level: Level,
    index: number,
    change: Partial<Sector>,
): Level {
    return {
        ...level,
        sectors: level.sectors.map((sector, at) =>
            at === index ? { ...sector, ...change } : sector,
        ),
    };
}

/** A height put back on the step it belongs to, and off 0.30000000000000004. */
function snapHeight(value: number): number {
    return (
        Math.round(Math.round(value / HEIGHT_STEP) * HEIGHT_STEP * 1000) / 1000
    );
}

/**
 * Moves rooms' floors or ceilings by whole steps — the keyboard's answer to
 * dragging a handle in the section, and clamped the same way that drag is, so
 * a floor can never be nudged up through its own ceiling.
 */
export function nudgeHeights(
    level: Level,
    rooms: number[],
    which: 'floor' | 'ceiling',
    steps: number,
): Level {
    return rooms.reduce((current, index) => {
        const sector = current.sectors[index];

        if (sector === undefined) {
            return current;
        }

        const moved = snapHeight(
            (which === 'floor' ? sector.floorHeight : sector.ceilingHeight) +
                steps * HEIGHT_STEP,
        );

        return updateSector(
            current,
            index,
            which === 'floor'
                ? {
                      floorHeight: snapHeight(
                          Math.min(
                              moved,
                              sector.ceilingHeight - MIN_ROOM_HEIGHT,
                          ),
                      ),
                  }
                : {
                      ceilingHeight: snapHeight(
                          Math.max(moved, sector.floorHeight + MIN_ROOM_HEIGHT),
                      ),
                  },
        );
    }, level);
}

function setEdge(
    level: Level,
    sectorIndex: number,
    edgeIndex: number,
    change: Partial<SectorPoint>,
): Level {
    return updateSector(level, sectorIndex, {
        points: level.sectors[sectorIndex].points.map((point, at) =>
            at === edgeIndex ? { ...point, ...change } : point,
        ),
    });
}

/** The same wall as the room on the other side names it, if it is shared. */
export function twinEdge(
    level: Level,
    sectorIndex: number,
    edgeIndex: number,
): { sector: number; edge: number } | null {
    const points = level.sectors[sectorIndex]?.points ?? [];

    if (points.length === 0) {
        return null;
    }

    const from = points[edgeIndex];
    const to = points[(edgeIndex + 1) % points.length];

    for (let sector = 0; sector < level.sectors.length; sector++) {
        if (sector === sectorIndex) {
            continue;
        }

        const theirs = level.sectors[sector].points;

        for (let edge = 0; edge < theirs.length; edge++) {
            const start = theirs[edge];
            const end = theirs[(edge + 1) % theirs.length];

            const matches =
                (samePoint(start, from) && samePoint(end, to)) ||
                (samePoint(start, to) && samePoint(end, from));

            if (matches) {
                return { sector, edge };
            }
        }
    }

    return null;
}

/**
 * Changes one wall. Whether it is solid belongs to the boundary rather than to
 * one room, so that flag is set on both sides at once — set on one side only,
 * the room that still calls it solid goes on drawing a wall across the doorway.
 * The texture and the mirror stay a matter for each room.
 */
export function updateEdge(
    level: Level,
    sectorIndex: number,
    edgeIndex: number,
    change: Partial<SectorPoint>,
): Level {
    const changed = setEdge(level, sectorIndex, edgeIndex, change);

    if (change.blocks === undefined) {
        return changed;
    }

    const twin = twinEdge(level, sectorIndex, edgeIndex);

    return twin === null
        ? changed
        : setEdge(changed, twin.sector, twin.edge, { blocks: change.blocks });
}

/** A slug that no other room in the level is using. */
export function freeSlug(level: Level, wanted: string): string {
    const taken = new Set(level.sectors.map((sector) => sector.slug));

    if (!taken.has(wanted)) {
        return wanted;
    }

    let suffix = 2;

    while (taken.has(`${wanted}-${suffix}`)) {
        suffix++;
    }

    return `${wanted}-${suffix}`;
}

/** A slug no thing in the level is using. */
export function freeThingSlug(level: Level, wanted: string): string {
    const taken = new Set(level.things.map((thing) => thing.slug));

    if (!taken.has(wanted)) {
        return wanted;
    }

    let suffix = 2;

    while (taken.has(`${wanted}-${suffix}`)) {
        suffix++;
    }

    return `${wanted}-${suffix}`;
}

/**
 * Someone to walk about the room. People are drawn from sprite sheets rather
 * than built as boxes, so they carry a sprite and a height and nothing else
 * about their shape matters much.
 */
export function newPerson(
    level: Level,
    sprite: string,
    at: Point,
    height: number,
): LevelThing {
    const slug = freeThingSlug(level, sprite);

    return {
        slug,
        name: sprite.charAt(0).toUpperCase() + sprite.slice(1),
        description: `${sprite.charAt(0).toUpperCase() + sprite.slice(1)} is wandering about.`,
        kind: 'actor',
        sprite,
        behaviour: 'wander',
        // Nobody starts overridden: null means whatever their sprite starts
        // with, and the Inspector is where that is taken over.
        stats: null,
        speed: 1.1,
        texture: null,
        x: at.x,
        z: at.z,
        elevation: 0,
        width: 0.9,
        depth: 0.9,
        height,
        angle: 0,
        isSolid: false,
        verbs: [],
        interactions: [],
    };
}

/** A box of furniture, which is the other thing a level is made of. */
export function newProp(level: Level, at: Point): LevelThing {
    const slug = freeThingSlug(level, 'thing');

    return {
        slug,
        name: 'Thing',
        description: 'Something that stands in the room.',
        kind: 'prop',
        sprite: null,
        behaviour: null,
        stats: null,
        speed: 0,
        texture: null,
        x: at.x,
        z: at.z,
        elevation: 0,
        width: 0.8,
        depth: 0.8,
        height: 0.8,
        angle: 0,
        isSolid: true,
        verbs: [],
        interactions: [],
    };
}

export function updateThing(
    level: Level,
    index: number,
    change: Partial<LevelThing>,
): Level {
    return {
        ...level,
        things: level.things.map((thing, at) =>
            at === index ? { ...thing, ...change } : thing,
        ),
    };
}

/** The thing nearest a spot, within reach of it. */
export function thingNear(
    level: Level,
    at: Point,
    reach: number,
): number | null {
    let found: { index: number; away: number } | null = null;

    level.things.forEach((thing, index) => {
        const away = Math.hypot(thing.x - at.x, thing.z - at.z);

        if (away < reach && (found === null || away < found.away)) {
            found = { index, away };
        }
    });

    return found === null ? null : (found as { index: number }).index;
}

/** A new room, drawn at the level's default height, with nothing textured yet. */
export function newSector(level: Level, points: Point[]): Sector {
    const slug = freeSlug(level, `room-${level.sectors.length + 1}`);

    return {
        slug,
        name: `Room ${level.sectors.length + 1}`,
        floorHeight: 0,
        ceilingHeight: level.ceilingHeight,
        floorTexture: null,
        ceilingTexture: null,
        wallTexture: null,
        isSky: false,
        isWater: false,
        points: points.map((point) => ({
            x: point.x,
            z: point.z,
            wallTexture: null,
            blocks: false,
            isMirror: false,
            isSky: false,
            portalLink: null,
        })),
    };
}

/**
 * A copy of a room, shifted off the original so both can be seen, with a slug
 * no other room is using. The walls come across as they are — textures, mirrors
 * and all — but not their portal links, which pair two mouths by name and would
 * otherwise leave three walls claiming to be the same portal.
 */
export function duplicateSector(
    level: Level,
    index: number,
    offset = DUPLICATE_OFFSET,
): Sector {
    const sector = level.sectors[index];

    return {
        ...sector,
        slug: freeSlug(level, sector.slug),
        name: `${sector.name} copy`,
        points: sector.points.map((point) => ({
            ...point,
            x: point.x + offset,
            z: point.z + offset,
            portalLink: null,
        })),
    };
}

/**
 * Copies every picked room, one at a time so that each copy's slug is judged
 * against the ones already made. The copies land at the end, so they are the
 * last `rooms.length` sectors of the level that comes back.
 */
export function duplicateRooms(
    level: Level,
    rooms: number[],
    offset = DUPLICATE_OFFSET,
): Level {
    return rooms.reduce(
        (current, index) => ({
            ...current,
            sectors: [
                ...current.sectors,
                duplicateSector(current, index, offset),
            ],
        }),
        level,
    );
}

/** Twice the signed area; negative means the corners run the wrong way round. */
export function windingOf(points: Point[]): number {
    return points.reduce((total, point, index) => {
        const next = points[(index + 1) % points.length];

        return total + (point.x * next.z - next.x * point.z);
    }, 0);
}
