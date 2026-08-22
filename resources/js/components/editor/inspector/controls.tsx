import { useState } from 'react';
import { cn } from '@/lib/utils';

/**
 * The three controls every panel of the Inspector is built out of.
 *
 * Together in one file because they are one idea — a labelled thing you can
 * change — and because the alternative is three files of thirty lines that are
 * never opened separately.
 *
 * `mixed` on NumberInput is the reason this is worth a shared component at all.
 * Several rooms picked at once may disagree about a value, and the panel has to
 * show nothing rather than show one of them: a number that reads as fact would
 * be imposed on the rest by the next unrelated edit.
 */

export function Field({
    label,
    children,
}: {
    label: string;
    children: React.ReactNode;
}) {
    return (
        <label className="block">
            <span className="mb-1 block text-[11px] tracking-wider text-slate-400 uppercase">
                {label}
            </span>
            {children}
        </label>
    );
}

export const inputClass =
    'w-full rounded border border-slate-700 bg-slate-950 px-2 py-1 text-sm text-slate-200';

/**
 * A number you can clear.
 *
 * A plain controlled number input cannot be emptied: the moment the box is
 * blank the value read off it is zero, the field is handed a zero straight
 * back, and the only way to get 12 in is to select the 0 and overtype it. So
 * what has been typed is kept as it is typed, and only handed on once it reads
 * as a number. Empty is nothing at all — the value is left as it was until
 * either something valid is typed or the field is left, whereupon it shows
 * where it really stands again.
 */
export function NumberInput({
    value,
    step,
    min,
    max,
    mixed = false,
    onChange,
}: {
    value: number;
    step: string;
    min?: number;
    max?: number;
    /** The rooms this stands for do not agree, so show nothing until told. */
    mixed?: boolean;
    onChange: (value: number) => void;
}) {
    const [typing, setTyping] = useState<string | null>(null);

    return (
        <input
            type="number"
            step={step}
            min={min}
            max={max}
            placeholder={mixed ? '—' : undefined}
            value={typing ?? (mixed ? '' : value)}
            onChange={(event) => {
                const text = event.target.value;

                setTyping(text);

                if (text.trim() !== '' && Number.isFinite(Number(text))) {
                    onChange(Number(text));
                }
            }}
            onBlur={() => setTyping(null)}
            className={inputClass}
        />
    );
}

export function Toggle({
    label,
    checked,
    onChange,
}: {
    label: string;
    checked: boolean;
    onChange: (value: boolean) => void;
}) {
    return (
        <button
            type="button"
            // A toggle button rather than a plain one, so a screen reader
            // announces whether it is on. Without this its whole state is
            // carried by a border colour, which is no state at all to anybody
            // not looking at it.
            aria-pressed={checked}
            onClick={() => onChange(!checked)}
            className={cn(
                'rounded border px-2 py-1 text-xs',
                checked
                    ? 'border-amber-400 bg-amber-400/10 text-amber-200'
                    : 'border-slate-700 text-slate-400 hover:border-slate-500',
            )}
        >
            {label}
        </button>
    );
}
