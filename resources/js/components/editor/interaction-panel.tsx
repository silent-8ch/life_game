import { cn } from '@/lib/utils';
import type {
    ConditionType,
    EffectTypeName,
    InteractionCondition,
    InteractionEffect,
    ThingInteraction,
    VerbName,
} from '@/types';

/**
 * What a thing answers to, and what happens when it does.
 *
 * The same three-part shape the point-and-click game has used all along — a
 * verb, the conditions that have to hold, and the effects it writes back — only
 * hung on something standing in a room rather than on a rectangle drawn over a
 * painting. Nothing here is engine code: interactions are data, saved with the
 * map and settled on the server.
 */

const VERB_LABELS: Record<VerbName, string> = {
    look: 'Look at',
    use: 'Use',
    take: 'Take',
    talk: 'Talk to',
};

const CONDITION_LABELS: Record<ConditionType, string> = {
    has_item: 'Carrying',
    missing_item: 'Not carrying',
    flag_is: 'Flag is',
    flag_is_not: 'Flag is not',
};

const EFFECT_LABELS: Record<EffectTypeName, string> = {
    give_item: 'Give item',
    remove_item: 'Take item away',
    set_flag: 'Set flag',
};

// The label maps are the lists as well, so there is one place to add a verb or
// a kind of condition rather than two that can disagree.
const VERB_NAMES = Object.keys(VERB_LABELS) as VerbName[];
const CONDITION_TYPES = Object.keys(CONDITION_LABELS) as ConditionType[];
const EFFECT_TYPES = Object.keys(EFFECT_LABELS) as EffectTypeName[];

/** Which kinds name an item rather than a flag. */
const ITEM_CONDITIONS: ConditionType[] = ['has_item', 'missing_item'];
const ITEM_EFFECTS: EffectTypeName[] = ['give_item', 'remove_item'];

const inputClass =
    'w-full rounded border border-slate-700 bg-slate-950 px-2 py-1 text-sm text-slate-200';

type InteractionPanelProps = {
    interactions: ThingInteraction[];
    /** The game's items, for the pickers. */
    items: { slug: string; name: string }[];
    onChange: (interactions: ThingInteraction[]) => void;
};

function replace<T>(list: T[], index: number, value: T): T[] {
    return list.map((entry, at) => (at === index ? value : entry));
}

function without<T>(list: T[], index: number): T[] {
    return list.filter((_, at) => at !== index);
}

function ItemPicker({
    value,
    items,
    allowNone,
    onChange,
}: {
    value: string | null;
    items: { slug: string; name: string }[];
    allowNone: boolean;
    onChange: (slug: string | null) => void;
}) {
    return (
        <select
            className={inputClass}
            value={value ?? ''}
            onChange={(event) => onChange(event.target.value || null)}
        >
            {allowNone && <option value="">Nothing in hand</option>}
            {!allowNone && value === null && <option value="">Pick one</option>}
            {items.map((item) => (
                <option key={item.slug} value={item.slug}>
                    {item.name}
                </option>
            ))}
        </select>
    );
}

/** The small square that takes a row away again. */
function Remove({ onClick }: { onClick: () => void }) {
    return (
        <button
            type="button"
            onClick={onClick}
            aria-label="Remove"
            className="shrink-0 rounded border border-slate-700 px-2 text-sm text-slate-500 hover:border-rose-700 hover:text-rose-300"
        >
            ×
        </button>
    );
}

function Add({ label, onClick }: { label: string; onClick: () => void }) {
    return (
        <button
            type="button"
            onClick={onClick}
            className="rounded border border-slate-700 px-2 py-1 text-xs text-slate-400 hover:border-slate-500 hover:text-slate-200"
        >
            {label}
        </button>
    );
}

export default function InteractionPanel({
    interactions,
    items,
    onChange,
}: InteractionPanelProps) {
    const noItems = items.length === 0;

    const change = (index: number, patch: Partial<ThingInteraction>): void => {
        onChange(
            replace(interactions, index, { ...interactions[index], ...patch }),
        );
    };

    return (
        <section className="flex flex-col gap-3 border-t border-slate-800 pt-4">
            <div className="flex items-center justify-between">
                <h3 className="text-xs tracking-widest text-slate-400 uppercase">
                    Interactions
                </h3>

                <Add
                    label="Add"
                    onClick={() =>
                        onChange([
                            ...interactions,
                            {
                                verb: 'look',
                                response: '',
                                priority: 0,
                                requiredItem: null,
                                conditions: [],
                                effects: [],
                            },
                        ])
                    }
                />
            </div>

            {interactions.length === 0 && (
                <p className="text-xs leading-relaxed text-slate-500">
                    Nothing yet. Looking at this shows its description and
                    nothing else happens.
                </p>
            )}

            {interactions.map((interaction, index) => (
                <article
                    key={index}
                    className="flex flex-col gap-2 rounded border border-slate-800 bg-slate-900/40 p-2"
                >
                    <div className="flex items-center gap-2">
                        <select
                            className={inputClass}
                            value={interaction.verb}
                            onChange={(event) =>
                                change(index, {
                                    verb: event.target.value as VerbName,
                                })
                            }
                        >
                            {VERB_NAMES.map((verb) => (
                                <option key={verb} value={verb}>
                                    {VERB_LABELS[verb]}
                                </option>
                            ))}
                        </select>

                        <input
                            type="number"
                            min={0}
                            max={255}
                            title="Higher wins where more than one could fire"
                            className={cn(inputClass, 'w-16')}
                            value={interaction.priority}
                            onChange={(event) =>
                                change(index, {
                                    priority: Number(event.target.value) || 0,
                                })
                            }
                        />

                        <Remove
                            onClick={() =>
                                onChange(without(interactions, index))
                            }
                        />
                    </div>

                    {!noItems && (
                        <ItemPicker
                            value={interaction.requiredItem}
                            items={items}
                            allowNone
                            onChange={(requiredItem) =>
                                change(index, { requiredItem })
                            }
                        />
                    )}

                    <textarea
                        rows={2}
                        placeholder="What the player is told"
                        className={inputClass}
                        value={interaction.response}
                        onChange={(event) =>
                            change(index, { response: event.target.value })
                        }
                    />

                    <div className="flex flex-col gap-1">
                        <div className="flex items-center justify-between">
                            <span className="text-[11px] tracking-wider text-slate-500 uppercase">
                                Only if
                            </span>
                            <Add
                                label="+"
                                onClick={() =>
                                    change(index, {
                                        conditions: [
                                            ...interaction.conditions,
                                            {
                                                type: 'has_item',
                                                subject: items[0]?.slug ?? '',
                                                value: null,
                                            },
                                        ],
                                    })
                                }
                            />
                        </div>

                        {interaction.conditions.map((condition, at) => (
                            <Row
                                key={at}
                                kinds={CONDITION_TYPES}
                                labels={CONDITION_LABELS}
                                kind={condition.type}
                                subject={condition.subject}
                                value={condition.value}
                                items={items}
                                aboutAnItem={ITEM_CONDITIONS.includes(
                                    condition.type,
                                )}
                                takesAValue={
                                    !ITEM_CONDITIONS.includes(condition.type)
                                }
                                onChange={(next: InteractionCondition) =>
                                    change(index, {
                                        conditions: replace(
                                            interaction.conditions,
                                            at,
                                            next,
                                        ),
                                    })
                                }
                                onRemove={() =>
                                    change(index, {
                                        conditions: without(
                                            interaction.conditions,
                                            at,
                                        ),
                                    })
                                }
                            />
                        ))}
                    </div>

                    <div className="flex flex-col gap-1">
                        <div className="flex items-center justify-between">
                            <span className="text-[11px] tracking-wider text-slate-500 uppercase">
                                Then
                            </span>
                            <Add
                                label="+"
                                onClick={() =>
                                    change(index, {
                                        effects: [
                                            ...interaction.effects,
                                            {
                                                type: 'give_item',
                                                subject: items[0]?.slug ?? '',
                                                value: null,
                                            },
                                        ],
                                    })
                                }
                            />
                        </div>

                        {interaction.effects.map((effect, at) => (
                            <Row
                                key={at}
                                kinds={EFFECT_TYPES}
                                labels={EFFECT_LABELS}
                                kind={effect.type}
                                subject={effect.subject}
                                value={effect.value}
                                items={items}
                                aboutAnItem={ITEM_EFFECTS.includes(effect.type)}
                                takesAValue={
                                    !ITEM_EFFECTS.includes(effect.type)
                                }
                                onChange={(next: InteractionEffect) =>
                                    change(index, {
                                        effects: replace(
                                            interaction.effects,
                                            at,
                                            next,
                                        ),
                                    })
                                }
                                onRemove={() =>
                                    change(index, {
                                        effects: without(
                                            interaction.effects,
                                            at,
                                        ),
                                    })
                                }
                            />
                        ))}
                    </div>
                </article>
            ))}
        </section>
    );
}

/**
 * One condition or one effect. Both are the same three fields — a kind, what it
 * is about, and sometimes a value — so both are drawn by this.
 */
function Row<Kind extends string>({
    kinds,
    labels,
    kind,
    subject,
    value,
    items,
    aboutAnItem,
    takesAValue,
    onChange,
    onRemove,
}: {
    kinds: Kind[];
    labels: Record<Kind, string>;
    kind: Kind;
    subject: string;
    value: string | null;
    items: { slug: string; name: string }[];
    aboutAnItem: boolean;
    takesAValue: boolean;
    onChange: (next: {
        type: Kind;
        subject: string;
        value: string | null;
    }) => void;
    onRemove: () => void;
}) {
    const set = (
        patch: Partial<{
            type: Kind;
            subject: string;
            value: string | null;
        }>,
    ): void => onChange({ type: kind, subject, value, ...patch });

    return (
        <div className="flex items-center gap-1">
            <select
                className={cn(inputClass, 'w-28 shrink-0')}
                value={kind}
                onChange={(event) => set({ type: event.target.value as Kind })}
            >
                {kinds.map((one) => (
                    <option key={one} value={one}>
                        {labels[one]}
                    </option>
                ))}
            </select>

            {aboutAnItem ? (
                <ItemPicker
                    value={subject === '' ? null : subject}
                    items={items}
                    allowNone={false}
                    onChange={(slug) => set({ subject: slug ?? '' })}
                />
            ) : (
                <input
                    className={inputClass}
                    placeholder="flag"
                    value={subject}
                    onChange={(event) => set({ subject: event.target.value })}
                />
            )}

            {takesAValue && (
                <input
                    className={cn(inputClass, 'w-24 shrink-0')}
                    placeholder="value"
                    value={value ?? ''}
                    onChange={(event) =>
                        set({ value: event.target.value || null })
                    }
                />
            )}

            <Remove onClick={onRemove} />
        </div>
    );
}
