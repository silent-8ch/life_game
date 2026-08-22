import { Head, router } from '@inertiajs/react';
import { useCallback, useEffect, useMemo, useState } from 'react';
import { show } from '@/actions/App/Http/Controllers/GameController';
import { update } from '@/actions/App/Http/Controllers/LevelEditorController';
import Inspector from '@/components/editor/inspector';
import MapView from '@/components/editor/map-view';
import type { Tool } from '@/components/editor/map-view';
import SideView from '@/components/editor/side-view';
import LevelViewport from '@/components/game/level-viewport';
import { carveRooms, weldCorners } from '@/lib/editor/carve';
import {
    duplicateRooms,
    moveCorner,
    newPerson,
    newProp,
    newSector,
    nudgeHeights,
    splitEdge,
    toggleRoom,
    updateEdge,
    updateSector,
    updateThing,
    windingOf,
} from '@/lib/editor/map';
import type { Point, Selection } from '@/lib/editor/map';
import { DEFAULT_PLAYER_HEIGHT, HEIGHTS } from '@/lib/engine/sprite-actor';
import { cn } from '@/lib/utils';
import type { EditorPageProps, Level } from '@/types';

/**
 * The map editor: a floor plan to draw rooms on, a section to set their heights
 * in, and a preview running the same engine the game does, so what is drawn is
 * what gets walked around.
 */

const GRIDS = [0.25, 0.5, 1, 2];

/** How long to wait after an edit before rebuilding the preview. */
const PREVIEW_DELAY = 500;

export default function LevelEditor({
    game,
    level,
    levelId,
    assets,
}: EditorPageProps) {
    const [draft, setDraft] = useState<Level>(level);
    const [saved, setSaved] = useState<Level>(level);
    const [tool, setTool] = useState<Tool>('select');
    const [grid, setGrid] = useState(0.5);
    const [selection, setSelection] = useState<Selection>(null);
    // Every room picked. The one in `selection` is among them, and is the one
    // whose walls the panel offers when only one is picked.
    const [rooms, setRooms] = useState<number[]>([]);
    const [thing, setThing] = useState<number | null>(null);
    // Which of the household the next click drops, when placing people.
    const [sprite, setSprite] = useState<string>(assets.sprites[0] ?? '');
    const [drawing, setDrawing] = useState<Point[]>([]);
    const [saving, setSaving] = useState(false);
    const [preview, setPreview] = useState<Level>(level);

    const dirty = useMemo(
        () => JSON.stringify(draft) !== JSON.stringify(saved),
        [draft, saved],
    );

    // The preview rebuilds the whole level, so it waits for a pause in editing.
    useEffect(() => {
        const timer = setTimeout(() => setPreview(draft), PREVIEW_DELAY);

        return () => clearTimeout(timer);
    }, [draft]);

    const closeShape = useCallback(() => {
        if (drawing.length < 3) {
            return;
        }

        // Rooms are wound one way round so walls know which side they face.
        const points =
            windingOf(drawing) > 0 ? drawing : [...drawing].reverse();

        // Rooms never overlap: the new one is cut out of anything it lands on.
        const carved = carveRooms(
            {
                ...draft,
                sectors: [...draft.sectors, newSector(draft, points)],
            },
            draft.sectors.length,
        );

        setDraft(carved);
        setSelection({ sector: carved.sectors.length - 1, edge: null });
        setRooms([carved.sectors.length - 1]);
        setDrawing([]);

        // The tool stays in hand: rooms come in twos and threes, and having to
        // reach for it again between each one is a nuisance. Esc puts it down.
    }, [drawing, draft]);

    // Every room an edit works on: the whole picked set, or the one room the
    // selection holds when nothing was gathered up.
    const picked = useMemo(
        () =>
            rooms.length ? rooms : selection === null ? [] : [selection.sector],
        [rooms, selection],
    );

    const duplicate = useCallback(() => {
        if (picked.length === 0) {
            return;
        }

        // The copies go on the end, in the order they were picked.
        const first = draft.sectors.length;

        setDraft((current) => duplicateRooms(current, picked));
        setRooms(picked.map((_, index) => first + index));
        setSelection({ sector: first, edge: null });
        setThing(null);
    }, [picked, draft.sectors.length]);

    useEffect(() => {
        const handleKey = (event: KeyboardEvent): void => {
            if (event.target instanceof HTMLElement) {
                const typing = ['INPUT', 'TEXTAREA', 'SELECT'].includes(
                    event.target.tagName,
                );

                if (typing) {
                    return;
                }
            }

            // Before the tool keys, or duplicating would also put the draw
            // tool in hand.
            if ((event.metaKey || event.ctrlKey) && event.key === 'd') {
                event.preventDefault();
                duplicate();

                return;
            }

            // Heights by the step the section's drag snaps to, on whatever is
            // picked — shift for the ceiling, as the handles are stacked.
            if (event.key === 'ArrowUp' || event.key === 'ArrowDown') {
                if (picked.length === 0) {
                    return;
                }

                event.preventDefault();
                setDraft((current) =>
                    nudgeHeights(
                        current,
                        picked,
                        event.shiftKey ? 'ceiling' : 'floor',
                        event.key === 'ArrowUp' ? 1 : -1,
                    ),
                );

                return;
            }

            if (event.key === 'Escape') {
                setDrawing([]);
                setTool('select');
                setThing(null);
                setRooms([]);
            }

            if (event.key === 'Enter' && drawing.length > 2) {
                closeShape();
            }

            if (event.key === 'd') {
                setTool('draw');
            }

            if (event.key === 'v') {
                setTool('select');
            }

            if (event.key === 'p') {
                setTool('person');
            }

            if (event.key === 't') {
                setTool('prop');
            }
        };

        window.addEventListener('keydown', handleKey);

        return () => window.removeEventListener('keydown', handleKey);
    });

    const save = (): void => {
        setSaving(true);

        router.put(
            update.url(levelId),
            {
                name: draft.name,
                description: draft.description,
                playerSprite: draft.playerSprite,
                spawn: draft.spawn,
                ceilingHeight: draft.ceilingHeight,
                sky: draft.sky,
                sectors: draft.sectors,
                things: draft.things,
            },
            {
                preserveScroll: true,
                preserveState: true,
                onSuccess: () => setSaved(draft),
                onFinish: () => setSaving(false),
            },
        );
    };

    const sector = selection === null ? null : draft.sectors[selection.sector];

    return (
        <>
            <Head title={`${draft.name} — map editor`} />

            <div className="flex h-screen flex-col bg-slate-950 text-slate-200">
                <header className="flex shrink-0 items-center gap-4 border-b border-slate-800 px-4 py-2">
                    <div className="min-w-0">
                        <p className="text-[11px] tracking-widest text-slate-500 uppercase">
                            {game.title} · map editor
                        </p>
                        <h1 className="truncate text-lg font-medium">
                            {draft.name}
                        </h1>
                    </div>

                    <div className="ml-auto flex items-center gap-2">
                        <a
                            href="/admin/levels"
                            className="rounded border border-slate-700 px-3 py-1.5 text-sm text-slate-300 hover:border-slate-500"
                        >
                            Levels
                        </a>
                        <a
                            href={`${show.url(game.slug)}?level=${encodeURIComponent(saved.slug)}`}
                            target="_blank"
                            rel="noreferrer"
                            className="rounded border border-slate-700 px-3 py-1.5 text-sm text-slate-300 hover:border-slate-500"
                        >
                            Play
                        </a>
                        <button
                            type="button"
                            onClick={() => setDraft(saved)}
                            disabled={!dirty}
                            className="rounded border border-slate-700 px-3 py-1.5 text-sm text-slate-300 disabled:opacity-40"
                        >
                            Revert
                        </button>
                        <button
                            type="button"
                            onClick={save}
                            disabled={!dirty || saving}
                            className={cn(
                                'rounded px-4 py-1.5 text-sm font-medium',
                                dirty
                                    ? 'bg-amber-400 text-slate-950 hover:bg-amber-300'
                                    : 'bg-slate-800 text-slate-500',
                            )}
                        >
                            {saving ? 'Saving…' : dirty ? 'Save' : 'Saved'}
                        </button>
                    </div>
                </header>

                <div className="flex min-h-0 flex-1">
                    <div className="flex min-w-0 flex-1 flex-col">
                        <div className="flex shrink-0 items-center gap-3 border-b border-slate-800 px-4 py-2 text-sm">
                            {(
                                [
                                    ['select', 'Select', 'V'],
                                    ['draw', 'Draw room', 'D'],
                                    ['person', 'Add person', 'P'],
                                    ['prop', 'Add thing', 'T'],
                                    ['spawn', 'Move start', ''],
                                ] as const
                            ).map(([value, label, key]) => (
                                <button
                                    key={value}
                                    type="button"
                                    onClick={() => setTool(value)}
                                    className={cn(
                                        'rounded border px-3 py-1',
                                        tool === value
                                            ? 'border-amber-400 bg-amber-400/10 text-amber-200'
                                            : 'border-slate-700 text-slate-300 hover:border-slate-500',
                                    )}
                                >
                                    {label}
                                    {key !== '' && (
                                        <span className="ml-2 text-[10px] text-slate-500">
                                            {key}
                                        </span>
                                    )}
                                </button>
                            ))}

                            <span className="ml-4 text-[11px] tracking-wider text-slate-500 uppercase">
                                Grid
                            </span>
                            {GRIDS.map((size) => (
                                <button
                                    key={size}
                                    type="button"
                                    onClick={() => setGrid(size)}
                                    className={cn(
                                        'rounded border px-2 py-1 text-xs',
                                        grid === size
                                            ? 'border-amber-400 text-amber-200'
                                            : 'border-slate-700 text-slate-400 hover:border-slate-500',
                                    )}
                                >
                                    {size}m
                                </button>
                            ))}

                            {tool === 'draw' && (
                                <span className="ml-auto text-xs text-slate-400">
                                    Click corners · click the first again or
                                    press Enter to close · draw another · Esc to
                                    stop
                                </span>
                            )}

                            {tool === 'person' && (
                                <select
                                    value={sprite}
                                    onChange={(event) =>
                                        setSprite(event.target.value)
                                    }
                                    className="rounded border border-slate-700 bg-slate-950 px-2 py-1 text-xs text-slate-200"
                                >
                                    {assets.sprites.map((name) => (
                                        <option key={name} value={name}>
                                            {name}
                                        </option>
                                    ))}
                                </select>
                            )}

                            {(tool === 'person' || tool === 'prop') && (
                                <span className="ml-auto text-xs text-slate-400">
                                    Click to put one down · again for another ·
                                    Esc to stop
                                </span>
                            )}

                            {tool === 'select' && (
                                <span className="ml-auto text-xs text-slate-400">
                                    Drag a corner, a person or the start to move
                                    it · double-click a wall to add a corner ·
                                    shift-click or sweep to take several rooms ·
                                    ⌘D copies them
                                </span>
                            )}
                        </div>

                        <div className="min-h-0 flex-1">
                            <MapView
                                level={draft}
                                tool={tool}
                                grid={grid}
                                selection={selection}
                                drawing={drawing}
                                rooms={rooms}
                                thing={thing}
                                onSelect={(picked) => {
                                    setSelection(picked);

                                    if (picked !== null) {
                                        setThing(null);
                                    }
                                }}
                                onSelectRooms={(picked) => {
                                    setRooms(picked);

                                    if (picked.length > 0) {
                                        setThing(null);
                                    }
                                }}
                                onToggleRoom={(index) => {
                                    const next = toggleRoom(rooms, index);

                                    setRooms(next);
                                    setThing(null);
                                    setSelection(
                                        next.length
                                            ? { sector: next[0], edge: null }
                                            : null,
                                    );
                                }}
                                onSelectThing={(picked) => {
                                    setThing(picked);

                                    if (picked !== null) {
                                        setSelection(null);
                                    }
                                }}
                                onPlaceThing={(at) => {
                                    setDraft((current) => {
                                        const made =
                                            tool === 'person'
                                                ? newPerson(
                                                      current,
                                                      sprite,
                                                      at,
                                                      HEIGHTS[sprite] ??
                                                          DEFAULT_PLAYER_HEIGHT,
                                                  )
                                                : newProp(current, at);

                                        return {
                                            ...current,
                                            things: [...current.things, made],
                                        };
                                    });
                                    setThing(draft.things.length);
                                    setSelection(null);
                                }}
                                onMoveThing={(index, to) =>
                                    setDraft((current) =>
                                        updateThing(current, index, to),
                                    )
                                }
                                onMoveCorner={(from, to) =>
                                    setDraft((current) =>
                                        moveCorner(current, from, to),
                                    )
                                }
                                onDropCorner={() => {
                                    setDraft((current) => weldCorners(current));
                                    // Welding can renumber a room's walls.
                                    setSelection((current) =>
                                        current === null
                                            ? null
                                            : { ...current, edge: null },
                                    );
                                }}
                                onAddCorner={(at) =>
                                    setDrawing((current) => [...current, at])
                                }
                                onSplitEdge={(from, to, at) =>
                                    setDraft((current) =>
                                        splitEdge(current, from, to, at),
                                    )
                                }
                                onCloseShape={closeShape}
                                onMoveSpawn={(at) =>
                                    setDraft((current) => ({
                                        ...current,
                                        spawn: { ...current.spawn, ...at },
                                    }))
                                }
                            />
                        </div>

                        <div className="h-44 shrink-0 border-t border-slate-800">
                            <SideView
                                level={draft}
                                selected={selection?.sector ?? null}
                                onChangeHeights={(floorHeight, ceilingHeight) =>
                                    picked.length > 0 &&
                                    setDraft((current) =>
                                        // A dragged handle takes every picked
                                        // room with it, not only the one the
                                        // section happens to be drawing.
                                        picked.reduce(
                                            (level, index) =>
                                                updateSector(level, index, {
                                                    floorHeight,
                                                    ceilingHeight,
                                                }),
                                            current,
                                        ),
                                    )
                                }
                            />
                        </div>
                    </div>

                    <aside className="flex w-80 shrink-0 flex-col border-l border-slate-800">
                        <div className="min-h-0 flex-1">
                            <Inspector
                                level={draft}
                                assets={assets}
                                selection={selection}
                                rooms={rooms}
                                thing={thing}
                                onChangeRooms={(change) =>
                                    setDraft((current) =>
                                        rooms.reduce(
                                            (level, index) =>
                                                updateSector(
                                                    level,
                                                    index,
                                                    change,
                                                ),
                                            current,
                                        ),
                                    )
                                }
                                onChangeRoomWalls={(texture) =>
                                    setDraft((current) => ({
                                        ...current,
                                        sectors: current.sectors.map(
                                            (sector, index) =>
                                                rooms.includes(index)
                                                    ? {
                                                          ...sector,
                                                          points: sector.points.map(
                                                              (point) => ({
                                                                  ...point,
                                                                  wallTexture:
                                                                      texture,
                                                              }),
                                                          ),
                                                      }
                                                    : sector,
                                        ),
                                    }))
                                }
                                onDeleteRooms={() => {
                                    setDraft((current) => ({
                                        ...current,
                                        sectors: current.sectors.filter(
                                            (_, index) =>
                                                !rooms.includes(index),
                                        ),
                                    }));
                                    setRooms([]);
                                    setSelection(null);
                                }}
                                onChangeThing={(change) =>
                                    thing !== null &&
                                    setDraft((current) =>
                                        updateThing(current, thing, change),
                                    )
                                }
                                onDeleteThing={() => {
                                    if (thing === null) {
                                        return;
                                    }

                                    setDraft((current) => ({
                                        ...current,
                                        things: current.things.filter(
                                            (_, index) => index !== thing,
                                        ),
                                    }));
                                    setThing(null);
                                }}
                                onChangeLevel={(change) =>
                                    setDraft((current) => ({
                                        ...current,
                                        ...change,
                                    }))
                                }
                                onChangeSector={(change) =>
                                    selection !== null &&
                                    setDraft((current) =>
                                        updateSector(
                                            current,
                                            selection.sector,
                                            change,
                                        ),
                                    )
                                }
                                onChangeEdge={(change) =>
                                    selection?.edge !== null &&
                                    selection !== null &&
                                    setDraft((current) =>
                                        updateEdge(
                                            current,
                                            selection.sector,
                                            selection.edge ?? 0,
                                            change,
                                        ),
                                    )
                                }
                                onDeleteSector={() => {
                                    if (selection === null) {
                                        return;
                                    }

                                    setDraft((current) => ({
                                        ...current,
                                        sectors: current.sectors.filter(
                                            (_, index) =>
                                                index !== selection.sector,
                                        ),
                                    }));
                                    setSelection(null);
                                    setRooms([]);
                                }}
                            />
                        </div>

                        <div className="shrink-0 border-t border-slate-800 p-3">
                            <p className="mb-2 text-[11px] tracking-wider text-slate-500 uppercase">
                                Preview
                                {sector !== null && ` · ${sector.name}`}
                            </p>
                            <LevelViewport
                                level={preview}
                                onFocus={() => undefined}
                                onExamine={() => undefined}
                                onLockChange={() => undefined}
                            />
                        </div>
                    </aside>
                </div>
            </div>
        </>
    );
}
