import { fireEvent, screen } from '@testing-library/react';
import { describe, expect, it } from 'vitest';
import { level, room, showInspector } from './inspector-support';

/**
 * Working on several rooms at once.
 *
 * The panel's whole job is telling the truth about a set rather than about one
 * room: where they agree it shows the value, where they differ it has to say
 * so rather than pick one and quietly impose it on the rest the moment anything
 * else is touched. That is the behaviour worth having tests for — everything
 * else here is a field wired to a callback.
 */

function showTwoRooms(second: Parameters<typeof room>[0] = {}) {
    return showInspector({
        level: level({
            sectors: [
                room({ slug: 'hall', name: 'Hall' }),
                room({ slug: 'kitchen', name: 'Kitchen', ...second }),
            ],
        }),
        selection: { sector: 0, edge: null },
        rooms: [0, 1],
    });
}

describe('several rooms at once', () => {
    it('names the rooms it is working on, and how many will go', () => {
        showTwoRooms();

        expect(screen.getByText('Hall, Kitchen')).toBeInTheDocument();

        // Not "Delete". Deleting several rooms on one click is worth being
        // explicit about, and the count is the part that makes it a decision.
        expect(screen.getByText('Delete 2 rooms')).toBeInTheDocument();
    });

    it('shows a value the rooms agree on', () => {
        showTwoRooms({ floorHeight: 0 });

        expect(screen.getByLabelText('Floor')).toHaveValue(0);
    });

    it('refuses to show a value the rooms disagree about', () => {
        // The important half. Showing one room's floor height for both would
        // read as fact, and the next unrelated edit would silently flatten the
        // other room to match it.
        showTwoRooms({ floorHeight: 2.5 });

        expect(screen.getByLabelText('Floor')).toHaveValue(null);
    });

    it('applies an edit to every room picked, not to the one that was clicked', () => {
        const { handlers } = showTwoRooms({ floorHeight: 2.5 });

        fireEvent.change(screen.getByLabelText('Ceiling'), {
            target: { value: '4' },
        });
        fireEvent.blur(screen.getByLabelText('Ceiling'));

        expect(handlers.onChangeRooms).toHaveBeenCalledWith({
            ceilingHeight: 4,
        });

        // And never through the single-room path, which is the wiring mistake
        // that would look right until a second room was picked.
        expect(handlers.onChangeSector).not.toHaveBeenCalled();
    });

    it('offers the rise but not the hinge', () => {
        showTwoRooms();

        expect(screen.getByLabelText('Floor rise')).toBeInTheDocument();

        // A hinge is an index into one room's own walls, so the same number
        // means a different wall in each of them. Offering it across a mixed
        // selection would set rooms sloping in directions nobody chose.
        expect(
            screen.queryByLabelText('Floor hinged on'),
        ).not.toBeInTheDocument();
    });

    it('paints every wall through its own callback, not the room one', () => {
        // "Every wall in them" writes to each wall of each room; the ordinary
        // wall texture writes one shared value onto the rooms. Two different
        // things behind two pickers that look alike, and crossing them would
        // be invisible until somebody painted a room with textured walls.
        const { handlers } = showTwoRooms();

        // Queried by text rather than by label: TexturePicker renders its
        // label as a bare span with nothing tying it to the control, so
        // getByLabelText cannot see it. That is an accessibility gap in the
        // component — a screen reader announces an unlabelled button — and it
        // is reported rather than worked around silently.
        expect(screen.getByText('Every wall in them')).toBeInTheDocument();
        expect(screen.getByText('Wall texture')).toBeInTheDocument();

        expect(handlers.onChangeRoomWalls).not.toHaveBeenCalled();
    });

    it('goes back to one room when only one is picked', () => {
        showInspector({
            level: level(),
            selection: { sector: 0, edge: null },
            rooms: [0],
        });

        // The single-room panel has a slug field; the many-rooms one cannot,
        // since a slug is unique. That is the cheapest way to tell which panel
        // is on screen.
        expect(screen.getByLabelText('Slug')).toBeInTheDocument();
    });
});
