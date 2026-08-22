import { cn } from '@/lib/utils';

/**
 * Undo and redo, as buttons you can see.
 *
 * Both have worked since the day the history stack landed, and the people
 * drawing levels did not know. The only trace of either was a grey
 * `· ⌘Z undoes` appended to the map's status line — and only while there was
 * something to undo, so it was absent at precisely the moment somebody new
 * would go looking for it. A shortcut nobody is told about is a shortcut nobody
 * has.
 *
 * So they are always here, and greyed rather than hidden when there is nowhere
 * to step: a disabled button still says the feature exists, where an absent one
 * says nothing at all. The shortcut rides in the tooltip, which is how somebody
 * graduates from clicking to typing.
 */
export function HistoryButtons({
    canUndo,
    canRedo,
    onUndo,
    onRedo,
}: {
    canUndo: boolean;
    canRedo: boolean;
    onUndo: () => void;
    onRedo: () => void;
}) {
    const buttons = [
        { label: 'Undo', keys: '⌘Z', enabled: canUndo, run: onUndo },
        { label: 'Redo', keys: '⌘⇧Z', enabled: canRedo, run: onRedo },
    ];

    return (
        <span className="ml-4 flex items-center gap-1">
            {buttons.map(({ label, keys, enabled, run }) => (
                <button
                    key={label}
                    type="button"
                    onClick={run}
                    disabled={!enabled}
                    title={`${label} (${keys})`}
                    aria-label={`${label} (${keys})`}
                    className={cn(
                        'rounded border px-3 py-1',
                        enabled
                            ? 'border-slate-700 text-slate-300 hover:border-slate-500'
                            : 'border-slate-800 text-slate-600',
                    )}
                >
                    {label}
                </button>
            ))}
        </span>
    );
}
