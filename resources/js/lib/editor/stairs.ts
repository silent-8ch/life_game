import clipping from 'polygon-clipping';
import { dress, toPoints, toRing } from '@/lib/editor/carve';
import { freeSlug } from '@/lib/editor/map';
import type { Level, Sector } from '@/types';

/**
 * Turning one room into a flight of steps.
 *
 * Authoring a staircase by hand is the most tedious job in the editor: draw a
 * room, carve it into N strips, then set two heights on each of them and get
 * every one right. It is arithmetic, done by hand, N times, and the mistakes it
 * produces — a step half a centimetre out — are invisible in plan and obvious
 * the moment somebody tries to walk up.
 *
 * So: pick a room, say how many steps and over what rise, and say which wall
 * the flight starts from. The room is cut into bands running parallel to that
 * wall and each band gets its heights.
 *
 * The wall is named the same way a slope's hinge is, and for the same reason —
 * "which way is up" is a property of the room's own shape, not of the world
 * axes, and a staircase running north-east is no stranger to author than one
 * running north.
 *
 * **No step-height rule is applied here.** Whether a given rise is climbable is
 * a traversal question, and traversal is about to become a runtime decision
 * rather than a build-time one. Baking a limit into the geometry would freeze
 * today's answer into every level authored today. `tooSteepFor` is offered
 * separately, for a caller that wants to warn, and it takes the limit rather
 * than knowing one.
 */

/** How many steps a flight may have. More is a ramp; fewer is a doorstep. */
export const MIN_STEPS = 2;
export const MAX_STEPS = 64;

export type StairPlan = {
    /** How many sectors the room becomes. */
    steps: number;
    /** How far the flight climbs in total, in metres. Negative goes down. */
    rise: number;
    /**
     * Which wall the flight starts from, as an index into the room's points.
     * The first step sits against it and the climb runs into the room.
     */
    fromEdge: number;
};

/** Whether the rise per step is beyond what the caller says can be climbed. */
export function tooSteepFor(plan: StairPlan, maxStep: number): boolean {
    return Math.abs(plan.rise) / Math.max(1, plan.steps) > maxStep;
}

/**
 * Why a plan cannot be carved, or null if it can.
 *
 * Separate from the carving so the editor can say what is wrong before
 * anything is destroyed — a stair carve replaces the room it is given, and
 * finding out afterwards that it produced one sector is no use.
 */
export function whyNotStairs(sector: Sector, plan: StairPlan): string | null {
    if (!Number.isInteger(plan.steps)) {
        return 'A flight has a whole number of steps.';
    }

    if (plan.steps < MIN_STEPS) {
        return `A flight needs at least ${MIN_STEPS} steps.`;
    }

    if (plan.steps > MAX_STEPS) {
        return `${MAX_STEPS} steps is as long as a flight goes.`;
    }

    if (plan.rise === 0) {
        return 'A flight that does not climb is just a room.';
    }

    if (sector.points.length < 3) {
        return 'That is not a room.';
    }

    if (plan.fromEdge < 0 || plan.fromEdge >= sector.points.length) {
        return 'That room has no such wall to start from.';
    }

    return null;
}

/** The unit vector pointing into the room from one of its walls. */
function inwardFrom(sector: Sector, edge: number): { x: number; z: number } {
    const points = sector.points;
    const count = points.length;
    const from = points[edge];
    const to = points[(edge + 1) % count];

    const spanX = to.x - from.x;
    const spanZ = to.z - from.z;
    const length = Math.hypot(spanX, spanZ) || 1;

    let twiceArea = 0;

    for (let index = 0; index < count; index++) {
        const point = points[index];
        const next = points[(index + 1) % count];

        twiceArea += point.x * next.z - next.x * point.z;
    }

    const turn = twiceArea > 0 ? 1 : -1;

    return { x: (-spanZ / length) * turn, z: (spanX / length) * turn };
}

/**
 * Cuts the room at `index` into a flight of steps.
 *
 * Each step keeps the room's textures and flags — it is the same room, cut up —
 * but not its portal links: a portal pairs exactly two mouths by name, and
 * copying a link onto every step would leave a level with a dozen ends to one
 * portal and none of them working. Same reasoning as duplicating a room.
 *
 * Headroom is carried up with the floor rather than left behind. A staircase
 * under a flat ceiling runs out of room to stand at the top, which is a thing
 * somebody might want and is never what they meant by "make me a staircase".
 *
 * Returns the level unchanged if the plan is one `whyNotStairs` refuses.
 */
export function carveStairs(
    level: Level,
    index: number,
    plan: StairPlan,
): Level {
    const sector = level.sectors[index];

    if (sector === undefined || whyNotStairs(sector, plan) !== null) {
        return level;
    }

    const into = inwardFrom(sector, plan.fromEdge);
    const start = sector.points[plan.fromEdge];

    // How far each corner lies into the room from the starting wall. The flight
    // spans that range, so it fills the room however the room is shaped.
    const depths = sector.points.map(
        (point) => (point.x - start.x) * into.x + (point.z - start.z) * into.z,
    );

    const nearest = Math.min(...depths);
    const furthest = Math.max(...depths);
    const run = furthest - nearest;

    if (run <= 0) {
        return level;
    }

    // A band wide enough to cross the room whichever way it lies, so the cut is
    // decided by the two lines across the run and never by the band's ends.
    const along = { x: -into.z, z: into.x };
    const reach = run + Math.hypot(run, run) + 1;

    const ring = toRing(sector.points);
    const perStep = run / plan.steps;
    const perRise = plan.rise / plan.steps;
    const headroom = sector.ceilingHeight - sector.floorHeight;

    const cut: Sector[] = [];
    const taken: string[] = [];

    for (let step = 0; step < plan.steps; step++) {
        const from = nearest + step * perStep;
        const to = step === plan.steps - 1 ? furthest : from + perStep;

        const corner = (depth: number, side: number) => [
            start.x + into.x * depth + along.x * reach * side,
            start.z + into.z * depth + along.z * reach * side,
        ];

        const band = [
            corner(from, -1),
            corner(from, 1),
            corner(to, 1),
            corner(to, -1),
            corner(from, -1),
        ] as [number, number][];

        for (const piece of clipping.intersection([[ring]], [[band]])) {
            const points = dress(sector, toPoints(piece[0]));

            // Named against the level *and* against the steps already made, so
            // a flight cannot collide with itself.
            const slug =
                step === 0
                    ? sector.slug
                    : freeSlug(
                          {
                              ...level,
                              sectors: [
                                  ...level.sectors,
                                  ...taken.map((used) => ({
                                      ...sector,
                                      slug: used,
                                  })),
                              ],
                          },
                          `${sector.slug}-step`,
                      );

            taken.push(slug);

            cut.push({
                ...sector,
                slug,
                name:
                    step === 0
                        ? sector.name
                        : `${sector.name} step ${step + 1}`,
                points: points.map((point) => ({ ...point, portalLink: null })),
                floorHeight: sector.floorHeight + perRise * step,
                ceilingHeight: sector.floorHeight + perRise * step + headroom,
                // A step is flat. Whatever the room was sloping, the flight
                // replaces it — the two describe the same thing twice, and a
                // sloped step is a ramp with a lip.
                floorSlope: 0,
                floorSlopeEdge: null,
                ceilingSlope: 0,
                ceilingSlopeEdge: null,
            });
        }
    }

    if (cut.length < 2) {
        return level;
    }

    return {
        ...level,
        sectors: [
            ...level.sectors.slice(0, index),
            ...cut,
            ...level.sectors.slice(index + 1),
        ],
    };
}
