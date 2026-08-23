import { Field, inputClass } from '@/components/editor/inspector/controls';
import type { LevelThing, ThingBinding } from '@/types';

/**
 * What a thing does while its input is on, and while it is off.
 *
 * The responding half of Paul's redstone idea. There is no line to name here
 * any more: a thing answers **its own input**, and what feeds that input is the
 * lines somebody drew into it in the map view. A thing with two lines drawn in
 * answers whichever of them its logic says to.
 *
 * That is the whole of what the second slice changed, and it is why this panel
 * lost a field rather than gaining one.
 *
 * **Both sides are always authored.** *On and off each say what they do* — an
 * on-value with an implied resting position is the same thing with a worse
 * name, and it lets somebody forget to say what happens when the line goes off,
 * which is the difference between a door that shuts behind you and one that
 * stays open because nobody said otherwise.
 *
 * Swapping the two values is a NOT, which is why there is no sense to choose.
 */
export default function BindingsPanel({
    thing,
    onChangeThing,
}: {
    thing: LevelThing;
    onChangeThing: (change: Partial<LevelThing>) => void;
}) {
    const bindings = thing.bindings ?? [];

    const change = (at: number, part: Partial<ThingBinding>) =>
        onChangeThing({
            bindings: bindings.map((binding, index) =>
                index === at ? { ...binding, ...part } : binding,
            ),
        });

    return (
        <div className="flex flex-col gap-3 border-t border-slate-800 pt-3">
            <span className="text-[11px] tracking-wider text-slate-400 uppercase">
                Answers to
            </span>

            {bindings.length === 0 && (
                <p className="text-xs leading-relaxed text-slate-500">
                    Nothing yet. Add one and it moves whenever a line drawn into
                    it comes on.
                </p>
            )}

            {bindings.map((binding, at) => (
                <div
                    key={at}
                    className="flex flex-col gap-2 rounded border border-slate-800 p-2"
                >
                    <div className="grid grid-cols-2 gap-2">
                        <Field label="Does">
                            <select
                                value={binding.response}
                                onChange={(event) =>
                                    change(at, {
                                        response: event.target
                                            .value as ThingBinding['response'],
                                    })
                                }
                                className={inputClass}
                            >
                                <option value="rotate">Turns to</option>
                                <option value="blocking">Blocks the way</option>
                            </select>
                        </Field>
                    </div>

                    <div className="grid grid-cols-2 gap-2">
                        <Field label="While on">
                            <input
                                type="text"
                                value={binding.on}
                                onChange={(event) =>
                                    change(at, { on: event.target.value })
                                }
                                className={inputClass}
                            />
                        </Field>

                        <Field label="While off">
                            <input
                                type="text"
                                value={binding.off}
                                onChange={(event) =>
                                    change(at, { off: event.target.value })
                                }
                                className={inputClass}
                            />
                        </Field>
                    </div>

                    <button
                        type="button"
                        onClick={() =>
                            onChangeThing({
                                bindings: bindings.filter(
                                    (_, index) => index !== at,
                                ),
                            })
                        }
                        className="self-start text-xs text-rose-300/80 hover:text-rose-200"
                    >
                        Remove
                    </button>
                </div>
            ))}

            <button
                type="button"
                onClick={() =>
                    onChangeThing({
                        bindings: [
                            ...bindings,
                            {
                                response: 'rotate',
                                on: '90',
                                off: '0',
                            },
                        ],
                    })
                }
                className="self-start text-xs text-sky-300/80 hover:text-sky-200"
            >
                Answer another line
            </button>
        </div>
    );
}
