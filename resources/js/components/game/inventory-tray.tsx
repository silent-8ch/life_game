import { cn } from '@/lib/utils';
import type { Item } from '@/types';

type InventoryTrayProps = {
    items: Item[];
    selectedItem: string | null;
    canSelect: boolean;
    onSelect: (slug: string | null) => void;
};

export default function InventoryTray({
    items,
    selectedItem,
    canSelect,
    onSelect,
}: InventoryTrayProps) {
    return (
        <div>
            <h2 className="mb-2 text-xs tracking-widest text-amber-100/50 uppercase">
                Carrying
            </h2>

            {items.length === 0 ? (
                <p className="text-sm text-amber-50/40 italic">
                    Your pockets are empty.
                </p>
            ) : (
                <ul className="flex flex-wrap gap-2">
                    {items.map((item) => (
                        <li key={item.slug}>
                            <button
                                type="button"
                                disabled={!canSelect}
                                aria-pressed={item.slug === selectedItem}
                                title={item.description}
                                onClick={() =>
                                    onSelect(
                                        item.slug === selectedItem
                                            ? null
                                            : item.slug,
                                    )
                                }
                                className={cn(
                                    'rounded-md border px-3 py-1.5 text-sm transition',
                                    item.slug === selectedItem
                                        ? 'border-amber-300 bg-amber-300 text-black'
                                        : 'border-white/15 bg-white/5 text-amber-50 hover:border-white/40',
                                    !canSelect &&
                                        'cursor-not-allowed opacity-40 hover:border-white/15',
                                )}
                            >
                                {item.name}
                            </button>
                        </li>
                    ))}
                </ul>
            )}
        </div>
    );
}
