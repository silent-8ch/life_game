import {
    Field,
    inputClass,
    NumberInput,
    Toggle,
} from '@/components/editor/inspector/controls';
import { cn } from '@/lib/utils';
import type { LevelThing, Stats } from '@/types';

const ATTRIBUTES: (keyof Stats)[] = [
    'strength',
    'perception',
    'endurance',
    'charisma',
    'intelligence',
    'agility',
    'luck',
];

/** What a person is worth if nobody has said, on either side of the wire. */
const NEUTRAL: Stats = {
    strength: 5,
    perception: 5,
    endurance: 5,
    charisma: 5,
    intelligence: 5,
    agility: 5,
    luck: 5,
};

/**
 * Everything about whatever is picked: the level itself when nothing is, the
 * room when one is, and the wall as well when one of those is picked too.
 */

/**
 * What a person is made of, and whether these are their own numbers or their
 * sprite's. All seven or none: taking them over seeds the boxes from what was
 * being inherited, so the author starts from this person's own numbers rather
 * than from nothing, and handing them back leaves nothing stored at all.
 *
 * Nothing reads these while playing yet. They are here to be written down.
 */
export default function StatsPanel({
    thing,
    onChangeThing,
}: {
    thing: LevelThing;
    onChangeThing: (change: Partial<LevelThing>) => void;
}) {
    const inherited = thing.inheritedStats ?? NEUTRAL;
    const own = thing.stats;

    return (
        <section className="flex flex-col gap-3 border-t border-slate-800 pt-4">
            <h3 className="text-xs tracking-widest text-slate-400 uppercase">
                Stats
            </h3>

            <Toggle
                label="Override stats"
                checked={own !== null}
                onChange={(override) =>
                    onChangeThing({ stats: override ? { ...inherited } : null })
                }
            />

            <div className="grid grid-cols-2 gap-2">
                {ATTRIBUTES.map((attribute) => (
                    <Field key={attribute} label={attribute}>
                        {own === null ? (
                            <input
                                type="number"
                                value={inherited[attribute]}
                                disabled
                                readOnly
                                className={cn(inputClass, 'text-slate-500')}
                            />
                        ) : (
                            <NumberInput
                                step="1"
                                min={1}
                                max={10}
                                value={own[attribute]}
                                onChange={(next) =>
                                    onChangeThing({
                                        stats: { ...own, [attribute]: next },
                                    })
                                }
                            />
                        )}
                    </Field>
                ))}
            </div>

            <p className="text-xs leading-relaxed text-slate-500">
                {own === null
                    ? `Inherited from ${thing.sprite ?? 'nobody in particular'}.`
                    : 'This person’s own, kept with the level.'}
            </p>
        </section>
    );
}
