import type * as THREE from 'three';
import { buildBoundaries } from '@/lib/engine/build/boundaries';
import { createBuildContext } from '@/lib/engine/build/context';
import { buildSectorFlats } from '@/lib/engine/build/flats';
import { buildMirrorImages } from '@/lib/engine/build/images';
import { buildPortals } from '@/lib/engine/build/portal-panes';
import type { SkyLid } from '@/lib/engine/build/scene';
import { buildThings } from '@/lib/engine/build/things';
import type { PropSet } from '@/lib/engine/build/things';
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
 * The pieces are in lib/engine/build: what the plan says about itself
 * (topology), the materials, and one module per kind of surface. This file is
 * the order they run in, which is load-bearing — the panes cannot be filled
 * until every room has drawn whatever stands behind its mouths.
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
    /**
     * The props: what turns to face a viewpoint, what animates, and what
     * changes picture when a flag is set.
     */
    props: PropSet;
    highlight: (slug: string | null) => void;
    dispose: () => void;
};

export function buildLevel(level: Level, textures: TextureLibrary): BuiltLevel {
    const ctx = createBuildContext(level, textures);
    const { group, colliders, targets, mirrors, portals, skyLids } = ctx.scene;

    buildSectorFlats(ctx);

    // The mouths cannot be filled here: each pane is drawn by a camera standing
    // behind the far one, and it also has to know what every room drew.
    const mouths = buildBoundaries(ctx);

    const props = buildThings(ctx);

    buildPortals(ctx, mouths);

    // Last, and it has to be: a room's image is a copy of everything that room
    // drew, and the last of that is not there until every edge is done.
    buildMirrorImages(ctx);

    const dispose = (): void => {
        for (const mirror of mirrors) {
            mirror.dispose();
        }

        for (const portal of portals) {
            portal.dispose();
        }

        ctx.materials.dispose();
    };

    return {
        group,
        colliders,
        targets,
        mirrors,
        portals,
        skyLids,
        props,
        highlight: props.highlight,
        dispose,
    };
}

/** Things the engine draws as sprites rather than boxes. */
export function actorsOf(level: Level): LevelThing[] {
    return level.things.filter((thing) => thing.kind === 'actor');
}
