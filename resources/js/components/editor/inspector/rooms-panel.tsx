import {
    Field,
    NumberInput,
    Toggle,
} from '@/components/editor/inspector/controls';
import TexturePicker from '@/components/editor/texture-picker';
import type { Level, LevelAssets, Sector } from '@/types';

/**
 * Several rooms picked at once, worked on together.
 *
 * The panel's job is telling the truth about a set rather than about one room.
 * Where they agree it shows the value; where they differ it shows nothing,
 * because a number that reads as fact would be imposed on the rest by the next
 * unrelated edit.
 *
 * No hinge picker, deliberately: a hinge is an index into one room's own walls,
 * so the same number means a different wall in each of them. The rise is safe
 * because it is the same quantity everywhere.
 */
export default function RoomsPanel({
    level,
    assets,
    rooms,
    onChangeRooms,
    onChangeRoomWalls,
    onDeleteRooms,
}: {
    level: Level;
    assets: LevelAssets;
    rooms: number[];
    onChangeRooms: (change: Partial<Sector>) => void;
    onChangeRoomWalls: (texture: string | null) => void;
    onDeleteRooms: () => void;
}) {
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
    const seeThrough = shared((sector) => sector.isInvisible);
    const floorRise = shared((sector) => sector.floorSlope);
    const ceilingRise = shared((sector) => sector.ceilingSlope);

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

                {/*
                    Rise only, with no hinge picker. A hinge is an index
                    into one room's own walls, and the same number means a
                    different wall in each of them — so offering it across a
                    mixed selection would set several rooms sloping in
                    directions nobody chose. The rise is safe because it is
                    the same quantity everywhere, and it only does anything
                    to rooms that already have a hinge.
                */}
                <div className="grid grid-cols-2 gap-2">
                    <Field label="Floor rise">
                        <NumberInput
                            step="0.05"
                            min={-8}
                            max={8}
                            value={floorRise ?? 0}
                            mixed={floorRise === undefined}
                            onChange={(next) =>
                                onChangeRooms({ floorSlope: next })
                            }
                        />
                    </Field>
                    <Field label="Ceiling rise">
                        <NumberInput
                            step="0.05"
                            min={-8}
                            max={8}
                            value={ceilingRise ?? 0}
                            mixed={ceilingRise === undefined}
                            onChange={(next) =>
                                onChangeRooms({ ceilingSlope: next })
                            }
                        />
                    </Field>
                </div>

                <div className="flex gap-2">
                    <Toggle
                        label={
                            sky === undefined ? 'Open to sky ·' : 'Open to sky'
                        }
                        checked={sky === true}
                        onChange={(isSky) => onChangeRooms({ isSky })}
                    />
                    <Toggle
                        label={water === undefined ? 'Water ·' : 'Water'}
                        checked={water === true}
                        onChange={(isWater) => onChangeRooms({ isWater })}
                    />
                    <Toggle
                        label={
                            seeThrough === undefined
                                ? 'See through ·'
                                : 'See through'
                        }
                        checked={seeThrough === true}
                        onChange={(isInvisible) =>
                            onChangeRooms({ isInvisible })
                        }
                    />
                </div>

                <p className="text-xs leading-relaxed text-slate-500">
                    A field marked · is not the same in all of them. Setting it
                    sets the lot.
                </p>
            </section>

            <section className="flex flex-col gap-3 border-t border-slate-800 pt-4">
                <TexturePicker
                    label="Floor texture"
                    value={shared((sector) => sector.floorTexture) ?? null}
                    textures={assets.textures}
                    onChange={(floorTexture) => onChangeRooms({ floorTexture })}
                />

                <TexturePicker
                    label="Ceiling texture"
                    value={shared((sector) => sector.ceilingTexture) ?? null}
                    textures={assets.textures}
                    onChange={(ceilingTexture) =>
                        onChangeRooms({ ceilingTexture })
                    }
                />

                <TexturePicker
                    label="Wall texture"
                    value={shared((sector) => sector.wallTexture) ?? null}
                    textures={assets.textures}
                    onChange={(wallTexture) => onChangeRooms({ wallTexture })}
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
