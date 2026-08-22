import { Head, Link, router } from '@inertiajs/react';

type PickableLevel = {
    id: number;
    slug: string;
    name: string;
    game: string;
    rooms: number;
    things: number;
    owner: string | null;
    mine: boolean;
    orphan: boolean;
    editable: boolean;
    updatedAt: string | null;
};

type Props = {
    levels: PickableLevel[];
    everyone: boolean;
    me: string;
};

/**
 * Pick a level to carry on with.
 *
 * Yours by default. The switch widens it to everybody's, including the levels
 * drawn before anyone had an account, which belong to nobody — those stay
 * editable, because unclaimed is not the same as protected.
 */
export default function Levels({ levels, everyone, me }: Props) {
    const show = (all: boolean): void => {
        router.get('/levels', all ? { everyone: 1 } : {}, {
            preserveScroll: true,
        });
    };

    return (
        <>
            <Head title="Levels" />

            <div className="min-h-screen bg-[#0a0a0a] px-4 py-8 text-amber-50">
                <div className="mx-auto flex w-full max-w-4xl flex-col gap-6">
                    <header className="flex items-baseline justify-between gap-4">
                        <div>
                            <p className="text-xs tracking-widest text-amber-100/40 uppercase">
                                {me}
                            </p>
                            <h1 className="text-2xl font-medium">
                                {everyone ? "Everyone's levels" : 'Your levels'}
                            </h1>
                        </div>

                        <div className="flex gap-1 rounded-lg bg-amber-100/5 p-1">
                            <button
                                type="button"
                                onClick={() => show(false)}
                                aria-pressed={!everyone}
                                className={`rounded px-3 py-1.5 text-sm transition ${
                                    everyone
                                        ? 'text-amber-100/50 hover:text-amber-100'
                                        : 'bg-amber-100/10 text-amber-50'
                                }`}
                            >
                                Mine
                            </button>
                            <button
                                type="button"
                                onClick={() => show(true)}
                                aria-pressed={everyone}
                                className={`rounded px-3 py-1.5 text-sm transition ${
                                    everyone
                                        ? 'bg-amber-100/10 text-amber-50'
                                        : 'text-amber-100/50 hover:text-amber-100'
                                }`}
                            >
                                Everyone
                            </button>
                        </div>
                    </header>

                    {levels.length === 0 && (
                        <p className="rounded-lg border border-amber-100/10 px-4 py-8 text-center text-sm text-amber-100/50">
                            {everyone
                                ? 'There are no levels yet.'
                                : 'You have not drawn a level yet. Switch to Everyone to see the rest.'}
                        </p>
                    )}

                    <ul className="flex flex-col gap-2">
                        {levels.map((level) => (
                            <li
                                key={level.id}
                                className="flex items-center justify-between gap-4 rounded-lg border border-amber-100/10 px-4 py-3"
                            >
                                <div className="min-w-0">
                                    <p className="truncate font-medium">
                                        {level.name}
                                    </p>
                                    <p className="text-xs text-amber-100/40">
                                        {level.rooms} rooms · {level.things}{' '}
                                        things ·{' '}
                                        {level.orphan
                                            ? 'nobody'
                                            : level.mine
                                              ? 'you'
                                              : level.owner}
                                    </p>
                                </div>

                                <div className="flex shrink-0 gap-2 text-sm">
                                    <Link
                                        href={`/games/${level.game}?level=${level.slug}`}
                                        className="rounded bg-amber-100/10 px-3 py-1.5 transition hover:bg-amber-100/20"
                                    >
                                        Play
                                    </Link>
                                    {level.editable ? (
                                        <Link
                                            href={`/editor/${level.id}`}
                                            className="rounded bg-amber-100/10 px-3 py-1.5 transition hover:bg-amber-100/20"
                                        >
                                            Edit
                                        </Link>
                                    ) : (
                                        <span
                                            className="rounded px-3 py-1.5 text-amber-100/30"
                                            title={`${level.owner} drew this one`}
                                        >
                                            Edit
                                        </span>
                                    )}
                                </div>
                            </li>
                        ))}
                    </ul>
                </div>
            </div>
        </>
    );
}
