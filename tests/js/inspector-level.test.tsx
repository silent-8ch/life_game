import { fireEvent, screen } from '@testing-library/react';
import { describe, expect, it } from 'vitest';
import { level, showInspector } from './inspector-support';

/**
 * The level itself, which is what the panel shows when nothing is picked.
 *
 * Most of it is plain fields. The part that earns tests is the sky: it is a
 * whole object or nothing at all, with three settings that only mean anything
 * once there is one, so it is the only place here where a control can appear,
 * disappear, or write into a thing that is not there.
 */

const showLevel = (changes: Parameters<typeof level>[0] = {}) =>
    showInspector({ level: level(changes), selection: null });

describe('the level', () => {
    it('is what you get when nothing is picked', () => {
        showLevel();

        expect(screen.getByLabelText('Default ceiling height')).toHaveValue(3);
        expect(screen.getByLabelText('You play as')).toBeInTheDocument();
    });

    it('says where the player starts, and which way they face', () => {
        showLevel();

        expect(screen.getByLabelText('Spawn X')).toHaveValue(1);
        expect(screen.getByLabelText('Spawn Z')).toHaveValue(1);
        expect(screen.getByLabelText('Facing')).toHaveValue(0);
    });

    it('hides the sky settings while there is no sky', () => {
        // Variant and horizon are settings *of* a sky. With none they would be
        // writing into an object that does not exist.
        showLevel({ sky: null });

        expect(screen.queryByLabelText('Variant')).not.toBeInTheDocument();
        expect(screen.queryByLabelText('Horizon')).not.toBeInTheDocument();
    });

    it('shows the sky settings once there is one', () => {
        showLevel({
            sky: { image: 'sky-day', variant: 2, theme: 'hills', layers: [1] },
        } as Parameters<typeof level>[0]);

        expect(screen.getByLabelText('Variant')).toHaveValue('2');
        expect(screen.getByLabelText('Horizon')).toBeInTheDocument();
    });

    it('changes the level rather than any room', () => {
        // There is a room in the level and none of it is picked. Writing
        // through the sector path here would edit whichever room happened to
        // be first, which is the kind of thing that looks fine until a level
        // has two rooms.
        const { handlers } = showLevel();

        fireEvent.change(screen.getByLabelText('Default ceiling height'), {
            target: { value: '4' },
        });
        fireEvent.blur(screen.getByLabelText('Default ceiling height'));

        expect(handlers.onChangeLevel).toHaveBeenCalledWith({
            ceilingHeight: 4,
        });
        expect(handlers.onChangeSector).not.toHaveBeenCalled();
        expect(handlers.onChangeRooms).not.toHaveBeenCalled();
    });
});
