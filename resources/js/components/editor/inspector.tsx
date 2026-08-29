import LevelPanel from '@/components/editor/inspector/level-panel';
import RoomPanel from '@/components/editor/inspector/room-panel';
import RoomsPanel from '@/components/editor/inspector/rooms-panel';
import ThingPanel from '@/components/editor/inspector/thing-panel';
import type { StairPlan } from '@/lib/editor/stairs';
import { wallFacts } from '@/lib/editor/walls';
import type {
    LevelAssets,
    Level,
    LevelThing,
    Sector,
    SectorPoint,
} from '@/types';

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
    /** Replaces the picked room with a flight of steps. */
    onCarveStairs: (plan: StairPlan) => void;
    onChangeThing: (change: Partial<LevelThing>) => void;
    onDeleteThing: () => void;
    onDeleteSector: () => void;
};

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
    onCarveStairs,
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

    // No `twin` here: it was only ever read to work out `across` and whether
    // the doorway is open, and both of those are worked out for us now.
    const { across, partner, portalEnds, openDoorway, mouth } = wallFacts(
        level,
        selection,
    );

    // More than one room picked: work on what they have in common.
    if (rooms.length > 1 && thing === null) {
        return (
            <RoomsPanel
                level={level}
                assets={assets}
                rooms={rooms}
                onChangeRooms={onChangeRooms}
                onChangeRoomWalls={onChangeRoomWalls}
                onDeleteRooms={onDeleteRooms}
            />
        );
    }

    if (held !== null) {
        return (
            <ThingPanel
                held={held}
                assets={assets}
                onChangeThing={onChangeThing}
                onDeleteThing={onDeleteThing}
            />
        );
    }

    return (
        <div className="flex h-full flex-col gap-5 overflow-y-auto p-4">
            {sector === null ? (
                <LevelPanel
                    level={level}
                    assets={assets}
                    onChangeLevel={onChangeLevel}
                />
            ) : (
                <RoomPanel
                    sector={sector}
                    edge={edge}
                    across={across}
                    partner={partner}
                    portalEnds={portalEnds}
                    mouth={mouth}
                    openDoorway={openDoorway}
                    edgeIndex={selection?.edge ?? 0}
                    textures={assets.textures}
                    onChangeSector={onChangeSector}
                    onChangeEdge={onChangeEdge}
                    onCarveStairs={onCarveStairs}
                    onDeleteSector={onDeleteSector}
                />
            )}
        </div>
    );
}
