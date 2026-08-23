import {
    Field,
    inputClass,
    NumberInput,
} from '@/components/editor/inspector/controls';
import { slopeReach, wallLabels } from '@/lib/editor/walls';
import type { Sector } from '@/types';

/**
 * A floor or ceiling that is not level.
 *
 * A slope is a rise per metre measured into the room from a chosen wall, so
 * both halves are needed to mean anything: picking Flat clears the rise as
 * well, since a rise hinged on nothing is a number that does nothing.
 *
 * The heights above read differently once this is set — a room's floor height
 * becomes its height *along the hinge wall*, not everywhere — so the panel says
 * so rather than leaving it to be worked out from the geometry.
 */
export default function SlopePanel({
    sector,
    onChangeSector,
}: {
    sector: Sector;
    onChangeSector: (change: Partial<Sector>) => void;
}) {
    const walls = wallLabels(sector);

    /** What a rise per metre comes to, at the corners of this room. */
    const reachOf = (base: number, slope: number, hinge: number | null) =>
        slopeReach(sector, base, slope, hinge);

    const surface = (
        name: string,
        base: number,
        slope: number,
        hinge: number | null,
        set: (slope: number, hinge: number | null) => void,
    ) => (
        <div className="flex flex-col gap-2">
            <div className="grid grid-cols-2 gap-2">
                <Field label={`${name} hinged on`}>
                    <select
                        value={hinge ?? ''}
                        onChange={(event) =>
                            event.target.value === ''
                                ? set(0, null)
                                : set(slope, Number(event.target.value))
                        }
                        className={inputClass}
                    >
                        <option value="">Flat</option>
                        {walls.map((label, index) => (
                            <option key={label} value={index}>
                                {label}
                            </option>
                        ))}
                    </select>
                </Field>
                <Field label="Rise per metre">
                    <NumberInput
                        step="0.05"
                        min={-8}
                        max={8}
                        value={slope}
                        onChange={(next) => set(next, hinge)}
                    />
                </Field>
            </div>

            {(() => {
                const reach = reachOf(base, slope, hinge);

                if (reach === null) {
                    return null;
                }

                // Where it gets to, which is the thing the rise never said. Read
                // off the corners rather than the room's depth: `into` is measured
                // perpendicular from the hinge wall, so in a room that is not a
                // rectangle a corner can sit much further from that wall than the
                // room looks deep, and the floor there goes with it.
                return (
                    <p className="text-xs leading-relaxed text-slate-500">
                        {name} runs from{' '}
                        <span className="text-slate-200">
                            {reach.lowest.toFixed(2)} m
                        </span>{' '}
                        to{' '}
                        <span className="text-slate-200">
                            {reach.highest.toFixed(2)} m
                        </span>{' '}
                        across this room.
                    </p>
                );
            })()}
        </div>
    );

    return (
        <div className="flex flex-col gap-2 border-t border-slate-800 pt-3">
            <span className="text-[11px] tracking-wider text-slate-400 uppercase">
                Slopes
            </span>

            {surface(
                'Floor',
                sector.floorHeight,
                sector.floorSlope,
                sector.floorSlopeEdge,
                (floorSlope, floorSlopeEdge) =>
                    onChangeSector({ floorSlope, floorSlopeEdge }),
            )}

            {surface(
                'Ceiling',
                sector.ceilingHeight,
                sector.ceilingSlope,
                sector.ceilingSlopeEdge,
                (ceilingSlope, ceilingSlopeEdge) =>
                    onChangeSector({ ceilingSlope, ceilingSlopeEdge }),
            )}

            {(sector.floorSlope !== 0 || sector.ceilingSlope !== 0) && (
                <p className="text-[11px] text-slate-500">
                    Floor and ceiling above are the heights along the hinge
                    wall. Hinge two rooms on the wall they share, at the same
                    height, and they meet flush.
                </p>
            )}
        </div>
    );
}
