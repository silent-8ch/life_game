import * as THREE from 'three';
import { spriteDirection } from './sprite-direction';

/**
 * A body, drawn the way Doom drew its actors: one flat quad that turns to face
 * whoever is looking at it, showing whichever of eight painted angles matches
 * how the body is turned relative to that viewer.
 *
 * The player's own body uses this too. Nothing renders it in the main pass —
 * you cannot see yourself — so for the player the only place it appears is
 * inside a mirror.
 */

const SPRITE_PATH = '/sprites';

/**
 * How tall each person stands, in metres. Kept in step with
 * LevelAssets::HEIGHTS, which is where a level reads them from for the people
 * it places; this copy is only for the player, whose height comes from whoever
 * the level says they are rather than from a thing standing in a room.
 */
export const HEIGHTS: Record<string, number> = {
    paul: 1.85,
    wade: 1.8,
    krystal: 1.7,
    luna: 1.66,
    luke: 1.62,
    william: 1.55,
};

/** Who to draw the player as when the level names somebody unknown. */
export const DEFAULT_PLAYER_HEIGHT = 1.75;

/** Both atlases are four walk frames across by four viewing angles down. */
const COLUMNS = 4;
const ROWS = 4;

/** Metres walked per walk frame. */
const STRIDE = 0.55;

/**
 * Where a drawing sits inside its cell, as a fraction from the top. The aligned
 * sheets put every person in the same frame — crown at the top of that band,
 * feet at the bottom — so a sprite is scaled and lifted by this to stand the
 * height it is meant to stand with its feet on the floor. How tall each person
 * actually is comes from the level, not from the artwork.
 *
 * Measured off the sheets; re-measure if a new set is drawn to a different frame.
 */
const FIGURE_IN_CELL = { top: 10 / 256, feet: 245 / 256 };

export type SpriteActor = {
    object: THREE.Object3D;
    /**
     * Stand the body on the floor at a spot, turned to `yaw` radians, and
     * `walked` metres into its journey.
     */
    place: (
        x: number,
        floorY: number,
        z: number,
        yaw: number,
        walked: number,
    ) => void;
    /**
     * Square the quad up to a viewer and pick the angle that viewer would see.
     *
     * The quad is turned by the viewer's own heading rather than towards where
     * the viewer stands, so it stays parallel to the screen and never
     * foreshortens at the edge of a wide view — the way Doom did it. Which of
     * the eight painted angles gets shown comes from where the viewer stands
     * relative to the body.
     *
     * A mirror needs nothing special: its camera is a real camera standing
     * behind the glass, so asking what it can see gives what a mirror gives —
     * the side of the body that faces the mirror.
     */
    faceViewer: (viewerX: number, viewerZ: number, viewerYaw: number) => void;
    dispose: () => void;
};

function loadSheet(
    sprite: string,
    style: string,
    kind: 'cardinal' | 'diagonal',
): THREE.Texture {
    const texture = new THREE.TextureLoader().load(
        `${SPRITE_PATH}/${style}/${sprite}-${style}-${kind}-aligned-4step.png`,
    );

    texture.colorSpace = THREE.SRGBColorSpace;
    texture.magFilter = THREE.NearestFilter;
    texture.minFilter = THREE.NearestFilter;
    texture.repeat.set(1 / COLUMNS, 1 / ROWS);

    return texture;
}

/**
 * @param  height  How tall the person is, in metres, floor to head.
 * @param  style  Which set of sheets to draw them from.
 */
export function createSpriteActor(
    sprite: string,
    height: number,
    style: string,
): SpriteActor {
    const sheets = {
        cardinal: loadSheet(sprite, style, 'cardinal'),
        diagonal: loadSheet(sprite, style, 'diagonal'),
    };

    // The drawing is only part of its cell, so the cell has to be bigger than
    // the person for the person to come out the right height.
    const cell = height / (FIGURE_IN_CELL.feet - FIGURE_IN_CELL.top);

    // And it has to be lifted by whatever empty space sits under the feet.
    const underfoot = (1 - FIGURE_IN_CELL.feet) * cell;

    const geometry = new THREE.PlaneGeometry(cell, cell);
    const material = new THREE.MeshBasicMaterial({
        map: sheets.cardinal,
        transparent: true,
        alphaTest: 0.5,
        side: THREE.DoubleSide,
        fog: false,
    });

    const object = new THREE.Mesh(geometry, material);

    let yaw = 0;
    let frame = 0;

    const show = (
        sheet: 'cardinal' | 'diagonal',
        row: number,
        mirror: boolean,
    ): void => {
        const texture = sheets[sheet];

        if (material.map !== texture) {
            material.map = texture;
            material.needsUpdate = true;
        }

        // A negative repeat draws the cell the other way round, for a direction
        // the sheet has no drawing of. Rows run top to bottom in the atlas, but
        // texture space runs bottom up.
        texture.repeat.x = (mirror ? -1 : 1) / COLUMNS;
        texture.offset.set(
            (frame + (mirror ? 1 : 0)) / COLUMNS,
            (ROWS - 1 - row) / ROWS,
        );
    };

    return {
        object,

        place: (x, floorY, z, bodyYaw, walked) => {
            object.position.set(x, floorY + cell / 2 - underfoot, z);
            yaw = bodyYaw;
            frame = Math.floor(walked / STRIDE) % COLUMNS;
        },

        faceViewer: (viewerX, viewerZ, viewerYaw) => {
            // Flat on to the screen, whatever the body is doing and wherever it
            // stands in the view.
            object.rotation.y = viewerYaw;

            const towardsX = viewerX - object.position.x;
            const towardsZ = viewerZ - object.position.z;

            if (Math.hypot(towardsX, towardsZ) < 1e-4) {
                return;
            }

            // The body faces -Z at a yaw of zero, matching the camera.
            const facingX = -Math.sin(yaw);
            const facingZ = -Math.cos(yaw);

            // Its right-hand side, so the viewer can be placed left or right of it.
            const rightX = -facingZ;
            const rightZ = facingX;

            const ahead = towardsX * facingX + towardsZ * facingZ;
            const beside = towardsX * rightX + towardsZ * rightZ;

            const turn = Math.atan2(beside, ahead);
            const direction = spriteDirection(sprite, turn);

            show(direction.sheet, direction.row, direction.mirror);
        },

        dispose: () => {
            geometry.dispose();
            material.dispose();
            sheets.cardinal.dispose();
            sheets.diagonal.dispose();
        },
    };
}
