import { fireEvent, render, screen } from '@testing-library/react';
import { describe, expect, it, vi } from 'vitest';
import { HistoryButtons } from '@/components/editor/history-buttons';

/**
 * Undo and redo, as buttons somebody can find.
 *
 * The feature this covers is not the undo — that has worked since the history
 * stack landed. It is that the people drawing levels did not know it existed,
 * because the only trace was a grey line on the map's status bar that showed up
 * *after* their first edit, so it was missing at exactly the moment a new user
 * would look. These tests are therefore about visibility as much as behaviour:
 * present when there is nothing to undo, greyed rather than gone, and carrying
 * the shortcut so somebody can graduate from clicking to typing.
 */
describe('the undo and redo buttons', () => {
    it('are there before anything has been done', () => {
        // The case the old status line got wrong. A first-time user opens the
        // editor, has made no edits, and needs to see that undo exists at all.
        render(
            <HistoryButtons
                canUndo={false}
                canRedo={false}
                onUndo={() => {}}
                onRedo={() => {}}
            />,
        );

        expect(
            screen.getByRole('button', { name: /undo/i }),
        ).toBeInTheDocument();
        expect(
            screen.getByRole('button', { name: /redo/i }),
        ).toBeInTheDocument();
    });

    it('are disabled rather than hidden when there is nowhere to step', () => {
        render(
            <HistoryButtons
                canUndo={false}
                canRedo={false}
                onUndo={() => {}}
                onRedo={() => {}}
            />,
        );

        expect(screen.getByRole('button', { name: /undo/i })).toBeDisabled();
        expect(screen.getByRole('button', { name: /redo/i })).toBeDisabled();
    });

    it('teaches the shortcut, so somebody can stop clicking it', () => {
        render(
            <HistoryButtons
                canUndo
                canRedo
                onUndo={() => {}}
                onRedo={() => {}}
            />,
        );

        expect(screen.getByRole('button', { name: /undo/i })).toHaveAttribute(
            'title',
            'Undo (⌘Z)',
        );
        expect(screen.getByRole('button', { name: /redo/i })).toHaveAttribute(
            'title',
            'Redo (⌘⇧Z)',
        );
    });

    it('steps back when undo is pressed', () => {
        const onUndo = vi.fn();
        const onRedo = vi.fn();

        render(
            <HistoryButtons
                canUndo
                canRedo={false}
                onUndo={onUndo}
                onRedo={onRedo}
            />,
        );

        fireEvent.click(screen.getByRole('button', { name: /undo/i }));

        expect(onUndo).toHaveBeenCalledOnce();
        // And has not quietly done the other thing as well.
        expect(onRedo).not.toHaveBeenCalled();
    });

    it('steps forward when redo is pressed', () => {
        const onRedo = vi.fn();

        render(
            <HistoryButtons
                canUndo={false}
                canRedo
                onUndo={() => {}}
                onRedo={onRedo}
            />,
        );

        fireEvent.click(screen.getByRole('button', { name: /redo/i }));

        expect(onRedo).toHaveBeenCalledOnce();
    });

    it('does nothing at all when there is nothing to step to', () => {
        // A disabled button that still fires would undo past the beginning.
        const onUndo = vi.fn();

        render(
            <HistoryButtons
                canUndo={false}
                canRedo={false}
                onUndo={onUndo}
                onRedo={() => {}}
            />,
        );

        fireEvent.click(screen.getByRole('button', { name: /undo/i }));

        expect(onUndo).not.toHaveBeenCalled();
    });
});
