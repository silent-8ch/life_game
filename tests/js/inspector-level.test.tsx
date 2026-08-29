import { fireEvent, screen } from '@testing-library/react';
import { describe, expect, it } from 'vitest';
import { level, showInspector } from './inspector-support';

/**
 * The level itself, which is what the panel shows when nothing is picked.
 *
 * Most of it is plain fields. The part that earns tests is the sky: it is a
 * whole object or nothing at all, so it is the only place here where a control
 * can appear, disappear, or write into a thing that is not there.
 *
 * It is picked from one list, one line per file, and the chosen one is shown,
 * because nobody can tell Day 2 from Day 3 by name.
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

    it('shows no preview while there is no sky', () => {
        // A preview is a picture *of* a sky. With none there is nothing to
        // show, and a box of empty background would read as a broken image.
        showLevel({ sky: null });

        expect(screen.getByLabelText('Sky')).toHaveValue('');
        expect(document.querySelector('[style*="sprites/bg"]')).toBeNull();
    });

    it('offers every panorama as its own line, not a file and then a cell', () => {
        // One file is one sky. There is no second question about which cell of
        // which strip, because there are no strips.
        showLevel();

        expect(screen.queryByLabelText('Variant')).not.toBeInTheDocument();
        expect(screen.queryByLabelText('Horizon')).not.toBeInTheDocument();
        expect(
            [...screen.getByLabelText('Sky').querySelectorAll('option')].map(
                (option) => option.textContent,
            ),
        ).toEqual(['Indoors, no sky', 'Day 1', 'Day 2', 'Night 1', 'Night 4']);
    });

    it('shows the panorama that is picked', () => {
        showLevel({ sky: { image: 'sky-day-2' } });

        expect(screen.getByLabelText('Sky')).toHaveValue('sky-day-2');

        const preview = document.querySelector<HTMLElement>(
            '[style*="sprites/bg"]',
        );

        // The whole file, not a slice of one. Slicing is what turned a
        // single-image sky into four stretched quarters of itself.
        expect(preview?.style.backgroundImage).toContain(
            '/sprites/bg/sky-day-2.png',
        );
        expect(preview?.style.backgroundSize).toBe('100% 100%');
    });

    it('names the file it was given and nothing else', () => {
        const { handlers } = showLevel({ sky: null });

        fireEvent.change(screen.getByLabelText('Sky'), {
            target: { value: 'sky-night-4' },
        });

        expect(handlers.onChangeLevel).toHaveBeenCalledWith({
            sky: { image: 'sky-night-4' },
        });
    });

    it('clears the sky rather than leaving half of one behind', () => {
        const { handlers } = showLevel({ sky: { image: 'sky-day-2' } });

        fireEvent.change(screen.getByLabelText('Sky'), {
            target: { value: '' },
        });

        expect(handlers.onChangeLevel).toHaveBeenCalledWith({ sky: null });
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
