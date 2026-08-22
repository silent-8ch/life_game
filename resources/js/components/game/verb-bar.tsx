import { cn } from '@/lib/utils';
import type { Verb } from '@/types';

type VerbBarProps = {
    verbs: Verb[];
    selectedVerb: string;
    onSelect: (verb: string) => void;
};

export default function VerbBar({
    verbs,
    selectedVerb,
    onSelect,
}: VerbBarProps) {
    return (
        <div className="flex flex-wrap gap-2" role="group" aria-label="Verbs">
            {verbs.map((verb) => (
                <button
                    key={verb.value}
                    type="button"
                    aria-pressed={verb.value === selectedVerb}
                    onClick={() => onSelect(verb.value)}
                    className={cn(
                        'rounded-md border px-3 py-1.5 text-sm transition',
                        verb.value === selectedVerb
                            ? 'border-amber-300 bg-amber-300 text-black'
                            : 'border-white/15 bg-white/5 text-amber-50 hover:border-white/40',
                    )}
                >
                    {verb.label}
                </button>
            ))}
        </div>
    );
}
