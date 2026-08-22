import { Head, Link } from '@inertiajs/react';
import { show } from '@/actions/App/Http/Controllers/GameController';
import type { GameIndexProps } from '@/types';

export default function Index({ games }: GameIndexProps) {
    return (
        <>
            <Head title="Adventures" />

            <div className="min-h-screen bg-[#0a0a0a] px-4 py-16 text-amber-50">
                <div className="mx-auto flex w-full max-w-3xl flex-col gap-10">
                    <header className="flex flex-wrap items-end justify-between gap-4">
                        <div>
                            <h1 className="text-3xl font-medium">Adventures</h1>
                            <p className="mt-1 text-sm text-amber-100/50">
                                Pick something to play. Your progress is kept
                                for each one.
                            </p>
                        </div>

                        {/*
                          The adventures below start you at their own first
                          room. Everything anybody has drawn since lives behind
                          this, and until now the only way to reach one was to
                          know its slug and type it into the address bar.
                        */}
                        <Link
                            href="/levels"
                            className="rounded-lg border border-amber-300/40 bg-amber-300/10 px-4 py-2 text-sm text-amber-100 transition hover:border-amber-300/70 hover:bg-amber-300/20"
                        >
                            Levels people made →
                        </Link>
                    </header>

                    {games.length === 0 ? (
                        <p className="rounded-lg border border-white/10 bg-white/5 p-6 text-sm text-amber-50/50 italic">
                            Nothing has been written yet. Seed a game to get
                            started.
                        </p>
                    ) : (
                        <ul className="flex flex-col gap-3">
                            {games.map((game) => (
                                <li key={game.slug}>
                                    <Link
                                        href={show(game.slug)}
                                        prefetch
                                        className="block rounded-lg border border-white/10 bg-white/5 p-5 transition hover:border-amber-300/60 hover:bg-white/10"
                                    >
                                        <div className="flex items-baseline justify-between gap-4">
                                            <h2 className="text-lg font-medium">
                                                {game.title}
                                            </h2>
                                            {game.inProgress && (
                                                <span className="text-[11px] tracking-widest text-amber-300/70 uppercase">
                                                    In progress
                                                </span>
                                            )}
                                        </div>
                                        <p className="mt-1 text-sm text-amber-100/60">
                                            {game.tagline}
                                        </p>
                                        {game.currentLocationName && (
                                            <p className="mt-3 text-xs text-amber-100/35">
                                                Last seen in{' '}
                                                {game.currentLocationName}
                                            </p>
                                        )}
                                    </Link>
                                </li>
                            ))}
                        </ul>
                    )}
                </div>
            </div>
        </>
    );
}
