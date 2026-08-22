import * as THREE from 'three';
import { createSurfacePalette } from '@/lib/engine/build-materials';
import type { SurfacePalette } from '@/lib/engine/build-materials';
import type { Collider } from '@/lib/engine/collision';
import type { PortalSurface } from '@/lib/engine/portal-surface';
import { edgesOf } from '@/lib/engine/sectors';
import type { TextureLibrary } from '@/lib/engine/textures';
import type { Level } from '@/types';

/**
 * What every part of a level build shares: the group everything is added to,
 * the lists the viewport is handed back, the materials, and the two pieces of
 * per-room bookkeeping the panes need.
 *
 * The scene is made before any of the builders run, which is what keeps
 * `remember` alive for the first pass: the walls and the flats call it while
 * they are being built, and anything that only comes into being further down
 * the build is a temporal dead zone crash at runtime that tsc will not catch.
 */

/** A lid or a wall that shows the sky, and whose room it belongs to. */
export type SkyLid = { mesh: THREE.Object3D; room: string };

export type LevelScene = {
    level: Level;
    textures: TextureLibrary;
    group: THREE.Group;
    colliders: Collider[];
    /** Everything the look-at ray can hit, walls included. */
    targets: THREE.Object3D[];
    /** Mirrors, which are panes whose far side is the room reflected. */
    mirrors: PortalSurface[];
    /** One pane per portal mouth, each showing the view from the far one. */
    portals: PortalSurface[];
    /**
     * The lids over rooms open to the sky and the walls that show it, each
     * knowing whose room it is. Only the ones belonging to the room the player
     * is standing in belong in the picture.
     */
    skyLids: SkyLid[];
    palette: SurfacePalette;
    /** Notes that a room drew something, for `drawnIn` to find later. */
    remember: (slug: string, what: THREE.Object3D | null) => void;
    /** Everything a room drew. */
    drawnIn: (slug: string) => THREE.Object3D[];
    /** A room, and whatever can be seen from it through an open doorway. */
    seenFrom: (slug: string) => string[];
};

/**
 * For each room, itself and whatever can be seen from it through an open
 * doorway. Anything standing in one of those can turn up in a view of that
 * room, and has to be drawn for it — a mirror through a doorway that never
 * gets redrawn shows a reflection that never moves.
 */
function roomsSeenFrom(level: Level): Map<string, string[]> {
    const seenFrom = new Map<string, string[]>();

    for (const sector of level.sectors) {
        seenFrom.set(sector.slug, [sector.slug]);
    }

    for (const edge of edgesOf(level.sectors)) {
        const { sector, beyond } = edge;

        if (
            beyond === null ||
            edge.from.blocks ||
            (edge.beyondFrom?.blocks ?? false)
        ) {
            continue;
        }

        for (const [from, to] of [
            [sector.slug, beyond.slug],
            [beyond.slug, sector.slug],
        ]) {
            const seen = seenFrom.get(from);

            if (seen !== undefined && !seen.includes(to)) {
                seen.push(to);
            }
        }
    }

    return seenFrom;
}

export function createLevelScene(
    level: Level,
    textures: TextureLibrary,
): LevelScene {
    /**
     * Everything each room drew, by slug.
     *
     * A pane's camera stands in the room behind its far mouth, so that whole
     * room is between the camera and the opening: its own wall across the mouth,
     * the walls meeting it at the corners, its floor and its ceiling. The tilted
     * near plane is meant to cut all of it away, but anything touching the
     * mouth's plane sits inside the slack CLIP_BIAS leaves and survives as a
     * sliver down the edge of the opening. Taking the room out of the pass is
     * the certain way.
     */
    const drawnByRoom = new Map<string, THREE.Object3D[]>();

    const remember = (slug: string, what: THREE.Object3D | null): void => {
        if (what === null) {
            return;
        }

        drawnByRoom.set(slug, [...(drawnByRoom.get(slug) ?? []), what]);
    };

    const seen = roomsSeenFrom(level);

    return {
        level,
        textures,
        group: new THREE.Group(),
        colliders: [],
        targets: [],
        mirrors: [],
        portals: [],
        skyLids: [],
        palette: createSurfacePalette(level, textures),
        remember,
        drawnIn: (slug) => drawnByRoom.get(slug) ?? [],
        seenFrom: (slug) => seen.get(slug) ?? [slug],
    };
}
