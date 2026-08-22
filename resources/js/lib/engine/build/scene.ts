import * as THREE from 'three';
import type { Collider } from '@/lib/engine/collision';
import type { PortalSurface } from '@/lib/engine/portal-surface';

/** A lid or a sky wall, and the room it belongs to. */
export type SkyLid = { mesh: THREE.Object3D; room: string };

/**
 * What the build puts together as it goes. Every builder adds to these; nothing
 * reads them back except the portal panes, which need to know what each room
 * drew before they can decide what to hide.
 */
export type LevelScene = {
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
    drawnByRoom: Map<string, THREE.Object3D[]>;
    remember: (slug: string, what: THREE.Object3D | null) => void;
};

export function createScene(): LevelScene {
    const drawnByRoom = new Map<string, THREE.Object3D[]>();

    const remember = (slug: string, what: THREE.Object3D | null): void => {
        if (what === null) {
            return;
        }

        drawnByRoom.set(slug, [...(drawnByRoom.get(slug) ?? []), what]);
    };

    return {
        group: new THREE.Group(),
        colliders: [],
        targets: [],
        mirrors: [],
        portals: [],
        skyLids: [],
        drawnByRoom,
        remember,
    };
}
