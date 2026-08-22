import { fireEvent, screen } from '@testing-library/react';
import { describe, expect, it } from 'vitest';
import { corner, level, room, showInspector } from './inspector-support';

/**
 * One wall of one room.
 *
 * The panel that carries actual reasoning rather than fields: whether the wall
 * is shared, what is on the other side of it, whether the doorway is really
 * open, and whether the portal it names is a pair. Every one of those is a fact
 * about the level rather than about the wall, and every one of them is quiet
 * when it is wrong — a portal with one end looks exactly like a portal until
 * somebody walks into it.
 */

/** Two rooms sharing the wall along z = 4, with the south one's picked. */
function showSharedWall({
    nearBlocks = false,
    farBlocks = false,
    link = null,
}: {
    nearBlocks?: boolean;
    farBlocks?: boolean;
    link?: string | null;
} = {}) {
    return showInspector({
        level: level({
            sectors: [
                room({
                    slug: 'south',
                    name: 'South',
                    points: [
                        corner(0, 0),
                        corner(8, 0),
                        corner(8, 4, { blocks: nearBlocks, portalLink: link }),
                        corner(0, 4),
                    ],
                }),
                room({
                    slug: 'north',
                    name: 'North',
                    points: [
                        corner(0, 4, { blocks: farBlocks }),
                        corner(8, 4),
                        corner(8, 8),
                        corner(0, 8),
                    ],
                }),
            ],
        }),
        selection: { sector: 0, edge: 2 },
        rooms: [0],
    });
}

describe('one wall', () => {
    it('names the room on the other side of it', () => {
        showSharedWall();

        expect(screen.getByText('North')).toBeInTheDocument();
    });

    it('warns that a texture will not show through an open doorway', () => {
        // Passability belongs to the boundary, so with neither side blocking
        // there is nothing there to paint. Painting it anyway is a common way
        // to spend ten minutes wondering why a texture did nothing.
        showSharedWall({ nearBlocks: false, farBlocks: false });

        expect(
            screen.getByText(/a texture will not show/i),
        ).toBeInTheDocument();
    });

    it('drops the warning once the near side blocks', () => {
        showSharedWall({ nearBlocks: true });

        expect(
            screen.queryByText(/a texture will not show/i),
        ).not.toBeInTheDocument();
    });

    it('drops the warning when only the far side blocks', () => {
        // The half that is easy to get wrong. Either side saying no is enough,
        // so reading only the near wall's own flag would still call this open
        // and go on warning about a wall that is solid.
        showSharedWall({ nearBlocks: false, farBlocks: true });

        expect(
            screen.queryByText(/a texture will not show/i),
        ).not.toBeInTheDocument();
    });

    it('says when a portal has only one end', () => {
        // A portal is a pair. One end is a way to nowhere, and nothing else in
        // the editor would ever mention it.
        showSharedWall({ link: 'hop' });

        expect(
            screen.getByText(/the portal does nothing yet/i),
        ).toBeInTheDocument();
    });

    it('says nothing about a portal that is properly paired', () => {
        showInspector({
            level: level({
                sectors: [
                    room({
                        slug: 'south',
                        points: [
                            corner(0, 0),
                            corner(8, 0),
                            corner(8, 4, { portalLink: 'hop' }),
                            corner(0, 4),
                        ],
                    }),
                    room({
                        slug: 'away',
                        points: [
                            corner(40, 0, { portalLink: 'hop' }),
                            corner(48, 0),
                            corner(48, 8),
                            corner(40, 8),
                        ],
                    }),
                ],
            }),
            selection: { sector: 0, edge: 2 },
            rooms: [0],
        });

        expect(
            screen.queryByText(/the portal does nothing yet/i),
        ).not.toBeInTheDocument();
        expect(
            screen.queryByText(/A portal is a pair, so none of them work/i),
        ).not.toBeInTheDocument();

        // Both mouths are eight metres. The panel used to say "give both walls
        // the same length" here anyway — on every correctly paired portal it
        // ever showed, because it never compared them. Paul reported it as
        // always on, and he was right: the condition was that the pair was
        // complete, which is when there is least to complain about.
        expect(
            screen.queryByText(/give both the same length/i),
        ).not.toBeInTheDocument();
    });

    it('says so when the two ends of a portal are different lengths', () => {
        showInspector({
            level: level({
                sectors: [
                    room({
                        slug: 'south',
                        points: [
                            corner(0, 0),
                            corner(8, 0),
                            corner(8, 4, { portalLink: 'hop' }),
                            corner(0, 4),
                        ],
                    }),
                    room({
                        slug: 'away',
                        points: [
                            // Five metres against the south wall's eight, so
                            // the player can walk in past where the far mouth
                            // ends and come out beyond it.
                            corner(40, 0, { portalLink: 'hop' }),
                            corner(45, 0),
                            corner(45, 8),
                            corner(40, 8),
                        ],
                    }),
                ],
            }),
            selection: { sector: 0, edge: 2 },
            rooms: [0],
        });

        // The numbers, not just the complaint. "Give both walls the same
        // length" leaves an author to go and measure two walls by eye; saying
        // which is which tells them what to change and by how much.
        expect(
            screen.getByText(/8\.00 m and its partner is 5\.00 m/i),
        ).toBeInTheDocument();
    });

    it('changes the wall rather than the room it belongs to', () => {
        const { handlers } = showSharedWall();

        fireEvent.click(screen.getByRole('button', { name: 'Mirror' }));

        expect(handlers.onChangeEdge).toHaveBeenCalledWith({ isMirror: true });
        expect(handlers.onChangeSector).not.toHaveBeenCalled();
    });

    it('goes back to the room when the wall is let go of', () => {
        showInspector({
            level: level(),
            selection: { sector: 0, edge: null },
            rooms: [0],
        });

        // The room panel, not the wall one: no portal link to be seen.
        expect(screen.queryByLabelText('Portal link')).not.toBeInTheDocument();
        expect(screen.getByLabelText('Slug')).toBeInTheDocument();
    });
});
