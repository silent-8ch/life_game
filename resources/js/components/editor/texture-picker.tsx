import { useId, useState } from 'react';
import { cn } from '@/lib/utils';

/**
 * Picks one of the tiling textures out of the folder. Textures are a visual
 * thing, so the list is thumbnails rather than names.
 */

type TexturePickerProps = {
    label: string;
    value: string | null;
    textures: string[];
    onChange: (texture: string | null) => void;
    /**
     * Which folder under `public/sprites` the thumbnails come from.
     *
     * Props live apart from the tiling textures — they carry alpha, have real
     * aspect ratios and never repeat — so the same picker serves both lists by
     * being told where to look.
     */
    folder?: string;
};

export default function TexturePicker({
    label,
    value,
    textures,
    onChange,
    folder = 'textures',
}: TexturePickerProps) {
    const [open, setOpen] = useState(false);
    const [search, setSearch] = useState('');

    // The label is a span rather than a <label> because what it names is a
    // button that opens a grid, not a form field — so it is tied on by id
    // instead. Without this a screen reader reaches an unlabelled button and
    // says only what is written on its face, which for an unset picker is the
    // word "None".
    const labelId = useId();

    const shown = textures.filter((texture) =>
        texture.replaceAll('-', ' ').includes(search.toLowerCase()),
    );

    return (
        <div className="relative">
            <span
                id={labelId}
                className="mb-1 block text-[11px] tracking-wider text-slate-400 uppercase"
            >
                {label}
            </span>

            <button
                type="button"
                aria-labelledby={labelId}
                aria-expanded={open}
                onClick={() => setOpen(!open)}
                className="flex w-full items-center gap-2 rounded border border-slate-700 bg-slate-900 p-1.5 text-left text-sm text-slate-200 hover:border-slate-500"
            >
                {value === null ? (
                    <span className="grid h-8 w-8 shrink-0 place-items-center rounded bg-slate-800 text-[10px] text-slate-500">
                        none
                    </span>
                ) : (
                    <img
                        src={`/sprites/${folder}/${value}.png`}
                        alt=""
                        className="h-8 w-8 shrink-0 rounded object-cover"
                    />
                )}
                <span className="truncate">
                    {value === null ? 'Wireframe' : value.replaceAll('-', ' ')}
                </span>
            </button>

            {open && (
                <div className="absolute right-0 left-0 z-20 mt-1 rounded border border-slate-700 bg-slate-900 p-2 shadow-xl">
                    <input
                        value={search}
                        onChange={(event) => setSearch(event.target.value)}
                        placeholder="Search"
                        className="mb-2 w-full rounded border border-slate-700 bg-slate-950 px-2 py-1 text-sm text-slate-200 placeholder:text-slate-600"
                    />

                    <div className="grid max-h-64 grid-cols-4 gap-1 overflow-y-auto">
                        <button
                            type="button"
                            onClick={() => {
                                onChange(null);
                                setOpen(false);
                            }}
                            className={cn(
                                'grid h-14 place-items-center rounded border text-[10px] text-slate-400',
                                value === null
                                    ? 'border-amber-400'
                                    : 'border-slate-700 hover:border-slate-500',
                            )}
                        >
                            none
                        </button>

                        {shown.map((texture) => (
                            <button
                                key={texture}
                                type="button"
                                title={texture.replaceAll('-', ' ')}
                                onClick={() => {
                                    onChange(texture);
                                    setOpen(false);
                                }}
                                className={cn(
                                    'h-14 overflow-hidden rounded border',
                                    texture === value
                                        ? 'border-amber-400'
                                        : 'border-slate-700 hover:border-slate-500',
                                )}
                            >
                                <img
                                    src={`/sprites/${folder}/${texture}.png`}
                                    alt={texture}
                                    className="h-full w-full object-cover"
                                />
                            </button>
                        ))}
                    </div>
                </div>
            )}
        </div>
    );
}
