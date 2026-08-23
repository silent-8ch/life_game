import { Head, Link, router } from '@inertiajs/react';
import { useCallback, useRef, useState } from 'react';
import { index } from '@/actions/App/Http/Controllers/GameController';
import { store } from '@/actions/App/Http/Controllers/InteractionController';
import { store as reportFault } from '@/actions/App/Http/Controllers/SupportTicketController';
import InventoryTray from '@/components/game/inventory-tray';
import LevelViewport from '@/components/game/level-viewport';
import VerbMenu from '@/components/game/verb-menu';
import type { VerbChoice } from '@/components/game/verb-menu';
import type { MovedThings } from '@/lib/engine/build/things';
import type { ExplorePageProps, LevelThing } from '@/types';

export default function Explore({
    game,
    level,
    flags,
    moved,
    inventory,
    message,
}: ExplorePageProps) {
    const [focused, setFocused] = useState<LevelThing | null>(null);
    const [locked, setLocked] = useState(false);
    const [note, setNote] = useState<string | null>(message);

    // The thing the player pressed E on, and so the thing the verb menu is
    // asking about. Null when the menu is closed.
    const [asking, setAsking] = useState<LevelThing | null>(null);

    const examine = useCallback((thing: LevelThing) => {
        setAsking(thing);
    }, []);

    const close = useCallback(() => setAsking(null), []);

    /** What this level can move, once built, so Use can move it at once. */
    const moving = useRef<MovedThings | null>(null);

    /**
     * A verb, sent off to be settled. Only the inventory and the message come
     * back: the level the browser is already holding is left alone, so the
     * player carries on standing exactly where they were.
     */
    const choose = useCallback(
        (choice: VerbChoice) => {
            const thing = asking;

            setAsking(null);

            if (thing === null) {
                return;
            }

            // Looking at something with nothing to say about it is answered
            // here, without troubling the server: the description is what
            // looking at it means.
            if (
                choice.verb === 'look' &&
                !thing.verbs.some((offer) => offer.verb === 'look')
            ) {
                setNote(thing.description);

                return;
            }

            // Whatever this verb moves, moved now rather than when the server
            // has finished thinking. You walk through a door in the same frame
            // it opens, and the round trip returns an inventory and a message
            // by design, so the engine's own state goes first and the save
            // confirms it afterwards.
            //
            // The engine is told what to do rather than working it out: the
            // offer carries the two effects that move something, so a door, a
            // drawbridge and a hatch all arrive here as the same instruction
            // with different numbers in it.
            //
            // Nothing rolls this back and nothing needs to. `moved` comes back
            // with every interaction and the viewport puts every thing where it
            // says, so a refused one — a locked door, a missing key — is one
            // whose turn never arrives and swings shut on its own a moment
            // later.
            const offered = thing.verbs.find(
                (offer) =>
                    offer.verb === choice.verb &&
                    (offer.item ?? '') === (choice.item ?? ''),
            );

            for (const move of offered?.moves ?? []) {
                if (move.does === 'rotate_thing') {
                    moving.current?.turn(move.subject, Number(move.value));
                }

                if (move.does === 'set_blocking') {
                    moving.current?.block(
                        move.subject,
                        move.value === '1' || move.value === 'true',
                    );
                }
            }

            router.post(
                store(game.slug).url,
                {
                    level: level.slug,
                    thing: thing.slug,
                    verb: choice.verb,
                    item: choice.item ?? '',
                },
                {
                    only: ['inventory', 'flags', 'moved', 'message'],
                    preserveState: true,
                    preserveScroll: true,
                    onSuccess: (page) => {
                        const said = page.props.message;

                        setNote(
                            typeof said === 'string' ? said : thing.description,
                        );
                    },
                },
            );
        },
        [asking, game.slug, level.slug],
    );

    const lockChanged = useCallback((next: boolean) => {
        setLocked(next);

        if (!next) {
            setAsking(null);
        }
    }, []);

    return (
        <>
            <Head title={`${level.name} — ${game.title}`} />

            <div className="min-h-screen bg-[#0a0a0a] px-4 py-8 text-amber-50">
                <div className="mx-auto flex w-full max-w-4xl flex-col gap-6">
                    <header className="flex items-baseline justify-between gap-4">
                        <div>
                            <Link
                                href={index()}
                                className="text-xs tracking-widest text-amber-100/40 uppercase transition hover:text-amber-100"
                            >
                                {game.title}
                            </Link>
                            <h1 className="text-2xl font-medium">
                                {level.name}
                            </h1>
                        </div>
                        <p className="text-xs tracking-widest text-amber-100/40 uppercase">
                            Wireframe build
                        </p>
                    </header>

                    <LevelViewport
                        level={level}
                        flags={flags}
                        moved={moved}
                        movingRef={moving}
                        onFocus={setFocused}
                        onExamine={examine}
                        onLockChange={lockChanged}
                        onMessage={setNote}
                        reportTo={reportFault(game.slug).url}
                        paused={asking !== null}
                    >
                        {/* Over the touch controls, which are z-20. */}
                        <div className="pointer-events-none absolute inset-0 z-30">
                            <span
                                aria-hidden
                                className="absolute top-1/2 left-1/2 block h-1 w-1 -translate-x-1/2 -translate-y-1/2 rounded-full bg-amber-200/80"
                            />

                            {asking !== null && (
                                <VerbMenu
                                    thing={asking}
                                    inventory={inventory}
                                    onChoose={choose}
                                    onClose={close}
                                />
                            )}

                            {locked && asking === null && focused !== null && (
                                <p className="absolute bottom-8 left-1/2 -translate-x-1/2 rounded bg-black/70 px-3 py-1 text-sm whitespace-nowrap text-amber-100">
                                    {focused.name}
                                    <span className="ml-2 text-amber-100/50">
                                        [E] Look
                                    </span>
                                </p>
                            )}

                            {!locked && (
                                <div className="absolute inset-x-0 bottom-0 flex flex-col items-center gap-1 bg-gradient-to-t from-black/85 to-transparent px-4 pt-10 pb-4 text-center">
                                    <p className="text-sm tracking-widest text-amber-100 uppercase">
                                        Click or tap to play
                                    </p>
                                    <p className="text-xs text-amber-100/60">
                                        WASD to move · Shift to run · Space to
                                        jump · E to examine · Esc to let go
                                    </p>

                                    <p className="text-xs text-amber-100/60">
                                        1 wand · 2 pistol · 3 tablet · 4 phone ·
                                        0 for empty hands
                                    </p>

                                    <p className="text-xs text-amber-100/60">
                                        On a phone: left thumb to walk, push it
                                        right over to run, drag the right of the
                                        screen to look · Jump does what Space
                                        does
                                    </p>

                                    <p className="text-xs text-amber-100/60">
                                        F saves a snapshot of where you are
                                        standing · Snap does the same on a phone
                                    </p>

                                    {level.playerSprite === 'william' && (
                                        <p className="text-xs tracking-wide text-cyan-200/80">
                                            M to mark · R to recall
                                        </p>
                                    )}
                                </div>
                            )}
                        </div>
                    </LevelViewport>

                    <p
                        aria-live="polite"
                        className="min-h-16 rounded-lg border border-white/10 bg-white/5 p-4 text-[15px] leading-relaxed"
                    >
                        {note ?? level.description}
                    </p>

                    <InventoryTray
                        items={inventory}
                        selectedItem={null}
                        canSelect={false}
                        onSelect={() => undefined}
                    />
                </div>
            </div>
        </>
    );
}
