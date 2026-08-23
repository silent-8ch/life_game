import type { Item, LevelThing, VerbOffer } from '@/types';

/**
 * Which verbs to offer for a thing.
 *
 * The server says what a thing answers to; this tidies that down to what is
 * worth putting in front of the player right now. It lives apart from the menu
 * that draws it because the inventory changes without the level being resent,
 * so the filtering has to happen in the browser — but it is only ever a
 * tidying. Anything wrongly offered is refused when it is sent.
 */
export function offersFor(thing: LevelThing, inventory: Item[]): VerbOffer[] {
    const carried = new Set(inventory.map((item) => item.slug));

    const offered = thing.verbs.filter(
        (offer) => offer.item === null || carried.has(offer.item),
    );

    // Everything in a level has a description, and looking at it is what shows
    // it. A menu with no entries at all would be a dead end.
    return offered.some((offer) => offer.verb === 'look' && offer.item === null)
        ? offered
        : [
              // Looking moves nothing, which is why the fallback can name an
              // empty list without knowing anything about what does.
              { verb: 'look', item: null, moves: [] },
              ...offered,
          ];
}
