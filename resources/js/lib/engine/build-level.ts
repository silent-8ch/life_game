import type * as THREE from 'three';
import { buildFlats } from '@/lib/engine/build-flats';
import { buildPortalPanes } from '@/lib/engine/build-portal-panes';
import { createLevelScene } from '@/lib/engine/build-scene';
import type { SkyLid } from '@/lib/engine/build-scene';
import { buildThings } from '@/lib/engine/build-things';
import { buildWalls } from '@/lib/engine/build-walls';
import type { Collider } from '@/lib/engine/collision';
import type { PortalSurface } from '@/lib/engine/portal-surface';
import type { TextureLibrary } from '@/lib/engine/textures';
import type { Level, LevelThing } from '@/types';

/**
 * Turns a level's sectors into geometry. A sector contributes a floor, a
 * ceiling unless it is open to the sky, and a wall for every edge that has
 * nothing on the far side. Where two sectors meet, only the step between their
 * floors and the drop between their ceilings get built, which is what leaves a
 * doorway open.
 *
 * Surfaces with a texture are drawn with it; surfaces without one fall back to
 * the wireframe, so a half-built level still reads.
 *
 * The work itself is done a seam at a time — the flats, the walls and their
 * colliders, the things, and the panes that fill the portal mouths — and this
 * is the order they run in. It matters: the panes are told what the rooms drew,
 * so nothing can ask that question until the rooms have drawn.
 */

export type BuiltLevel = {
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
    highlight: (slug: string | null) => void;
    dispose: () => void;
};

export function buildLevel(level: Level, textures: TextureLibrary): BuiltLevel {
    const scene = createLevelScene(level, textures);

    buildFlats(scene);

    // The mouths, whose panes cannot be built until both ends of each portal
    // are known and every room has drawn.
    const mouths = buildWalls(scene);

    const highlight = buildThings(scene);

    buildPortalPanes(scene, mouths);

    const { group, colliders, targets, mirrors, portals, skyLids } = scene;

    const dispose = (): void => {
        for (const mirror of mirrors) {
            mirror.dispose();
        }

        for (const portal of portals) {
            portal.dispose();
        }

        scene.palette.dispose();
    };

    return {
        group,
        colliders,
        targets,
        mirrors,
        portals,
        skyLids,
        highlight,
        dispose,
    };
}

/** Things the engine draws as sprites rather than boxes. */
export function actorsOf(level: Level): LevelThing[] {
    return level.things.filter((thing) => thing.kind === 'actor');
}
