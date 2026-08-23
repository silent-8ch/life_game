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

            {thing.render === 'flat' && (
                <>
                    <Field label="Hinged on">
                        <select
                            value={thing.hinge ?? ''}
                            onChange={(event) =>
                                onChangeThing({
                                    hinge:
                                        event.target.value === ''
                                            ? null
                                            : (event.target
                                                  .value as LevelThing['hinge']),
                                })
                            }
                            className={inputClass}
                        >
                            <option value="">Nothing — it does not move</option>
                            <option value="left">Left edge</option>
                            <option value="right">Right edge</option>
                            <option value="top">Top edge</option>
                            <option value="bottom">Bottom edge</option>
                        </select>
                    </Field>

                    {thing.hinge !== null && (
                        <p className="text-xs leading-relaxed text-slate-500">
                            It turns about that edge, seen from the front. What
                            turns it is an interaction below: give it Use with a
                            Rotate and a Blocking effect and you have made a
                            door. Bottom makes a drawbridge, top a hatch.
                        </p>
                    )}
                </>
            )}

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

            <div className="flex flex-col gap-2 border-t border-slate-800 pt-3">
                <span className="text-[11px] tracking-wider text-slate-400 uppercase">
                    Action lines
                </span>

                <div className="grid grid-cols-2 gap-2">
                    <Field label="Turns on">
                        <select
                            value={thing.emitWhen ?? ''}
                            onChange={(event) =>
                                onChangeThing({
                                    emitWhen:
                                        event.target.value === ''
                                            ? null
                                            : (event.target
                                                  .value as LevelThing['emitWhen']),
                                })
                            }
                            className={inputClass}
                        >
                            <option value="">Not a source</option>
                            <option value="used">When used — a lever</option>
                            <option value="stood_on">
                                While stood on — a plate
                            </option>
                        </select>
                    </Field>

                    <Field label="Lines in combine">
                        <select
                            value={thing.logic}
                            onChange={(event) =>
                                onChangeThing({
                                    logic: event.target
                                        .value as LevelThing['logic'],
                                })
                            }
                            className={inputClass}
                        >
                            <option value="any">Any — a wire, or an OR</option>
                            <option value="all">All — an AND</option>
                            <option value="none">None — a NOT</option>
                        </select>
                    </Field>
                </div>

                {thing.emitWhen === 'stood_on' && (
                    <Field label="Stood on by">
                        <select
                            value={thing.triggeredBy}
                            onChange={(event) =>
                                onChangeThing({
                                    triggeredBy: event.target
                                        .value as LevelThing['triggeredBy'],
                                })
                            }
                            className={inputClass}
                        >
                            <option value="player">You</option>
                            <option value="actors">The people</option>
                            <option value="anyone">Anyone</option>
                        </select>
                    </Field>
                )}

                <div className="grid grid-cols-2 gap-2">
                    <Field label="On while flag">
                        <input
                            type="text"
                            value={thing.readsFlag ?? ''}
                            placeholder="never"
                            onChange={(event) =>
                                onChangeThing({
                                    readsFlag:
                                        event.target.value === ''
                                            ? null
                                            : event.target.value,
                                })
                            }
                            className={inputClass}
                        />
                    </Field>

                    <Field label="Sets flag while on">
                        <input
                            type="text"
                            value={thing.writesFlag ?? ''}
                            placeholder="none"
                            onChange={(event) =>
                                onChangeThing({
                                    writesFlag:
                                        event.target.value === ''
                                            ? null
                                            : event.target.value,
                                })
                            }
                            className={inputClass}
                        />
                    </Field>
                </div>

                <p className="text-xs leading-relaxed text-slate-500">
                    Nothing in a chain needs a name — draw a line from one thing
                    to the next in the map. The two flag fields are the only
                    bridge to names, for reaching a lock or the save.
                </p>
            </div>

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
