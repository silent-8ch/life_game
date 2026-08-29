import { fireEvent, render, screen } from '@testing-library/react';
import { describe, expect, it, vi } from 'vitest';
import Inspector from '@/components/editor/inspector';
import type { Level, LevelAssets, Sector } from '@/types';

/**
 * The first component test in the project.
 *
 * Everything else in `resources/js` is tested by running it under node in a
 * subprocess, which cannot render a component — so `inspector.tsx` (1300 lines
 * and growing), `map-view.tsx` and `side-view.tsx` have had no coverage and no
 * way to write any. This is the harness for that, proved on the file it exists
 * for rather than on a toy.
 *
 * The slope panel is a deliberate choice of subject. Its behaviour is a rule
 * rather than a rendering — picking Flat has to clear the rise as well, because
 * a rise hinged on nothing is a number that does nothing — and that rule lives
 * in an `onChange` handler where nothing else could reach it.
 */

const room = (changes: Partial<Sector> = {}): Sector => ({
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
    points: [
        {
            x: 0,
            z: 0,
            wallTexture: null,
            blocks: false,
            isMirror: false,
            isSky: false,
            portalLink: null,
        },
        {
            x: 8,
            z: 0,
            wallTexture: null,
            blocks: false,
            isMirror: false,
            isSky: false,
            portalLink: null,
        },
        {
            x: 8,
            z: 4,
            wallTexture: null,
            blocks: false,
            isMirror: false,
            isSky: false,
            portalLink: null,
        },
        {
            x: 0,
            z: 4,
            wallTexture: null,
            blocks: false,
            isMirror: false,
            isSky: false,
            portalLink: null,
        },
    ],
    ...changes,
});

const level = (sector: Sector): Level =>
    ({
        slug: 'test',
        name: 'Test',
        description: '',
        spawn: { x: 1, z: 1, angle: 0 },
        ceilingHeight: 3,
        spriteStyle: 'realistic',
        playerSprite: 'paul',
        playerStats: null,
        wallColor: '#fff',
        floorColor: '#888',
        accentColor: '#fc0',
        sky: null,
        things: [],
        sectors: [sector],
    }) as unknown as Level;

const assets: LevelAssets = {
    textures: ['oak-floor'],
    props: [],
    skies: [],
    sprites: ['paul'],
    styles: ['realistic'],
    items: [],
} as unknown as LevelAssets;

function showRoom(sector: Sector) {
    const onChangeSector = vi.fn();

    render(
        <Inspector
            level={level(sector)}
            assets={assets}
            selection={{ sector: 0, edge: null }}
            rooms={[0]}
            thing={null}
            onChangeLevel={vi.fn()}
            onChangeSector={onChangeSector}
            onChangeRooms={vi.fn()}
            onChangeRoomWalls={vi.fn()}
            onDeleteRooms={vi.fn()}
            onChangeEdge={vi.fn()}
            onChangeThing={vi.fn()}
            onDeleteThing={vi.fn()}
            onDeleteSector={vi.fn()}
        />,
    );

    return { onChangeSector };
}

describe('the slope panel', () => {
    it('names the walls by the side of the room they are on', () => {
        showRoom(room());

        const hinge = screen.getByLabelText('Floor hinged on');

        // Not "wall 3". The room spans z 0..4, so its z = 0 wall is the north
        // one, north being -z.
        expect(hinge).toHaveTextContent('1 — north');
        expect(hinge).toHaveTextContent('2 — east');
        expect(hinge).toHaveTextContent('Flat');
    });

    it('sets the hinge without disturbing the rise', () => {
        const { onChangeSector } = showRoom(room({ floorSlope: 0.5 }));

        fireEvent.change(screen.getByLabelText('Floor hinged on'), {
            target: { value: '2' },
        });

        expect(onChangeSector).toHaveBeenCalledWith({
            floorSlope: 0.5,
            floorSlopeEdge: 2,
        });
    });

    it('clears the rise when the floor is set back to flat', () => {
        // The rule this test exists for. A rise hinged on nothing is a number
        // that does nothing, and leaving it behind means the next hinge picked
        // silently applies an old slope somebody thought they had removed.
        const { onChangeSector } = showRoom(
            room({ floorSlope: 0.5, floorSlopeEdge: 0 }),
        );

        fireEvent.change(screen.getByLabelText('Floor hinged on'), {
            target: { value: '' },
        });

        expect(onChangeSector).toHaveBeenCalledWith({
            floorSlope: 0,
            floorSlopeEdge: null,
        });
    });

    it('keeps the floor and the ceiling apart', () => {
        // They are the same control twice over, built by one function. Wiring
        // both to the same field is the obvious way to get that wrong, and it
        // would look right until somebody sloped a ceiling.
        const { onChangeSector } = showRoom(room({ ceilingSlope: 0.25 }));

        fireEvent.change(screen.getByLabelText('Ceiling hinged on'), {
            target: { value: '1' },
        });

        expect(onChangeSector).toHaveBeenCalledWith({
            ceilingSlope: 0.25,
            ceilingSlopeEdge: 1,
        });
    });

    it('says nothing about hinge heights while the room is flat', () => {
        showRoom(room());

        expect(
            screen.queryByText(/heights along the hinge wall/i),
        ).not.toBeInTheDocument();
    });

    it('explains what the heights above mean once a slope is set', () => {
        // The one thing about this feature somebody would otherwise learn from
        // a room that looks wrong: the floor height is the height along the
        // hinge wall, not everywhere.
        showRoom(room({ floorSlope: 0.5, floorSlopeEdge: 0 }));

        expect(
            screen.getByText(/heights along the hinge wall/i),
        ).toBeInTheDocument();
    });
});
