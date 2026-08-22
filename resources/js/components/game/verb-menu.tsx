import { useEffect } from 'react';
import { offersFor } from '@/lib/verb-offers';
import type { Item, LevelThing, VerbName } from '@/types';

/**
 * What the player can do to the thing under the crosshair.
 *
 * The 2D game has a verb bar along the bottom of the screen; there is no room
 * for one here, and no mouse to use it with while the pointer is locked. So the
 * verbs come to the thing instead: press E, and the short list of what this
 * particular thing answers to appears, each entry on a number key.
 *
 * Looking is always offered. A thing with nothing else to it still has a
 * description, and that is what looking at it means.
 */

const LABELS: Record<VerbName, string> = {
    look: 'Look at',
    use: 'Use',
    take: 'Take',
    talk: 'Talk to',
};

export type VerbChoice = {
    verb: VerbName;
    /** The item to use it with, by slug, or null. */
    item: string | null;
};

type VerbMenuProps = {
    thing: LevelThing;
    inventory: Item[];
    onChoose: (choice: VerbChoice) => void;
    onClose: () => void;
};

export default function VerbMenu({
    thing,
    inventory,
    onChoose,
    onClose,
}: VerbMenuProps) {
    const offers = offersFor(thing, inventory);
    const named = (slug: string): string =>
        inventory.find((item) => item.slug === slug)?.name ?? slug;

    // The pointer is locked while the level is being played, so there is nothing
    // to click with. Every entry is on its own number key, and E closes the menu
    // the same way it opened it.
    useEffect(() => {
        const handleKeyDown = (event: KeyboardEvent): void => {
            if (event.code === 'KeyE' || event.code === 'Escape') {
                onClose();

                return;
            }

            const picked = offers[Number(event.key) - 1];

            if (picked !== undefined) {
                event.preventDefault();
                onChoose({ verb: picked.verb, item: picked.item });
            }
        };

        window.addEventListener('keydown', handleKeyDown);

        return () => window.removeEventListener('keydown', handleKeyDown);
    }, [offers, onChoose, onClose]);

    return (
        <div className="pointer-events-auto absolute bottom-8 left-1/2 w-64 -translate-x-1/2 rounded-lg border border-amber-100/20 bg-black/85 p-2 text-left">
            <p className="px-2 pb-1 text-xs tracking-widest text-amber-100/50 uppercase">
                {thing.name}
            </p>

            <ul>
                {offers.map((offer, index) => (
                    <li key={`${offer.verb}-${offer.item ?? ''}`}>
                        <button
                            type="button"
                            className="flex w-full items-baseline gap-2 rounded px-2 py-1 text-sm text-amber-100 hover:bg-amber-100/15"
                            onClick={() =>
                                onChoose({
                                    verb: offer.verb,
                                    item: offer.item,
                                })
                            }
                        >
                            <span className="text-amber-100/40 tabular-nums">
                                {index + 1}
                            </span>
                            <span>
                                {LABELS[offer.verb]}
                                {offer.item !== null && (
                                    <span className="text-amber-100/60">
                                        {' '}
                                        with the {named(offer.item)}
                                    </span>
                                )}
                            </span>
                        </button>
                    </li>
                ))}
            </ul>

            <p className="px-2 pt-1 text-xs text-amber-100/40">E to close</p>
        </div>
    );
}
