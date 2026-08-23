import {
    Field,
    inputClass,
    NumberInput,
    Toggle,
} from '@/components/editor/inspector/controls';
import TexturePicker from '@/components/editor/texture-picker';
import type { LevelThing } from '@/types';

/**
 * How a thing is drawn, and what it is drawn from.
 *
 * Conditional throughout, the same way sprite and behaviour already are: a
 * plane count means nothing to a billboard, a tiling mode means nothing to a
 * cross, and showing them anyway invites somebody to set a number that is read
 * by nothing and wonder why it did not work.
 */
export default function DrawingPanel({
    thing,
    props,
    onChangeThing,
}: {
    thing: LevelThing;
    props: string[];
    onChangeThing: (change: Partial<LevelThing>) => void;
}) {
    const animated = thing.animationFrames > 1;

    return (
        <div className="flex flex-col gap-3 border-t border-slate-800 pt-3">
            <span className="text-[11px] tracking-wider text-slate-400 uppercase">
                Drawing
            </span>

            <Field label="Shape">
                <select
                    value={thing.render}
                    onChange={(event) =>
                        onChangeThing({
                            render: event.target.value as LevelThing['render'],
                        })
                    }
                    className={inputClass}
                >
                    <option value="box">Box — six faces</option>
                    <option value="billboard">
                        Billboard — always faces you
                    </option>
                    <option value="cross">Cross — planes in a star</option>
                    <option value="flat">Flat — one quad, locked angle</option>
                </select>
            </Field>

            {thing.render === 'cross' && (
                <Field label="Planes">
                    <select
                        value={thing.planeCount}
                        onChange={(event) =>
                            onChangeThing({
                                planeCount: Number(event.target.value),
                            })
                        }
                        className={inputClass}
                    >
                        <option value={2}>2 — crossed at a right angle</option>
                        <option value={3}>3 — sixty degrees apart</option>
                    </select>
                </Field>
            )}

            {thing.render === 'box' && (
                <Toggle
                    label="Stretch the texture to fit each face"
                    checked={thing.uvMode === 'fit'}
                    onChange={(fit) =>
                        onChangeThing({ uvMode: fit ? 'fit' : 'tile' })
                    }
                />
            )}

            <Field label="Frames">
                <NumberInput
                    step="1"
                    min={1}
                    max={16}
                    value={thing.animationFrames}
                    onChange={(next) =>
                        onChangeThing({ animationFrames: Math.round(next) })
                    }
                />
            </Field>

            {animated && (
                <Field label="Frames a second">
                    <NumberInput
                        step="1"
                        min={0.1}
                        max={60}
                        value={thing.animationFps}
                        onChange={(next) =>
                            onChangeThing({ animationFps: next })
                        }
                    />
                </Field>
            )}

            <TexturePicker
                label="While a flag is set"
                value={thing.textureAlt}
                textures={props}
                folder="props"
                onChange={(textureAlt) =>
                    onChangeThing({
                        textureAlt,
                        // The pair is all or nothing — either alone is refused
                        // at save, so clearing one clears the other rather than
                        // leaving a state that cannot be saved.
                        altFlag: textureAlt === null ? null : thing.altFlag,
                    })
                }
            />

            {thing.textureAlt !== null && (
                <Field label="Flag">
                    <input
                        value={thing.altFlag ?? ''}
                        placeholder="lamp-on"
                        onChange={(event) =>
                            onChangeThing({
                                altFlag:
                                    event.target.value === ''
                                        ? null
                                        : event.target.value,
                            })
                        }
                        className={inputClass}
                    />
                </Field>
            )}
        </div>
    );
}
