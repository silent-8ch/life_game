import {
    Field,
    inputClass,
    NumberInput,
    Toggle,
} from '@/components/editor/inspector/controls';
import DrawingPanel from '@/components/editor/inspector/drawing-panel';
import StatsPanel from '@/components/editor/inspector/stats-panel';
import InteractionPanel from '@/components/editor/interaction-panel';
import TexturePicker from '@/components/editor/texture-picker';
import type { LevelAssets, LevelThing } from '@/types';

/**
 * One thing: a person, or a piece of furniture.
 *
 * The two share a panel and almost nothing else. A person is drawn from a
 * sprite sheet and has a stat block; a prop is built as a box and has a shape,
 * a texture and frames. Showing either the wrong half is not a crash — it is a
 * control that quietly does nothing, which is worse, so everything here is
 * conditional on what it actually applies to.
 */
export default function ThingPanel({
    held,
    assets,
    onChangeThing,
    onDeleteThing,
}: {
    held: LevelThing;
    assets: LevelAssets;
    onChangeThing: (change: Partial<LevelThing>) => void;
    onDeleteThing: () => void;
}) {
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
                        folder={held.render === 'box' ? 'textures' : 'props'}
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
