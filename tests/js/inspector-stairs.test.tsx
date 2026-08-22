import { fireEvent, screen } from '@testing-library/react';
import { describe, expect, it } from 'vitest';
import { level, room, showInspector } from './inspector-support';

/**
 * Turning a room into a flight of steps.
 *
 * The dialog is small and the carve is not: it replaces the room with N rooms,
 * and there is no partial version of that to back out of. So what is worth
 * pinning is the asking — that it takes two presses, that changing your mind
 * about the plan un-asks the question, and that a plan which cannot be carved
 * cannot be pressed at all.
 *
 * The arithmetic lives in `StairsTest`; this is about not doing it by accident.
 */

const showRoom = (changes: Parameters<typeof room>[0] = {}) =>
    showInspector({
        level: level({ sectors: [room(changes)] }),
        selection: { sector: 0, edge: null },
        rooms: [0],
    });

const carveButton = () =>
    screen.getByRole('button', { name: /carve into steps|replace/i });

describe('the stairs panel', () => {
    it('asks before it carves', () => {
        const { handlers } = showRoom();

        fireEvent.click(carveButton());

        // Nothing yet. The first press turns the button into the question.
        expect(handlers.onCarveStairs).not.toHaveBeenCalled();
        expect(carveButton()).toHaveTextContent('Replace Hall with 4 steps?');
    });

    it('carves on the second press, with the plan that was shown', () => {
        const { handlers } = showRoom();

        fireEvent.click(carveButton());
        fireEvent.click(carveButton());

        expect(handlers.onCarveStairs).toHaveBeenCalledWith({
            steps: 4,
            rise: 1,
            fromEdge: 0,
        });
    });

    it('un-asks the question when the plan changes underneath it', () => {
        // Otherwise the confirmation is for a flight nobody looked at: press
        // once, think again about the rise, and the second press would carve
        // something you never confirmed.
        const { handlers } = showRoom();

        fireEvent.click(carveButton());
        expect(carveButton()).toHaveTextContent('Replace');

        fireEvent.change(screen.getByLabelText('Steps'), {
            target: { value: '6' },
        });
        fireEvent.blur(screen.getByLabelText('Steps'));

        expect(carveButton()).toHaveTextContent('Carve into steps');
        expect(handlers.onCarveStairs).not.toHaveBeenCalled();
    });

    it('names the walls the way the slope picker does', () => {
        showRoom();

        // The same question — which wall does this run from — asked the same
        // way, rather than one panel counting corners and the other naming
        // sides of the room.
        expect(screen.getByLabelText('Starting from')).toHaveTextContent(
            '1 — north',
        );
    });

    it('warns about a step nobody can climb, and carves it anyway', () => {
        // Advice rather than a rule. Whether a rise is climbable is a traversal
        // question and traversal is becoming a runtime decision, so the carve
        // holds no opinion — it cuts what was asked for.
        const { handlers } = showRoom();

        fireEvent.change(screen.getByLabelText('Climbing'), {
            target: { value: '8' },
        });
        fireEvent.blur(screen.getByLabelText('Climbing'));

        expect(
            screen.getByText(/taller than anybody can/i),
        ).toBeInTheDocument();

        fireEvent.click(carveButton());
        fireEvent.click(carveButton());

        expect(handlers.onCarveStairs).toHaveBeenCalledWith({
            steps: 4,
            rise: 8,
            fromEdge: 0,
        });
    });

    it('will not carve a flight that is not a flight', () => {
        showRoom();

        fireEvent.change(screen.getByLabelText('Climbing'), {
            target: { value: '0' },
        });
        fireEvent.blur(screen.getByLabelText('Climbing'));

        expect(screen.getByText(/just a room/i)).toBeInTheDocument();
        expect(carveButton()).toBeDisabled();
    });
});
