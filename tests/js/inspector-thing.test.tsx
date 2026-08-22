import { fireEvent, screen } from '@testing-library/react';
import { describe, expect, it } from 'vitest';
import { level, person, prop, showInspector } from './inspector-support';

/**
 * Working on one thing: a person, or a piece of furniture.
 *
 * A person and a prop share a panel and almost nothing else. A person is drawn
 * from a sprite sheet and has a stat block; a prop is built as a box and has a
 * shape, a texture and frames. Showing either the wrong half is the fault worth
 * guarding, because it is not a crash — it is a control that quietly does
 * nothing, and the author is left wondering why the number they set had no
 * effect.
 */

const showThing = (held: ReturnType<typeof prop>) =>
    showInspector({
        level: level({ things: [held] }),
        selection: null,
        thing: 0,
    });

describe('a prop', () => {
    it('is offered a shape, and a person is not', () => {
        showThing(prop());

        expect(screen.getByLabelText('Shape')).toBeInTheDocument();
    });

    it('shows the plane count only once it is a cross', () => {
        // A plane count means nothing to a box or a billboard. Showing it
        // anyway invites somebody to set a number that is read by nothing.
        showThing(prop({ render: 'box' }));

        expect(screen.queryByLabelText('Planes')).not.toBeInTheDocument();
    });

    it('shows the plane count when it is a cross', () => {
        showThing(prop({ render: 'cross', planeCount: 3 }));

        expect(screen.getByLabelText('Planes')).toHaveValue('3');
    });

    it('offers the tiling toggle only to a box', () => {
        // Fitting is per-face, and a billboard or a cross is one face already.
        showThing(prop({ render: 'box' }));

        expect(
            screen.getByRole('button', {
                name: 'Stretch the texture to fit each face',
            }),
        ).toBeInTheDocument();
    });

    it('hides the tiling toggle from a cross', () => {
        showThing(prop({ render: 'cross' }));

        expect(
            screen.queryByRole('button', {
                name: 'Stretch the texture to fit each face',
            }),
        ).not.toBeInTheDocument();
    });

    it('asks how fast the frames go only when there is more than one', () => {
        showThing(prop({ animationFrames: 1 }));

        expect(
            screen.queryByLabelText('Frames a second'),
        ).not.toBeInTheDocument();
    });

    it('asks how fast the frames go once it is animated', () => {
        showThing(prop({ animationFrames: 4 }));

        expect(screen.getByLabelText('Frames a second')).toHaveValue(8);
    });

    it('clears the flag when the alternate texture goes', () => {
        // The two are refused as a pair at save, so leaving the flag behind
        // would put the editor in a state that cannot be saved and not say why.
        const { handlers } = showThing(
            prop({ textureAlt: 'pot-plant', altFlag: 'lamp-on' }),
        );

        expect(screen.getByLabelText('Flag')).toHaveValue('lamp-on');

        fireEvent.change(screen.getByLabelText('Flag'), {
            target: { value: '' },
        });

        expect(handlers.onChangeThing).toHaveBeenCalledWith({ altFlag: null });
    });

    it('asks for a flag only once there is an alternate texture to show', () => {
        showThing(prop());

        expect(screen.queryByLabelText('Flag')).not.toBeInTheDocument();
    });

    it('is not offered a stat block', () => {
        showThing(prop());

        expect(screen.queryByText('Stats')).not.toBeInTheDocument();
    });
});

describe('a person', () => {
    it('is offered a stat block', () => {
        showThing(person());

        expect(screen.getByText('Stats')).toBeInTheDocument();
    });

    it('is not offered a shape, a texture or frames', () => {
        // A person is drawn from a sprite sheet. None of the box rendering
        // applies, and every one of those controls would be a dead end.
        showThing(person());

        expect(screen.queryByLabelText('Shape')).not.toBeInTheDocument();
        expect(screen.queryByLabelText('Frames')).not.toBeInTheDocument();
    });

    it('is asked which sheet they are drawn from, and how they move', () => {
        showThing(person());

        expect(screen.getByLabelText('Drawn from')).toBeInTheDocument();
        expect(screen.getByLabelText('Behaviour')).toBeInTheDocument();
    });

    it('reads as off while a person is inheriting their sprite s stats', () => {
        showThing(person({ stats: null }));

        // Explicitly false rather than merely not-true: `.not.toBePressed()`
        // also passes when the attribute is missing altogether, which is the
        // state this test exists to rule out.
        expect(
            screen.getByRole('button', { name: 'Override stats' }),
        ).toHaveAttribute('aria-pressed', 'false');
    });

    it('reads as on once they have their own', () => {
        showThing(
            person({
                stats: {
                    strength: 5,
                    perception: 5,
                    endurance: 5,
                    charisma: 5,
                    intelligence: 5,
                    agility: 5,
                    luck: 5,
                },
            }),
        );

        expect(
            screen.getByRole('button', { name: 'Override stats' }),
        ).toBePressed();
    });

    it('takes over a complete stat block, never half of one', () => {
        // Null means "whatever their sprite starts with", and switching the
        // override on has to hand back all seven attributes: a partial block is
        // refused at save, and half a stat block is not a meaningful thing to
        // store.
        const { handlers } = showThing(person({ stats: null }));

        fireEvent.click(screen.getByRole('button', { name: 'Override stats' }));

        const [change] = handlers.onChangeThing.mock.calls[0];

        expect(Object.keys(change.stats)).toHaveLength(7);
    });

    it('gives the block back when the override is switched off again', () => {
        // The other direction, and the one that loses data if it is wrong:
        // null is how a person goes back to inheriting, and anything else
        // leaves them pinned to numbers they were only shown.
        const { handlers } = showThing(
            person({
                stats: {
                    strength: 5,
                    perception: 5,
                    endurance: 5,
                    charisma: 5,
                    intelligence: 5,
                    agility: 5,
                    luck: 5,
                },
            }),
        );

        fireEvent.click(screen.getByRole('button', { name: 'Override stats' }));

        expect(handlers.onChangeThing).toHaveBeenCalledWith({ stats: null });
    });
});
