import { useState } from 'react';
import InteractionPanel from '@/components/editor/interaction-panel';
import TexturePicker from '@/components/editor/texture-picker';
import { twinEdge } from '@/lib/editor/map';
import { cn } from '@/lib/utils';
import type {
    LevelAssets,
    Level,
    LevelThing,
    Sector,
    SectorPoint,
    Stats,
} from '@/types';

/**
 * The seven, in canonical SPECIAL order. The same order as
 * `PersonStats::ATTRIBUTES`, which is where the numbers themselves live.
 */
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

type InspectorProps = {
    level: Level;
    assets: LevelAssets;
    selection: { sector: number; edge: number | null } | null;
    /** Every room picked. More than one and they are worked on together. */
    rooms: number[];
    /** Which thing is picked, if it is a thing rather than a room. */
    thing: number | null;
    onChangeLevel: (change: Partial<Level>) => void;
    onChangeSector: (change: Partial<Sector>) => void;
    /** Applies a change to every picked room at once. */
    onChangeRooms: (change: Partial<Sector>) => void;
    /** Applies a texture to every wall of every picked room. */
    onChangeRoomWalls: (texture: string | null) => void;
    onDeleteRooms: () => void;
    onChangeEdge: (change: Partial<SectorPoint>) => void;
    onChangeThing: (change: Partial<LevelThing>) => void;
    onDeleteThing: () => void;
    onDeleteSector: () => void;
};

function Field({
    label,
    children,
}: {
    label: string;
    children: React.ReactNode;
}) {
    return (
        <label className="block">
            <span className="mb-1 block text-[11px] tracking-wider text-slate-400 uppercase">
                {label}
            </span>
            {children}
        </label>
    );
}

const inputClass =
    'w-full rounded border border-slate-700 bg-slate-950 px-2 py-1 text-sm text-slate-200';

/**
 * A number you can clear.
 *
 * A plain controlled number input cannot be emptied: the moment the box is
 * blank the value read off it is zero, the field is handed a zero straight
 * back, and the only way to get 12 in is to select the 0 and overtype it. So
 * what has been typed is kept as it is typed, and only handed on once it reads
 * as a number. Empty is nothing at all — the value is left as it was until
 * either something valid is typed or the field is left, whereupon it shows
 * where it really stands again.
 */
function NumberInput({
    value,
    step,
    min,
    max,
    mixed = false,
    onChange,
}: {
    value: number;
    step: string;
    min?: number;
    max?: number;
    /** The rooms this stands for do not agree, so show nothing until told. */
    mixed?: boolean;
    onChange: (value: number) => void;
}) {
    const [typing, setTyping] = useState<string | null>(null);

    return (
        <input
            type="number"
            step={step}
            min={min}
            max={max}
            placeholder={mixed ? '—' : undefined}
            value={typing ?? (mixed ? '' : value)}
            onChange={(event) => {
                const text = event.target.value;

                setTyping(text);

                if (text.trim() !== '' && Number.isFinite(Number(text))) {
                    onChange(Number(text));
                }
            }}
            onBlur={() => setTyping(null)}
            className={inputClass}
        />
    );
}

function Toggle({
    label,
    checked,
    onChange,
}: {
    label: string;
    checked: boolean;
    onChange: (value: boolean) => void;
}) {
    return (
        <button
            type="button"
            onClick={() => onChange(!checked)}
            className={cn(
                'rounded border px-2 py-1 text-xs',
                checked
                    ? 'border-amber-400 bg-amber-400/10 text-amber-200'
                    : 'border-slate-700 text-slate-400 hover:border-slate-500',
            )}
        >
            {label}
        </button>
    );
}

/**
 * What a person is made of, and whether these are their own numbers or their
 * sprite's. All seven or none: taking them over seeds the boxes from what was
 * being inherited, so the author starts from this person's own numbers rather
 * than from nothing, and handing them back leaves nothing stored at all.
 *
 * Nothing reads these while playing yet. They are here to be written down.
 */
function StatsPanel({
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

/**
 * How a thing is drawn, and what it is drawn from.
 *
 * Conditional throughout, the same way sprite and behaviour already are: a
 * plane count means nothing to a billboard, a tiling mode means nothing to a
 * cross, and showing them anyway invites somebody to set a number that is read
 * by nothing and wonder why it did not work.
 */
function DrawingPanel({
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

export default function Inspector({
    level,
    assets,
    selection,
    rooms,
    thing,
    onChangeLevel,
    onChangeSector,
    onChangeRooms,
    onChangeRoomWalls,
    onDeleteRooms,
    onChangeEdge,
    onChangeThing,
    onDeleteThing,
    onDeleteSector,
}: InspectorProps) {
    const held = thing === null ? null : (level.things[thing] ?? null);
    const sector =
        selection === null ? null : (level.sectors[selection.sector] ?? null);
    const edge =
        sector === null || selection?.edge === null || selection === null
            ? null
            : (sector.points[selection.edge] ?? null);

    // The same wall as the room on the other side of it names it, if shared.
    const twin =
        sector === null || selection === null || selection.edge === null
            ? null
            : twinEdge(level, selection.sector, selection.edge);
    const across = twin === null ? null : level.sectors[twin.sector];
    // A portal is a pair: the other wall in the level naming the same link.
    const partner =
        edge === null || edge.portalLink === null || edge.portalLink === ''
            ? null
            : (level.sectors.find(
                  (other, index) =>
                      other.points.some(
                          (point, at) =>
                              point.portalLink === edge.portalLink &&
                              !(
                                  index === selection?.sector &&
                                  at === selection?.edge
                              ),
                      ) &&
                      other.points.filter(
                          (point) => point.portalLink === edge.portalLink,
                      ).length > 0,
              ) ?? null);

    const portalEnds =
        edge === null || edge.portalLink === null
            ? 0
            : level.sectors.reduce(
                  (total, other) =>
                      total +
                      other.points.filter(
                          (point) => point.portalLink === edge.portalLink,
                      ).length,
                  0,
              );

    const openDoorway =
        edge !== null &&
        twin !== null &&
        across !== null &&
        !edge.blocks &&
        !across.points[twin.edge].blocks;

    const themes = Object.keys(assets.backdrops);
    const layers =
        level.sky?.theme === null
            ? []
            : (assets.backdrops[level.sky?.theme ?? ''] ?? []);

    // More than one room picked: work on what they have in common.
    if (rooms.length > 1 && thing === null) {
        const picked = rooms
            .map((index) => level.sectors[index])
            .filter((sector) => sector !== undefined);

        /** The value they all share, or undefined where they differ. */
        const shared = <T,>(read: (sector: Sector) => T): T | undefined => {
            const first = read(picked[0]);

            return picked.every((sector) => read(sector) === first)
                ? first
                : undefined;
        };

        const floor = shared((sector) => sector.floorHeight);
        const ceiling = shared((sector) => sector.ceilingHeight);
        const sky = shared((sector) => sector.isSky);
        const water = shared((sector) => sector.isWater);

        return (
            <div className="flex h-full flex-col gap-5 overflow-y-auto p-4">
                <section className="flex flex-col gap-3">
                    <h2 className="text-xs tracking-widest text-slate-400 uppercase">
                        {picked.length} rooms
                    </h2>

                    <p className="text-xs leading-relaxed text-slate-500">
                        {picked.map((sector) => sector.name).join(', ')}
                    </p>

                    <div className="grid grid-cols-2 gap-2">
                        <Field label="Floor">
                            <NumberInput
                                step="0.1"
                                value={floor ?? 0}
                                mixed={floor === undefined}
                                onChange={(next) =>
                                    onChangeRooms({ floorHeight: next })
                                }
                            />
                        </Field>
                        <Field label="Ceiling">
                            <NumberInput
                                step="0.1"
                                value={ceiling ?? 0}
                                mixed={ceiling === undefined}
                                onChange={(next) =>
                                    onChangeRooms({ ceilingHeight: next })
                                }
                            />
                        </Field>
                    </div>

                    <div className="flex gap-2">
                        <Toggle
                            label={
                                sky === undefined
                                    ? 'Open to sky ·'
                                    : 'Open to sky'
                            }
                            checked={sky === true}
                            onChange={(isSky) => onChangeRooms({ isSky })}
                        />
                        <Toggle
                            label={water === undefined ? 'Water ·' : 'Water'}
                            checked={water === true}
                            onChange={(isWater) => onChangeRooms({ isWater })}
                        />
                    </div>

                    <p className="text-xs leading-relaxed text-slate-500">
                        A field marked · is not the same in all of them. Setting
                        it sets the lot.
                    </p>
                </section>

                <section className="flex flex-col gap-3 border-t border-slate-800 pt-4">
                    <TexturePicker
                        label="Floor texture"
                        value={shared((sector) => sector.floorTexture) ?? null}
                        textures={assets.textures}
                        onChange={(floorTexture) =>
                            onChangeRooms({ floorTexture })
                        }
                    />

                    <TexturePicker
                        label="Ceiling texture"
                        value={
                            shared((sector) => sector.ceilingTexture) ?? null
                        }
                        textures={assets.textures}
                        onChange={(ceilingTexture) =>
                            onChangeRooms({ ceilingTexture })
                        }
                    />

                    <TexturePicker
                        label="Wall texture"
                        value={shared((sector) => sector.wallTexture) ?? null}
                        textures={assets.textures}
                        onChange={(wallTexture) =>
                            onChangeRooms({ wallTexture })
                        }
                    />
                </section>

                <section className="flex flex-col gap-3 border-t border-slate-800 pt-4">
                    <TexturePicker
                        label="Every wall in them"
                        value={null}
                        textures={assets.textures}
                        onChange={onChangeRoomWalls}
                    />

                    <p className="text-xs leading-relaxed text-slate-500">
                        Paints each wall of each of them one at a time, over
                        whatever any of them had of its own.
                    </p>
                </section>

                <button
                    type="button"
                    onClick={onDeleteRooms}
                    className="rounded border border-rose-900 px-3 py-1.5 text-sm text-rose-300 hover:border-rose-600"
                >
                    Delete {picked.length} rooms
                </button>
            </div>
        );
    }

    if (held !== null) {
        const isPerson = held.kind === 'actor';

        return (
            <div className="flex h-full flex-col gap-5 overflow-y-auto p-4">
                <section className="flex flex-col gap-3">
                    <h2 className="text-xs tracking-widest text-slate-400 uppercase">
                        {isPerson ? 'Person' : 'Thing'}
                    </h2>

                    <Field label="Name">
                        <input
                            value={held.name}
                            onChange={(event) =>
                                onChangeThing({ name: event.target.value })
                            }
                            className={inputClass}
                        />
                    </Field>

                    <Field label="Description">
                        <textarea
                            value={held.description}
                            rows={2}
                            onChange={(event) =>
                                onChangeThing({
                                    description: event.target.value,
                                })
                            }
                            className={inputClass}
                        />
                    </Field>

                    <div className="grid grid-cols-2 gap-2">
                        <Field label="X">
                            <NumberInput
                                step="0.1"
                                value={held.x}
                                onChange={(next) =>
                                    onChangeThing({
                                        x: next,
                                    })
                                }
                            />
                        </Field>
                        <Field label="Z">
                            <NumberInput
                                step="0.1"
                                value={held.z}
                                onChange={(next) =>
                                    onChangeThing({
                                        z: next,
                                    })
                                }
                            />
                        </Field>
                    </div>
                </section>

                {isPerson ? (
                    <section className="flex flex-col gap-3 border-t border-slate-800 pt-4">
                        <Field label="Drawn from">
                            <select
                                value={held.sprite ?? ''}
                                onChange={(event) =>
                                    onChangeThing({
                                        sprite: event.target.value,
                                    })
                                }
                                className={inputClass}
                            >
                                {assets.sprites.map((name) => (
                                    <option key={name} value={name}>
                                        {name}
                                    </option>
                                ))}
                            </select>
                        </Field>

                        <Field label="Behaviour">
                            <select
                                value={held.behaviour ?? 'still'}
                                onChange={(event) =>
                                    onChangeThing({
                                        behaviour: event.target.value,
                                    })
                                }
                                className={inputClass}
                            >
                                <option value="wander">Wanders about</option>
                                <option value="still">Stays put</option>
                            </select>
                        </Field>

                        <div className="grid grid-cols-2 gap-2">
                            <Field label="Height">
                                <NumberInput
                                    step="0.01"
                                    value={held.height}
                                    onChange={(next) =>
                                        onChangeThing({
                                            height: next,
                                        })
                                    }
                                />
                            </Field>
                            <Field label="Speed">
                                <NumberInput
                                    step="0.1"
                                    value={held.speed}
                                    onChange={(next) =>
                                        onChangeThing({
                                            speed: next,
                                        })
                                    }
                                />
                            </Field>
                        </div>
                    </section>
                ) : (
                    <section className="flex flex-col gap-3 border-t border-slate-800 pt-4">
                        <div className="grid grid-cols-3 gap-2">
                            <Field label="Width">
                                <NumberInput
                                    step="0.1"
                                    value={held.width}
                                    onChange={(next) =>
                                        onChangeThing({
                                            width: next,
                                        })
                                    }
                                />
                            </Field>
                            <Field label="Depth">
                                <NumberInput
                                    step="0.1"
                                    value={held.depth}
                                    onChange={(next) =>
                                        onChangeThing({
                                            depth: next,
                                        })
                                    }
                                />
                            </Field>
                            <Field label="Height">
                                <NumberInput
                                    step="0.1"
                                    value={held.height}
                                    onChange={(next) =>
                                        onChangeThing({
                                            height: next,
                                        })
                                    }
                                />
                            </Field>
                        </div>

                        <div className="grid grid-cols-2 gap-2">
                            <Field label="Off the floor">
                                <NumberInput
                                    step="0.1"
                                    value={held.elevation}
                                    onChange={(next) =>
                                        onChangeThing({
                                            elevation: Number(next),
                                        })
                                    }
                                />
                            </Field>
                            <Field label="Facing">
                                <NumberInput
                                    step="5"
                                    value={held.angle}
                                    onChange={(next) =>
                                        onChangeThing({
                                            angle: next,
                                        })
                                    }
                                />
                            </Field>
                        </div>

                        <Toggle
                            label="Solid"
                            checked={held.isSolid}
                            onChange={(isSolid) => onChangeThing({ isSolid })}
                        />

                        <TexturePicker
                            label="Texture"
                            value={held.texture}
                            textures={
                                held.render === 'box'
                                    ? assets.textures
                                    : assets.props
                            }
                            folder={
                                held.render === 'box' ? 'textures' : 'props'
                            }
                            onChange={(texture) => onChangeThing({ texture })}
                        />

                        <DrawingPanel
                            thing={held}
                            props={assets.props}
                            onChangeThing={onChangeThing}
                        />
                    </section>
                )}

                {isPerson ? (
                    <StatsPanel thing={held} onChangeThing={onChangeThing} />
                ) : null}

                <InteractionPanel
                    interactions={held.interactions ?? []}
                    items={assets.items}
                    onChange={(interactions) => onChangeThing({ interactions })}
                />

                <button
                    type="button"
                    onClick={onDeleteThing}
                    className="rounded border border-rose-900 px-3 py-1.5 text-sm text-rose-300 hover:border-rose-600"
                >
                    Delete
                </button>
            </div>
        );
    }

    return (
        <div className="flex h-full flex-col gap-5 overflow-y-auto p-4">
            {sector === null ? (
                <>
                    <section className="flex flex-col gap-3">
                        <h2 className="text-xs tracking-widest text-slate-400 uppercase">
                            Level
                        </h2>

                        <Field label="Name">
                            <input
                                value={level.name}
                                onChange={(event) =>
                                    onChangeLevel({ name: event.target.value })
                                }
                                className={inputClass}
                            />
                        </Field>

                        <Field label="Description">
                            <textarea
                                value={level.description}
                                rows={3}
                                onChange={(event) =>
                                    onChangeLevel({
                                        description: event.target.value,
                                    })
                                }
                                className={inputClass}
                            />
                        </Field>

                        <div className="grid grid-cols-3 gap-2">
                            <Field label="Spawn X">
                                <NumberInput
                                    step="0.1"
                                    value={level.spawn.x}
                                    onChange={(next) =>
                                        onChangeLevel({
                                            spawn: {
                                                ...level.spawn,
                                                x: next,
                                            },
                                        })
                                    }
                                />
                            </Field>
                            <Field label="Spawn Z">
                                <NumberInput
                                    step="0.1"
                                    value={level.spawn.z}
                                    onChange={(next) =>
                                        onChangeLevel({
                                            spawn: {
                                                ...level.spawn,
                                                z: next,
                                            },
                                        })
                                    }
                                />
                            </Field>
                            <Field label="Facing">
                                <NumberInput
                                    step="5"
                                    value={level.spawn.angle}
                                    onChange={(next) =>
                                        onChangeLevel({
                                            spawn: {
                                                ...level.spawn,
                                                angle: Number(next),
                                            },
                                        })
                                    }
                                />
                            </Field>
                        </div>

                        <Field label="You play as">
                            <select
                                value={level.playerSprite}
                                onChange={(event) =>
                                    onChangeLevel({
                                        playerSprite: event.target.value,
                                    })
                                }
                                className={inputClass}
                            >
                                {assets.sprites.map((name) => (
                                    <option key={name} value={name}>
                                        {name}
                                    </option>
                                ))}
                            </select>
                        </Field>

                        <p className="text-xs leading-relaxed text-slate-500">
                            Whoever you are is only ever seen in a mirror or
                            through a portal. Nothing stops you putting them in
                            the room as well, and meeting yourself.
                        </p>

                        <Field label="Default ceiling height">
                            <NumberInput
                                step="0.1"
                                value={level.ceilingHeight}
                                onChange={(next) =>
                                    onChangeLevel({
                                        ceilingHeight: Number(next),
                                    })
                                }
                            />
                        </Field>
                    </section>

                    <section className="flex flex-col gap-3 border-t border-slate-800 pt-4">
                        <h2 className="text-xs tracking-widest text-slate-400 uppercase">
                            Sky
                        </h2>

                        <Field label="Sky">
                            <select
                                value={level.sky?.image ?? ''}
                                onChange={(event) =>
                                    onChangeLevel({
                                        sky:
                                            event.target.value === ''
                                                ? null
                                                : {
                                                      image: event.target.value,
                                                      variant:
                                                          level.sky?.variant ??
                                                          0,
                                                      theme:
                                                          level.sky?.theme ??
                                                          null,
                                                      layers:
                                                          level.sky?.layers ??
                                                          [],
                                                  },
                                    })
                                }
                                className={inputClass}
                            >
                                <option value="">Indoors, no sky</option>
                                {assets.skies.map((sky) => (
                                    <option key={sky} value={sky}>
                                        {sky.replace('sky-', '')}
                                    </option>
                                ))}
                            </select>
                        </Field>

                        {level.sky !== null && (
                            <>
                                <Field label="Variant">
                                    <select
                                        value={level.sky.variant}
                                        onChange={(event) =>
                                            onChangeLevel({
                                                sky: {
                                                    ...level.sky!,
                                                    variant: Number(
                                                        event.target.value,
                                                    ),
                                                },
                                            })
                                        }
                                        className={inputClass}
                                    >
                                        {[0, 1, 2, 3].map((variant) => (
                                            <option
                                                key={variant}
                                                value={variant}
                                            >
                                                {variant + 1}
                                            </option>
                                        ))}
                                    </select>
                                </Field>

                                <Field label="Horizon">
                                    <select
                                        value={level.sky.theme ?? ''}
                                        onChange={(event) =>
                                            onChangeLevel({
                                                sky: {
                                                    ...level.sky!,
                                                    theme:
                                                        event.target.value ===
                                                        ''
                                                            ? null
                                                            : event.target
                                                                  .value,
                                                    layers:
                                                        assets.backdrops[
                                                            event.target.value
                                                        ] ?? [],
                                                },
                                            })
                                        }
                                        className={inputClass}
                                    >
                                        <option value="">Bare sky</option>
                                        {themes.map((theme) => (
                                            <option key={theme} value={theme}>
                                                {theme}
                                            </option>
                                        ))}
                                    </select>
                                </Field>

                                {layers.length > 0 && (
                                    <div className="flex gap-2">
                                        {layers.map((layer) => (
                                            <Toggle
                                                key={layer}
                                                label={`Layer ${layer}`}
                                                checked={
                                                    level.sky?.layers.includes(
                                                        layer,
                                                    ) ?? false
                                                }
                                                onChange={(on) =>
                                                    onChangeLevel({
                                                        sky: {
                                                            ...level.sky!,
                                                            layers: on
                                                                ? [
                                                                      ...(level
                                                                          .sky
                                                                          ?.layers ??
                                                                          []),
                                                                      layer,
                                                                  ].sort()
                                                                : (
                                                                      level.sky
                                                                          ?.layers ??
                                                                      []
                                                                  ).filter(
                                                                      (at) =>
                                                                          at !==
                                                                          layer,
                                                                  ),
                                                        },
                                                    })
                                                }
                                            />
                                        ))}
                                    </div>
                                )}
                            </>
                        )}
                    </section>

                    <p className="text-xs leading-relaxed text-slate-500">
                        Pick a room on the plan to set its heights and textures,
                        or draw a new one. Rooms that share two corners have a
                        way through between them unless that wall is marked
                        solid.
                    </p>
                </>
            ) : (
                <>
                    <section className="flex flex-col gap-3">
                        <div className="flex items-center justify-between">
                            <h2 className="text-xs tracking-widest text-slate-400 uppercase">
                                Room
                            </h2>
                            <button
                                type="button"
                                onClick={onDeleteSector}
                                className="text-xs text-rose-400 hover:text-rose-300"
                            >
                                Delete
                            </button>
                        </div>

                        <Field label="Name">
                            <input
                                value={sector.name}
                                onChange={(event) =>
                                    onChangeSector({ name: event.target.value })
                                }
                                className={inputClass}
                            />
                        </Field>

                        <Field label="Slug">
                            <input
                                value={sector.slug}
                                onChange={(event) =>
                                    onChangeSector({ slug: event.target.value })
                                }
                                className={inputClass}
                            />
                        </Field>

                        <div className="grid grid-cols-2 gap-2">
                            <Field label="Floor">
                                <NumberInput
                                    step="0.1"
                                    value={sector.floorHeight}
                                    onChange={(next) =>
                                        onChangeSector({
                                            floorHeight: Number(next),
                                        })
                                    }
                                />
                            </Field>
                            <Field label="Ceiling">
                                <NumberInput
                                    step="0.1"
                                    value={sector.ceilingHeight}
                                    onChange={(next) =>
                                        onChangeSector({
                                            ceilingHeight: Number(next),
                                        })
                                    }
                                />
                            </Field>
                        </div>

                        <div className="flex gap-2">
                            <Toggle
                                label="Open to sky"
                                checked={sector.isSky}
                                onChange={(isSky) => onChangeSector({ isSky })}
                            />
                            <Toggle
                                label="Water"
                                checked={sector.isWater}
                                onChange={(isWater) =>
                                    onChangeSector({ isWater })
                                }
                            />
                        </div>

                        <TexturePicker
                            label="Floor texture"
                            value={sector.floorTexture}
                            textures={assets.textures}
                            onChange={(floorTexture) =>
                                onChangeSector({ floorTexture })
                            }
                        />

                        {!sector.isSky && (
                            <TexturePicker
                                label="Ceiling texture"
                                value={sector.ceilingTexture}
                                textures={assets.textures}
                                onChange={(ceilingTexture) =>
                                    onChangeSector({ ceilingTexture })
                                }
                            />
                        )}

                        <TexturePicker
                            label="Wall texture"
                            value={sector.wallTexture}
                            textures={assets.textures}
                            onChange={(wallTexture) =>
                                onChangeSector({ wallTexture })
                            }
                        />
                    </section>

                    {edge !== null && (
                        <section className="flex flex-col gap-3 border-t border-slate-800 pt-4">
                            <h2 className="text-xs tracking-widest text-slate-400 uppercase">
                                Wall {(selection?.edge ?? 0) + 1} of{' '}
                                {sector.points.length}
                            </h2>

                            <div className="flex gap-2">
                                <Toggle
                                    label="Solid"
                                    checked={edge.blocks}
                                    onChange={(blocks) =>
                                        onChangeEdge({ blocks })
                                    }
                                />
                                <Toggle
                                    label="Mirror"
                                    checked={edge.isMirror}
                                    onChange={(isMirror) =>
                                        onChangeEdge({ isMirror })
                                    }
                                />
                                <Toggle
                                    label="Sky"
                                    checked={edge.isSky}
                                    onChange={(isSky) =>
                                        onChangeEdge({ isSky })
                                    }
                                />
                            </div>

                            <p className="text-xs leading-relaxed text-slate-500">
                                Solid belongs to the wall itself, so it is set
                                for both rooms at once; leave it off for a
                                doorway. The texture and the mirror are this
                                room's side only.
                            </p>

                            {edge.isSky && (
                                <p className="text-xs leading-relaxed text-sky-300/80">
                                    Nothing is drawn here, so the sky shows
                                    through and whatever stands beyond is
                                    hidden. It is still a wall: you cannot walk
                                    out through it.
                                </p>
                            )}

                            {across !== null && (
                                <p className="text-xs leading-relaxed text-slate-400">
                                    Shared with{' '}
                                    <span className="text-slate-200">
                                        {across.name}
                                    </span>
                                    . Click the wall again on the map to work on
                                    that room's side of it.
                                </p>
                            )}

                            {openDoorway && (
                                <p className="text-xs leading-relaxed text-amber-300/80">
                                    This wall is a doorway, so nothing is drawn
                                    across it and a texture will not show. Tick
                                    Solid to make it a wall.
                                </p>
                            )}

                            <Field label="Portal link">
                                <input
                                    value={edge.portalLink ?? ''}
                                    placeholder="none"
                                    onChange={(event) =>
                                        onChangeEdge({
                                            portalLink:
                                                event.target.value
                                                    .toLowerCase()
                                                    .replace(
                                                        /[^a-z0-9-]/g,
                                                        '',
                                                    ) || null,
                                        })
                                    }
                                    className={inputClass}
                                />
                            </Field>

                            <p className="text-xs leading-relaxed text-slate-500">
                                Give two walls the same link and they become the
                                two ends of one portal: walk into either and you
                                come out of the other, still walking, turned by
                                the angle between them.
                            </p>

                            {edge.portalLink !== null && portalEnds !== 2 && (
                                <p className="text-xs leading-relaxed text-amber-300/80">
                                    {portalEnds === 1
                                        ? 'Only one wall has this link, so the portal does nothing yet. Give a second wall the same link.'
                                        : `${portalEnds} walls have this link. A portal is a pair, so none of them work.`}
                                </p>
                            )}

                            {edge.portalLink !== null &&
                                portalEnds === 2 &&
                                partner !== null && (
                                    <p className="text-xs leading-relaxed text-violet-300/80">
                                        Paired with a wall of{' '}
                                        <span className="text-slate-200">
                                            {partner.name}
                                        </span>
                                        . Give both walls the same length, or
                                        the player can come out beyond the far
                                        one.
                                    </p>
                                )}

                            <TexturePicker
                                label={
                                    across === null
                                        ? 'This wall'
                                        : `This wall, ${sector.name} side`
                                }
                                value={edge.wallTexture}
                                textures={assets.textures}
                                onChange={(wallTexture) =>
                                    onChangeEdge({ wallTexture })
                                }
                            />
                        </section>
                    )}
                </>
            )}
        </div>
    );
}
