import { fireEvent, screen } from '@testing-library/react';
import { describe, expect, it } from 'vitest';
import { level, prop, showInspector } from './inspector-support';

/**
 * A thing that opens.
 *
 * Two rules carry the panel. Everything is behind the one toggle, because a
 * swing angle on a bookcase is a control that does nothing. And turning a thing
 * into a door makes it solid, because a door's collider leaving the set while
 * it opens only means something if it was in the set to begin with — a door you
 * could already walk through would open silently and change nothing.
 */

const showThing = (held: ReturnType<typeof prop>) =>
    showInspector({
        level: level({ things: [held] }),
        selection: null,
        thing: 0,
    });

describe('the door panel', () => {
    it('asks nothing about opening until something opens', () => {
        showThing(prop({ isDoor: false }));

        expect(screen.queryByLabelText('Moves by')).not.toBeInTheDocument();
        expect(screen.queryByLabelText('Taking')).not.toBeInTheDocument();
        expect(
            screen.queryByRole('button', { name: 'Starts open' }),
        ).not.toBeInTheDocument();
    });

    it('asks how it moves once it does', () => {
        showThing(prop({ isDoor: true }));

        expect(screen.getByLabelText('Moves by')).toHaveValue('swing');
        expect(screen.getByLabelText('Taking')).toHaveValue(0.4);
    });

    it('makes a thing solid when it becomes a door', () => {
        // Otherwise the collider has nothing to leave. A door you can already
        // walk through opens silently and changes nothing, and the author would
        // have no way to tell why.
        const { handlers } = showThing(prop({ isDoor: false, isSolid: false }));

        fireEvent.click(screen.getByRole('button', { name: 'This opens' }));

        expect(handlers.onChangeThing).toHaveBeenCalledWith({
            isDoor: true,
            isSolid: true,
        });
    });

    it('does not un-solid a thing that stops being a door', () => {
        // The other direction is not symmetrical. A wall-solid crate that was
        // briefly a door is still a crate, and quietly making it walk-through
        // would be a second change nobody asked for.
        const { handlers } = showThing(prop({ isDoor: true, isSolid: true }));

        fireEvent.click(screen.getByRole('button', { name: 'This opens' }));

        expect(handlers.onChangeThing).toHaveBeenCalledWith({
            isDoor: false,
            isSolid: true,
        });
    });

    it('renames the angle for a slider, which does not have one', () => {
        // A swing door opens *to* an angle; a slider opens *by* a fraction of
        // its width. Same number, different meaning, and the label is the only
        // place that difference is visible.
        showThing(prop({ isDoor: true, swing: 'swing' }));
        expect(screen.getByLabelText('Opens to')).toBeInTheDocument();

        showThing(prop({ isDoor: true, swing: 'slide' }));
        expect(screen.getByLabelText('Opens by')).toBeInTheDocument();
    });

    it('says whether the door will be remembered', () => {
        showThing(prop({ isDoor: true, opensFlag: null }));

        expect(
            screen.getByText(/shuts again every time the level loads/i),
        ).toBeInTheDocument();
    });

    it('says so when it will be', () => {
        showThing(prop({ isDoor: true, opensFlag: 'front-door-open' }));

        expect(screen.getByText(/open again next time/i)).toBeInTheDocument();
    });

    it('clears the flag rather than remembering an empty name', () => {
        const { handlers } = showThing(
            prop({ isDoor: true, opensFlag: 'front-door-open' }),
        );

        fireEvent.change(screen.getByLabelText('Remembered as'), {
            target: { value: '' },
        });

        expect(handlers.onChangeThing).toHaveBeenCalledWith({
            opensFlag: null,
        });
    });
});
