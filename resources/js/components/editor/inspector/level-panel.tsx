import {
    Field,
    inputClass,
    NumberInput,
    Toggle,
} from '@/components/editor/inspector/controls';
import type { Level, LevelAssets } from '@/types';

/**
 * The level itself, which is what shows when nothing is picked.
 *
 * Mostly plain fields. The sky is the part with behaviour: it is a whole object
 * or nothing at all, and its three settings only mean anything once there is
 * one, so they appear with it rather than writing into something absent.
 */
export default function LevelPanel({
    level,
    assets,
    themes,
    layers,
    onChangeLevel,
}: {
    level: Level;
    assets: LevelAssets;
    themes: string[];
    layers: number[];
    onChangeLevel: (change: Partial<Level>) => void;
}) {
    return (
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
                    Whoever you are is only ever seen in a mirror or through a
                    portal. Nothing stops you putting them in the room as well,
                    and meeting yourself.
                </p>

                <Field label="Art style">
                    <select
                        value={level.spriteStyle}
                        onChange={(event) =>
                            onChangeLevel({
                                spriteStyle: event.target.value,
                            })
                        }
                        className={inputClass}
                    >
                        {assets.styles.map((name) => (
                            <option key={name} value={name}>
                                {name}
                            </option>
                        ))}
                    </select>
                </Field>

                <p className="text-xs leading-relaxed text-slate-500">
                    Which set of sheets the people are drawn from. Only people
                    with sheets in the chosen style appear — a style is a whole
                    cast, not one character.
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
                                              variant: level.sky?.variant ?? 0,
                                              theme: level.sky?.theme ?? null,
                                              layers: level.sky?.layers ?? [],
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
                                            variant: Number(event.target.value),
                                        },
                                    })
                                }
                                className={inputClass}
                            >
                                {[0, 1, 2, 3].map((variant) => (
                                    <option key={variant} value={variant}>
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
                                                event.target.value === ''
                                                    ? null
                                                    : event.target.value,
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
                                            level.sky?.layers.includes(layer) ??
                                            false
                                        }
                                        onChange={(on) =>
                                            onChangeLevel({
                                                sky: {
                                                    ...level.sky!,
                                                    layers: on
                                                        ? [
                                                              ...(level.sky
                                                                  ?.layers ??
                                                                  []),
                                                              layer,
                                                          ].sort()
                                                        : (
                                                              level.sky
                                                                  ?.layers ?? []
                                                          ).filter(
                                                              (at) =>
                                                                  at !== layer,
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
                Pick a room on the plan to set its heights and textures, or draw
                a new one. Rooms that share two corners have a way through
                between them unless that wall is marked solid.
            </p>
        </>
    );
}
