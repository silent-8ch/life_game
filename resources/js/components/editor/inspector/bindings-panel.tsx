import { Field, inputClass } from '@/components/editor/inspector/controls';
import TexturePicker from '@/components/editor/texture-picker';
import type { LevelAssets, LevelThing, ThingBinding } from '@/types';

/**
 * What a thing does while it is on, and while it is off.
 *
 * The responding half of Paul's redstone idea. There is no line to name here: a
 * thing answers **its own on**, and there are two ways to have one.
 *
 * - Lines drawn into it in the map view. A thing with two lines in answers
 *   whichever of them its logic says to.
 * - Being a source itself — set to a lever or a plate under *Emits*. That is a
 *   door which is its own handle, and needs no line at all.
 *
 * The second used to be broken: a thing answered `inputOf`, which counts only
 * the lines drawn in, so a self-operating door applied its `off` value for ever
 * however often it was used. It answers its output now, which is the same
 * number for a wired thing and the thing's own state for a source.
 *
 * **Both sides are always authored.** *On and off each say what they do* — an
 * on-value with an implied resting position is the same thing with a worse
 * name, and it lets somebody forget to say what happens when the line goes off,
 * which is the difference between a door that shuts behind you and one that
 * stays open because nobody said otherwise.
 *
 * Swapping the two values is a NOT, which is why there is no sense to choose.
 */
/**
 * The pair of controls a response is worth editing with.
 *
 * Paul: *selected shows alt pic, should see a texture picker. still see
 * angles.* Every response used to be two plain text boxes, so picking one that
 * takes a picture still asked for a number and picking one that takes a yes or
 * a no let you type anything at all. What a value means is decided entirely by
 * the response beside it, so the control should be too.
 */
function Value({
    response,
    label,
    value,
    assets,
    thing,
    onChange,
}: {
    response: ThingBinding['response'];
    label: string;
    value: string;
    assets: LevelAssets;
    thing: LevelThing;
    onChange: (next: string) => void;
}) {
    if (response === 'texture') {
        return (
            <TexturePicker
                label={label}
                value={value === '' ? null : value}
                textures={
                    thing.render === 'box' ? assets.textures : assets.props
                }
                folder={thing.render === 'box' ? 'textures' : 'props'}
                onChange={(texture) => onChange(texture ?? '')}
            />
        );
    }

    if (response === 'blocking' || response === 'visible') {
        return (
            <Field label={label}>
                <select
                    value={value === '1' || value === 'true' ? '1' : '0'}
                    onChange={(event) => onChange(event.target.value)}
                    className={inputClass}
                >
                    <option value="1">Yes</option>
                    <option value="0">No</option>
                </select>
            </Field>
        );
    }

    if (response === 'move') {
        return (
            <Field label={`${label} (x, z, up)`}>
                <input
                    type="text"
                    value={value}
                    placeholder="0,0,0"
                    onChange={(event) => onChange(event.target.value)}
                    className={inputClass}
                />
            </Field>
        );
    }

    return (
        <Field label={`${label} (°)`}>
            <input
                type="number"
                step="1"
                value={value}
                onChange={(event) => onChange(event.target.value)}
                className={inputClass}
            />
        </Field>
    );
}

/** What each response wants its two values to start out as. */
const STARTS: Record<ThingBinding['response'], { on: string; off: string }> = {
    rotate: { on: '90', off: '0' },
    move: { on: '0,0,3', off: '0,0,0' },
    blocking: { on: '0', off: '1' },
    texture: { on: '', off: '' },
    visible: { on: '0', off: '1' },
};

export default function BindingsPanel({
    thing,
    assets,
    onChangeThing,
}: {
    thing: LevelThing;
    assets: LevelAssets;
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
                    Nothing yet, so this thing does nothing when it turns on.
                    Add one to say what it should do — swing, slide, block the
                    way, change picture or disappear.
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
                                onChange={(event) => {
                                    // The old pair means nothing under the new
                                    // response: 90 degrees is not a texture and
                                    // an offset is not a yes. Start it on
                                    // something that reads as that response
                                    // rather than leaving a number in a
                                    // picture's place.
                                    const response = event.target
                                        .value as ThingBinding['response'];

                                    change(at, {
                                        response,
                                        ...STARTS[response],
                                    });
                                }}
                                className={inputClass}
                            >
                                <option value="rotate">Turns to</option>
                                <option value="move">Slides to</option>
                                <option value="blocking">Blocks the way</option>
                                <option value="texture">
                                    Shows alt picture
                                </option>
                                <option value="visible">Is visible</option>
                            </select>
                        </Field>
                    </div>

                    <div className="grid grid-cols-2 gap-2">
                        <Value
                            response={binding.response}
                            label="While on"
                            value={binding.on}
                            assets={assets}
                            thing={thing}
                            onChange={(on) => change(at, { on })}
                        />

                        <Value
                            response={binding.response}
                            label="While off"
                            value={binding.off}
                            assets={assets}
                            thing={thing}
                            onChange={(off) => change(at, { off })}
                        />
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
                            { response: 'rotate', ...STARTS.rotate },
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
