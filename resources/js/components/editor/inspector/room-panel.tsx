import {
    Field,
    inputClass,
    NumberInput,
    Toggle,
} from '@/components/editor/inspector/controls';
import SlopePanel from '@/components/editor/inspector/slope-panel';
import StairsPanel from '@/components/editor/inspector/stairs-panel';
import TexturePicker from '@/components/editor/texture-picker';
import type { StairPlan } from '@/lib/editor/stairs';
import type { Sector, SectorPoint } from '@/types';

/**
 * One room, and one of its walls if a wall is picked.
 *
 * Together in one panel because that is how they are worked on — you pick a
 * wall by picking it inside a room, and the room's settings stay in view while
 * you do. The wall half carries the reasoning: what is on the other side,
 * whether the doorway is really open, whether the portal it names is a pair.
 * All three are facts about the level rather than about the wall, and all three
 * are quiet when they are wrong.
 */
export default function RoomPanel({
    sector,
    edge,
    across,
    partner,
    portalEnds,
    openDoorway,
    edgeIndex,
    textures,
    onChangeSector,
    onChangeEdge,
    onCarveStairs,
    onDeleteSector,
}: {
    sector: Sector;
    edge: SectorPoint | null;
    across: Sector | null;
    partner: Sector | null;
    portalEnds: number;
    openDoorway: boolean;
    /** Which wall is picked, for saying "Wall 3 of 4". */
    edgeIndex: number;
    textures: string[];
    onChangeSector: (change: Partial<Sector>) => void;
    onChangeEdge: (change: Partial<SectorPoint>) => void;
    onCarveStairs: (plan: StairPlan) => void;
    onDeleteSector: () => void;
}) {
    return (
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

                <SlopePanel sector={sector} onChangeSector={onChangeSector} />

                <StairsPanel sector={sector} onCarveStairs={onCarveStairs} />

                <div className="flex gap-2">
                    <Toggle
                        label="Open to sky"
                        checked={sector.isSky}
                        onChange={(isSky) => onChangeSector({ isSky })}
                    />
                    <Toggle
                        label="Water"
                        checked={sector.isWater}
                        onChange={(isWater) => onChangeSector({ isWater })}
                    />
                </div>

                <TexturePicker
                    label="Floor texture"
                    value={sector.floorTexture}
                    textures={textures}
                    onChange={(floorTexture) =>
                        onChangeSector({ floorTexture })
                    }
                />

                {!sector.isSky && (
                    <TexturePicker
                        label="Ceiling texture"
                        value={sector.ceilingTexture}
                        textures={textures}
                        onChange={(ceilingTexture) =>
                            onChangeSector({ ceilingTexture })
                        }
                    />
                )}

                <TexturePicker
                    label="Wall texture"
                    value={sector.wallTexture}
                    textures={textures}
                    onChange={(wallTexture) => onChangeSector({ wallTexture })}
                />
            </section>

            {edge !== null && (
                <section className="flex flex-col gap-3 border-t border-slate-800 pt-4">
                    <h2 className="text-xs tracking-widest text-slate-400 uppercase">
                        Wall {edgeIndex + 1} of {sector.points.length}
                    </h2>

                    <div className="flex gap-2">
                        <Toggle
                            label="Solid"
                            checked={edge.blocks}
                            onChange={(blocks) => onChangeEdge({ blocks })}
                        />
                        <Toggle
                            label="Mirror"
                            checked={edge.isMirror}
                            onChange={(isMirror) => onChangeEdge({ isMirror })}
                        />
                        <Toggle
                            label="Sky"
                            checked={edge.isSky}
                            onChange={(isSky) => onChangeEdge({ isSky })}
                        />
                    </div>

                    <p className="text-xs leading-relaxed text-slate-500">
                        Solid belongs to the wall itself, so it is set for both
                        rooms at once; leave it off for a doorway. The texture
                        and the mirror are this room's side only.
                    </p>

                    {edge.isSky && (
                        <p className="text-xs leading-relaxed text-sky-300/80">
                            Nothing is drawn here, so the sky shows through and
                            whatever stands beyond is hidden. It is still a
                            wall: you cannot walk out through it.
                        </p>
                    )}

                    {across !== null && (
                        <p className="text-xs leading-relaxed text-slate-400">
                            Shared with{' '}
                            <span className="text-slate-200">
                                {across.name}
                            </span>
                            . Click the wall again on the map to work on that
                            room's side of it.
                        </p>
                    )}

                    {openDoorway && (
                        <p className="text-xs leading-relaxed text-amber-300/80">
                            This wall is a doorway, so nothing is drawn across
                            it and a texture will not show. Tick Solid to make
                            it a wall.
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
                                            .replace(/[^a-z0-9-]/g, '') || null,
                                })
                            }
                            className={inputClass}
                        />
                    </Field>

                    <p className="text-xs leading-relaxed text-slate-500">
                        Give two walls the same link and they become the two
                        ends of one portal: walk into either and you come out of
                        the other, still walking, turned by the angle between
                        them.
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
                                . Give both walls the same length, or the player
                                can come out beyond the far one.
                            </p>
                        )}

                    <TexturePicker
                        label={
                            across === null
                                ? 'This wall'
                                : `This wall, ${sector.name} side`
                        }
                        value={edge.wallTexture}
                        textures={textures}
                        onChange={(wallTexture) =>
                            onChangeEdge({ wallTexture })
                        }
                    />
                </section>
            )}
        </>
    );
}
