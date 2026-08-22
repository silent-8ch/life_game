import {
    Field,
    inputClass,
    NumberInput,
    Toggle,
} from '@/components/editor/inspector/controls';
import type { LevelThing } from '@/types';

/**
 * A thing that opens.
 *
 * Everything here is behind the one toggle, because a swing angle on a bookcase
 * is a control that does nothing — the same rule the drawing panel follows.
 *
 * The wording is careful about one thing and it is worth keeping careful.
 * "Starts open" is not "is open": where a door stands while somebody is playing
 * belongs to the engine, since you walk through a door in the same frame it
 * opens and nothing involving the server can keep up with that. This panel sets
 * what a door is like when the level loads, and nothing else.
 */
export default function DoorPanel({
    thing,
    onChangeThing,
}: {
    thing: LevelThing;
    onChangeThing: (change: Partial<LevelThing>) => void;
}) {
    return (
        <div className="flex flex-col gap-3 border-t border-slate-800 pt-3">
            <span className="text-[11px] tracking-wider text-slate-400 uppercase">
                Opening
            </span>

            <Toggle
                label="This opens"
                checked={thing.isDoor}
                onChange={(isDoor) =>
                    onChangeThing({
                        isDoor,
                        // A thing that opens is solid while it is shut, which
                        // is the only reason its collider leaving the set means
                        // anything. Turning it on makes it so, rather than
                        // leaving a door you can already walk through.
                        isSolid: isDoor ? true : thing.isSolid,
                    })
                }
            />

            {thing.isDoor && (
                <>
                    <Field label="Moves by">
                        <select
                            value={thing.swing}
                            onChange={(event) =>
                                onChangeThing({
                                    swing: event.target
                                        .value as LevelThing['swing'],
                                })
                            }
                            className={inputClass}
                        >
                            <option value="swing">Swinging on a hinge</option>
                            <option value="slide">Sliding aside</option>
                            <option value="fold">Folding in half</option>
                        </select>
                    </Field>

                    <div className="grid grid-cols-2 gap-2">
                        <Field
                            label={
                                thing.swing === 'swing'
                                    ? 'Opens to'
                                    : 'Opens by'
                            }
                        >
                            <NumberInput
                                step="5"
                                min={15}
                                max={180}
                                value={thing.openAngle}
                                onChange={(openAngle) =>
                                    onChangeThing({ openAngle })
                                }
                            />
                        </Field>
                        <Field label="Taking">
                            <NumberInput
                                step="0.05"
                                min={0.05}
                                max={10}
                                value={thing.openSeconds}
                                onChange={(openSeconds) =>
                                    onChangeThing({ openSeconds })
                                }
                            />
                        </Field>
                    </div>

                    <Toggle
                        label="Starts open"
                        checked={thing.isOpen}
                        onChange={(isOpen) => onChangeThing({ isOpen })}
                    />

                    <Field label="Remembered as">
                        <input
                            value={thing.opensFlag ?? ''}
                            placeholder="front-door-open"
                            onChange={(event) =>
                                onChangeThing({
                                    opensFlag:
                                        event.target.value === ''
                                            ? null
                                            : event.target.value,
                                })
                            }
                            className={inputClass}
                        />
                    </Field>

                    <p className="text-[11px] text-slate-500">
                        {thing.opensFlag === null
                            ? 'Shuts again every time the level loads.'
                            : 'Opened once, it is open again next time.'}
                    </p>
                </>
            )}
        </div>
    );
}
