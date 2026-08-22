import { useState } from 'react';
import {
    Field,
    inputClass,
    NumberInput,
} from '@/components/editor/inspector/controls';
import {
    MAX_STEPS,
    MIN_STEPS,
    tooSteepFor,
    whyNotStairs,
} from '@/lib/editor/stairs';
import type { StairPlan } from '@/lib/editor/stairs';
import { wallLabels } from '@/lib/editor/walls';
import { MAX_STEP } from '@/lib/engine/constants';
import type { Sector } from '@/types';

/**
 * Turning this room into a flight of steps.
 *
 * Behind a confirmation rather than a button, because it replaces the room: the
 * room you were working on becomes N rooms, and there is no partial version of
 * that to back out of. Undo covers it, but a two-stage button is cheaper than
 * finding out.
 *
 * The steep warning is advice, not a rule. `MAX_STEP` is read rather than
 * copied — it is about to become a runtime decision rather than a build-time
 * one, and a number copied into a dialog would go on saying what used to be
 * true. The carve itself holds no opinion at all: it cuts what was asked for.
 */
export default function StairsPanel({
    sector,
    onCarveStairs,
}: {
    sector: Sector;
    onCarveStairs: (plan: StairPlan) => void;
}) {
    const [plan, setPlan] = useState<StairPlan>({
        steps: 4,
        rise: 1,
        fromEdge: 0,
    });
    const [asking, setAsking] = useState(false);

    const walls = wallLabels(sector);
    const refusal = whyNotStairs(sector, plan);
    const steep = refusal === null && tooSteepFor(plan, MAX_STEP);

    const change = (part: Partial<StairPlan>) => {
        setPlan((was) => ({ ...was, ...part }));
        setAsking(false);
    };

    return (
        <div className="flex flex-col gap-2 border-t border-slate-800 pt-3">
            <span className="text-[11px] tracking-wider text-slate-400 uppercase">
                Stairs
            </span>

            <div className="grid grid-cols-2 gap-2">
                <Field label="Steps">
                    <NumberInput
                        step="1"
                        min={MIN_STEPS}
                        max={MAX_STEPS}
                        value={plan.steps}
                        onChange={(next) => change({ steps: Math.round(next) })}
                    />
                </Field>
                <Field label="Climbing">
                    <NumberInput
                        step="0.1"
                        value={plan.rise}
                        onChange={(rise) => change({ rise })}
                    />
                </Field>
            </div>

            <Field label="Starting from">
                <select
                    value={plan.fromEdge}
                    onChange={(event) =>
                        change({ fromEdge: Number(event.target.value) })
                    }
                    className={inputClass}
                >
                    {walls.map((label, index) => (
                        <option key={label} value={index}>
                            {label}
                        </option>
                    ))}
                </select>
            </Field>

            {refusal !== null && (
                <p className="text-[11px] text-amber-300/80">{refusal}</p>
            )}

            {steep && (
                <p className="text-[11px] text-amber-300/80">
                    {(Math.abs(plan.rise) / plan.steps).toFixed(2)} m a step is
                    taller than anybody can currently climb. It will be carved
                    as asked.
                </p>
            )}

            <button
                type="button"
                disabled={refusal !== null}
                onClick={() => {
                    if (asking) {
                        onCarveStairs(plan);
                        setAsking(false);

                        return;
                    }

                    setAsking(true);
                }}
                className="rounded border border-slate-700 px-3 py-1.5 text-sm text-slate-300 hover:border-slate-500 disabled:opacity-40"
            >
                {asking
                    ? `Replace ${sector.name} with ${plan.steps} steps?`
                    : 'Carve into steps'}
            </button>
        </div>
    );
}
