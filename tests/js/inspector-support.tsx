import { render } from '@testing-library/react';
import { vi } from 'vitest';
import Inspector from '@/components/editor/inspector';
import type {
    Level,
    LevelAssets,
    LevelThing,
    Sector,
    SectorPoint,
} from '@/types';

/**
 * Shared scaffolding for the Inspector's tests.
 *
 * One place for the fixtures, because the Inspector wants a whole level and
 * fifteen callbacks whichever of its five panels you are looking at, and
 * repeating that in five files would bury the thing each test is about.
 */

export const corner = (
    x: number,
    z: number,
    changes: Partial<SectorPoint> = {},
): SectorPoint => ({
    x,
    z,
    wallTexture: null,
    blocks: false,
    isMirror: false,
    isSky: false,
    portalLink: null,
    ...changes,
});

/** A four-by-eight room, its corners wound the way the editor draws them. */
export const room = (changes: Partial<Sector> = {}): Sector => ({
    slug: 'hall',
    name: 'Hall',
    floorHeight: 0,
    ceilingHeight: 3,
    floorSlope: 0,
    floorSlopeEdge: null,
    ceilingSlope: 0,
    ceilingSlopeEdge: null,
    floorTexture: null,
    ceilingTexture: null,
    wallTexture: null,
    isSky: false,
    isWater: false,
    points: [corner(0, 0), corner(8, 0), corner(8, 4), corner(0, 4)],
    ...changes,
});

export const prop = (changes: Partial<LevelThing> = {}): LevelThing =>
    ({
        slug: 'crate',
        name: 'Crate',
        description: 'A wooden crate.',
        kind: 'prop',
        sprite: null,
        behaviour: null,
        stats: null,
        speed: 0,
        texture: null,
        render: 'box',
        planeCount: 2,
        uvMode: 'tile',
        textureAlt: null,
        altFlag: null,
        animationFrames: 1,
        animationFps: 8,
        x: 2,
        z: 2,
        elevation: 0,
        width: 0.6,
        depth: 0.6,
        height: 0.6,
        angle: 0,
        isSolid: true,
        isDoor: false,
        swing: 'swing',
        openAngle: 90,
        openSeconds: 0.4,
        isOpen: false,
        opensFlag: null,
        verbs: [],
        interactions: [],
        ...changes,
    }) as unknown as LevelThing;

export const person = (changes: Partial<LevelThing> = {}): LevelThing =>
    prop({
        slug: 'krystal',
        name: 'Krystal',
        kind: 'actor',
        sprite: 'krystal',
        behaviour: 'wander',
        speed: 1.1,
        height: 1.7,
        isSolid: false,
        ...changes,
    });

export const assets = {
    textures: ['oak-floor', 'red-brick'],
    props: ['pot-plant'],
    skies: ['sky-day', 'sky-night'],
    backdrops: { hills: [1, 2, 3] },
    sprites: ['paul', 'krystal'],
    styles: ['realistic', 'stylized'],
    items: [{ slug: 'key', name: 'Key' }],
} as unknown as LevelAssets;

export const level = (changes: Partial<Level> = {}): Level =>
    ({
        slug: 'test',
        name: 'Test level',
        description: 'Somewhere to stand.',
        spawn: { x: 1, z: 1, angle: 0 },
        ceilingHeight: 3,
        spriteStyle: 'realistic',
        playerSprite: 'paul',
        playerStats: null,
        wallColor: '#ffffff',
        floorColor: '#888888',
        accentColor: '#ffcc00',
        sky: null,
        things: [],
        sectors: [room()],
        ...changes,
    }) as unknown as Level;

export type Handlers = ReturnType<typeof showInspector>['handlers'];

/**
 * Renders the Inspector and hands back every callback as a spy, so a test can
 * say which one a control reached as well as what it sent.
 */
export function showInspector({
    level: shown,
    selection = null,
    rooms = [],
    thing = null,
}: {
    level: Level;
    selection?: { sector: number; edge: number | null } | null;
    rooms?: number[];
    thing?: number | null;
}) {
    const handlers = {
        onChangeLevel: vi.fn(),
        onChangeSector: vi.fn(),
        onChangeRooms: vi.fn(),
        onChangeRoomWalls: vi.fn(),
        onDeleteRooms: vi.fn(),
        onChangeEdge: vi.fn(),
        onCarveStairs: vi.fn(),
        onChangeThing: vi.fn(),
        onDeleteThing: vi.fn(),
        onDeleteSector: vi.fn(),
    };

    render(
        <Inspector
            level={shown}
            assets={assets}
            selection={selection}
            rooms={rooms}
            thing={thing}
            {...handlers}
        />,
    );

    return { handlers };
}
