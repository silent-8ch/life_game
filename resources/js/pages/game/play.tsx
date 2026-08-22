import { Head, Link, router } from '@inertiajs/react';
import { useState } from 'react';
import { index } from '@/actions/App/Http/Controllers/GameController';
import { store } from '@/actions/App/Http/Controllers/InteractionController';
import { destroy } from '@/actions/App/Http/Controllers/SaveController';
import InventoryTray from '@/components/game/inventory-tray';
import SceneStage from '@/components/game/scene-stage';
import VerbBar from '@/components/game/verb-bar';
import type { GamePageProps, Hotspot } from '@/types';

export default function Play({
    game,
    scene,
    hotspots,
    inventory,
    verbs,
    message,
}: GamePageProps) {
    const [selectedVerb, setSelectedVerb] = useState(verbs[0].value);
    const [selectedItem, setSelectedItem] = useState<string | null>(null);
    const [processing, setProcessing] = useState(false);

    const verbAcceptsItem =
        verbs.find((verb) => verb.value === selectedVerb)?.acceptsItem ?? false;

    function selectVerb(verb: string) {
        setSelectedVerb(verb);

        if (!verbs.find((candidate) => candidate.value === verb)?.acceptsItem) {
            setSelectedItem(null);
        }
    }

    function interact(hotspot: Hotspot) {
        setProcessing(true);

        router.post(
            store.url(game.slug),
            {
                hotspot: hotspot.slug,
                verb: selectedVerb,
                item: verbAcceptsItem ? selectedItem : null,
            },
            {
                preserveScroll: true,
                onSuccess: () => setSelectedItem(null),
                onFinish: () => setProcessing(false),
            },
        );
    }

    function restart() {
        router.delete(destroy.url(game.slug), {
            onBefore: () =>
                confirm('Start over? Everything you have found will be lost.'),
            onSuccess: () => {
                setSelectedVerb(verbs[0].value);
                setSelectedItem(null);
            },
        });
    }

    return (
        <>
            <Head title={`${scene.name} — ${game.title}`} />

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
                                {scene.name}
                            </h1>
                        </div>
                        <button
                            type="button"
                            onClick={restart}
                            className="text-xs tracking-widest text-amber-100/40 uppercase transition hover:text-amber-100"
                        >
                            Restart
                        </button>
                    </header>

                    <SceneStage
                        scene={scene}
                        hotspots={hotspots}
                        disabled={processing}
                        onHotspotClick={interact}
                    />

                    <p
                        aria-live="polite"
                        className="min-h-16 rounded-lg border border-white/10 bg-white/5 p-4 text-[15px] leading-relaxed"
                    >
                        {message ?? scene.description}
                    </p>

                    <VerbBar
                        verbs={verbs}
                        selectedVerb={selectedVerb}
                        onSelect={selectVerb}
                    />

                    <InventoryTray
                        items={inventory}
                        selectedItem={selectedItem}
                        canSelect={verbAcceptsItem}
                        onSelect={setSelectedItem}
                    />

                    <p className="text-xs text-amber-100/30">
                        Pick a verb, then click something in the scene. Select
                        an item first to use it on what you click.
                    </p>
                </div>
            </div>
        </>
    );
}
