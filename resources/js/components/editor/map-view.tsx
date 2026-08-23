import { useEffect, useMemo, useRef, useState } from 'react';
import {
    centroid,
    closestOnEdge,
    distanceToEdge,
    fitView,
    lineNear,
    MAX_SCALE,
    MIN_SCALE,
    boxBetween,
    pointInSector,
    roomsWithin,
    samePoint,
    snap,
    thingNear,
    toScreen,
    toWorld,
    twinEdge,
    windingOf,
} from '@/lib/editor/map';
import type { Point, Selection, View } from '@/lib/editor/map';
import { boundsOf } from '@/lib/engine/sectors';
import type { Level } from '@/types';

/**
 * The floor plan. Rooms are drawn as polygons on a grid: click one to work on
 * it, click a wall to work on that wall, drag a corner to move it, and
 * double-click a wall to put a new corner in it — and a corner shared by two
 * rooms moves for both, which is what keeps a doorway lined up.
 *
 * Shift-click gathers rooms up one at a time, and dragging across empty space
 * sweeps out a box and takes whatever has its middle inside. Panning is on the
 * middle button, the left one being busy.
 */

export type Tool = 'select' | 'draw' | 'spawn' | 'person' | 'prop' | 'wire';

type MapViewProps = {
    level: Level;
    tool: Tool;
    grid: number;
    selection: Selection;
    /** Every room picked, the one in `selection` among them. */
    rooms: number[];
    /** Which thing is picked, if it is a thing rather than a room. */
    thing: number | null;
    /** Whether there is an edit to step back from, for the readout to say so. */
    canUndo: boolean;
    drawing: Point[];
    onSelect: (selection: Selection) => void;
    /** Picks a set of rooms outright, or adds one to what is already picked. */
    onSelectRooms: (rooms: number[]) => void;
    onToggleRoom: (index: number) => void;
    onSelectThing: (index: number | null) => void;
    onPlaceThing: (at: Point) => void;
    /**
     * A line drawn from one thing to another, which is the whole of how two
     * things are connected.
     *
     * Drawing it here rather than typing a name either end is the second slice
     * of action lines: Paul asked to *switch to manual drawing of the redstone
     * line*, and this is the drawing. Passed the two slugs because that is what
     * a line is; the map has no opinion about what either end does with it.
     */
    /** Whether to draw every line, rather than only the picked thing's. */
    showLines: boolean;
    onDrawLine: (from: string, to: string) => void;
    /** A line taken out again, by its two ends. */
    onCutLine: (from: string, to: string) => void;
    onMoveThing: (index: number, to: Point) => void;
    onMoveCorner: (from: Point, to: Point) => void;
    onDropCorner: () => void;
    onAddCorner: (at: Point) => void;
    onSplitEdge: (from: Point, to: Point, at: Point) => void;
    onCloseShape: () => void;
    onMoveSpawn: (at: Point) => void;
};

const COLORS = {
    background: '#0c0f14',
    grid: '#1b2430',
    gridStrong: '#26333f',
    room: 'rgba(125, 211, 252, 0.10)',
    roomSky: 'rgba(56, 189, 248, 0.16)',
    roomWater: 'rgba(45, 212, 191, 0.20)',
    roomSelected: 'rgba(251, 191, 36, 0.18)',
    /** A room the camera sees through. Faint, because it barely exists. */
    roomSeeThrough: 'rgba(148, 163, 184, 0.08)',
    /** A drawn action line, and the one being dragged out. */
    line: 'rgba(56, 189, 248, 0.75)',
    lineDragging: 'rgba(250, 204, 21, 0.9)',
    sweep: 'rgba(251, 191, 36, 0.08)',
    wall: '#7dd3fc',
    wallSelected: '#fbbf24',
    portal: '#475569',
    mirror: '#f472b6',
    /** A wall linked to another as the two ends of one portal. */
    portalMouth: '#a78bfa',
    corner: '#e2e8f0',
    spawn: '#4ade80',
    thing: '#f59e0b',
    actor: '#f472b6',
    label: 'rgba(226, 232, 240, 0.75)',
    pending: '#fbbf24',
};

/** How close, in pixels, counts as clicking a corner or a wall. */
const GRAB_PIXELS = 9;

export default function MapView({
    level,
    tool,
    grid,
    selection,
    rooms,
    thing,
    canUndo,
    drawing,
    onSelect,
    onSelectRooms,
    onToggleRoom,
    onSelectThing,
    onPlaceThing,
    showLines,
    onDrawLine,
    onCutLine,
    onMoveThing,
    onMoveCorner,
    onDropCorner,
    onAddCorner,
    onSplitEdge,
    onCloseShape,
    onMoveSpawn,
}: MapViewProps) {
    const canvasRef = useRef<HTMLCanvasElement>(null);
    const holderRef = useRef<HTMLDivElement>(null);

    const [view, setView] = useState<View | null>(null);
    const [pointer, setPointer] = useState<Point | null>(null);
    const [dragging, setDragging] = useState<Point | null>(null);
    /** Where a corner drag started, and whether it has moved anywhere yet. */
    const [grabbed, setGrabbed] = useState<Point | null>(null);
    const [draggingThing, setDraggingThing] = useState<number | null>(null);
    const [draggingSpawn, setDraggingSpawn] = useState(false);
    const [moved, setMoved] = useState(false);
    const [panning, setPanning] = useState<{ x: number; y: number } | null>(
        null,
    );
    /** Where a sweep of empty space started, while it is being dragged out. */
    const [sweeping, setSweeping] = useState<Point | null>(null);

    /**
     * The line being pulled out of a thing, while the button is down: which
     * thing it left, and where the pointer has got to, in metres.
     */
    const [wiring, setWiring] = useState<{
        from: string;
        at: Point;
    } | null>(null);

    // Everything the draw loop needs, without making it a dependency of itself.
    const sweepRef = useRef<Point | null>(null);

    const state = useRef({
        level,
        tool,
        grid,
        selection,
        rooms,
        thing,
        drawing,
        view,
        pointer,
        dragging,
        wiring,
        showLines,
    });

    useEffect(() => {
        state.current = {
            level,
            tool,
            grid,
            selection,
            rooms,
            thing,
            drawing,
            view,
            pointer,
            dragging,
            wiring,
            showLines,
        };

        sweepRef.current = sweeping;
    });

    useEffect(() => {
        const holder = holderRef.current;
        const canvas = canvasRef.current;

        if (holder === null || canvas === null) {
            return;
        }

        const context = canvas.getContext('2d');

        if (context === null) {
            return;
        }

        let frame = 0;

        const resize = (): void => {
            const ratio = window.devicePixelRatio;

            canvas.width = holder.clientWidth * ratio;
            canvas.height = holder.clientHeight * ratio;
            canvas.style.width = `${holder.clientWidth}px`;
            canvas.style.height = `${holder.clientHeight}px`;

            setView(
                (current) =>
                    current ??
                    fitView(
                        state.current.level,
                        holder.clientWidth,
                        holder.clientHeight,
                    ),
            );
        };

        const draw = (): void => {
            frame = requestAnimationFrame(draw);

            const {
                level: map,
                grid: step,
                selection: picked,
                drawing: pending,
                view: at,
                pointer: over,
                tool: using,
            } = state.current;

            if (at === null) {
                return;
            }

            const ratio = window.devicePixelRatio;
            const width = canvas.width / ratio;
            const height = canvas.height / ratio;

            context.setTransform(ratio, 0, 0, ratio, 0, 0);
            context.fillStyle = COLORS.background;
            context.fillRect(0, 0, width, height);

            // Grid, heavier every five metres.
            const spacing = Math.max(step, 0.25);
            const firstX = Math.floor(at.originX / spacing) * spacing;
            const lastX = at.originX + width / at.scale;
            const firstZ = Math.floor(at.originZ / spacing) * spacing;
            const lastZ = at.originZ + height / at.scale;

            context.lineWidth = 1;

            for (let x = firstX; x <= lastX; x += spacing) {
                const [screenX] = toScreen(at, x, 0);

                context.strokeStyle =
                    Math.abs(x % 5) < 1e-6 ? COLORS.gridStrong : COLORS.grid;
                context.beginPath();
                context.moveTo(screenX, 0);
                context.lineTo(screenX, height);
                context.stroke();
            }

            for (let z = firstZ; z <= lastZ; z += spacing) {
                const [, screenZ] = toScreen(at, 0, z);

                context.strokeStyle =
                    Math.abs(z % 5) < 1e-6 ? COLORS.gridStrong : COLORS.grid;
                context.beginPath();
                context.moveTo(0, screenZ);
                context.lineTo(width, screenZ);
                context.stroke();
            }

            // Rooms.
            const inSelection = state.current.rooms;

            map.sectors.forEach((sector, index) => {
                const chosen = inSelection.includes(index);

                context.beginPath();

                sector.points.forEach((point, corner) => {
                    const [x, y] = toScreen(at, point.x, point.z);

                    if (corner === 0) {
                        context.moveTo(x, y);
                    } else {
                        context.lineTo(x, y);
                    }
                });

                context.closePath();
                // See-through beats the rest: a room that draws nothing is the
                // one thing on this map you cannot check by looking at the
                // level, since looking at the level is exactly what does not
                // show it.
                context.fillStyle = chosen
                    ? COLORS.roomSelected
                    : sector.isInvisible
                      ? COLORS.roomSeeThrough
                      : sector.isWater
                        ? COLORS.roomWater
                        : sector.isSky
                          ? COLORS.roomSky
                          : COLORS.room;
                context.fill();

                // Walls, and the openings between rooms.
                sector.points.forEach((point, corner) => {
                    const next =
                        sector.points[(corner + 1) % sector.points.length];
                    const [fromX, fromY] = toScreen(at, point.x, point.z);
                    const [toX, toY] = toScreen(at, next.x, next.z);

                    // Both sides have to leave it open for it to be a doorway,
                    // which is how the engine reads it too.
                    const twin = twinEdge(map, index, corner);
                    const open =
                        twin !== null &&
                        !point.blocks &&
                        !map.sectors[twin.sector].points[twin.edge].blocks;
                    const isPicked = chosen && picked?.edge === corner;

                    const linked =
                        point.portalLink !== null && point.portalLink !== '';

                    context.strokeStyle = linked
                        ? COLORS.portalMouth
                        : point.isMirror
                          ? COLORS.mirror
                          : isPicked
                            ? COLORS.wallSelected
                            : open
                              ? COLORS.portal
                              : COLORS.wall;
                    context.lineWidth = isPicked
                        ? 4
                        : linked
                          ? 3
                          : open
                            ? 1.5
                            : 2.5;
                    context.setLineDash(open ? [5, 4] : []);
                    context.beginPath();
                    context.moveTo(fromX, fromY);
                    context.lineTo(toX, toY);
                    context.stroke();
                    context.setLineDash([]);

                    context.fillStyle = COLORS.corner;
                    context.fillRect(fromX - 2.5, fromY - 2.5, 5, 5);
                });

                const middle = centroid(sector);
                const [labelX, labelY] = toScreen(at, middle.x, middle.z);

                context.fillStyle = COLORS.label;
                context.font =
                    '11px ui-sans-serif, system-ui, -apple-system, sans-serif';
                context.textAlign = 'center';
                context.fillText(sector.name, labelX, labelY);
                context.fillText(
                    `${sector.floorHeight.toFixed(1)} → ${sector.ceilingHeight.toFixed(1)}`,
                    labelX,
                    labelY + 13,
                );

                // An arrow on the hinge wall, pointing uphill. Without it a
                // slope is invisible in plan — the heights shown above are the
                // heights along the hinge and nowhere else, so two rooms
                // reading "0.0 → 3.0" can be quite different rooms.
                if (sector.floorSlope !== 0 && sector.floorSlopeEdge !== null) {
                    const points = sector.points;
                    const hinge = points[sector.floorSlopeEdge];
                    const next =
                        points[(sector.floorSlopeEdge + 1) % points.length];

                    if (hinge !== undefined && next !== undefined) {
                        const spanX = next.x - hinge.x;
                        const spanZ = next.z - hinge.z;
                        const length = Math.hypot(spanX, spanZ) || 1;

                        // Into the room, and reversed when the floor falls
                        // away, so the arrow always points up the slope.
                        const turn = windingOf(points) > 0 ? 1 : -1;
                        const rising = sector.floorSlope > 0 ? 1 : -1;
                        const intoX = (-spanZ / length) * turn * rising;
                        const intoZ = (spanX / length) * turn * rising;

                        const [tailX, tailY] = toScreen(
                            at,
                            (hinge.x + next.x) / 2,
                            (hinge.z + next.z) / 2,
                        );
                        const reach = Math.min(28, at.scale * 0.8);
                        const headX = tailX + intoX * reach;
                        const headY = tailY + intoZ * reach;

                        context.strokeStyle = COLORS.label;
                        context.lineWidth = 1;
                        context.beginPath();
                        context.moveTo(tailX, tailY);
                        context.lineTo(headX, headY);
                        context.stroke();

                        const wing = 4;
                        context.beginPath();
                        context.moveTo(headX, headY);
                        context.lineTo(
                            headX - intoX * wing + intoZ * wing,
                            headY - intoZ * wing - intoX * wing,
                        );
                        context.moveTo(headX, headY);
                        context.lineTo(
                            headX - intoX * wing - intoZ * wing,
                            headY - intoZ * wing + intoX * wing,
                        );
                        context.stroke();
                    }
                }
            });

            // The wiring, under the things so a line never hides the dot it
            // joins. Drawn from the middle of one to the middle of the other,
            // with a head near the far end so which way it runs is readable —
            // a line that does not say which end is the input is half a line.
            const middles = new Map(
                map.things.map((held) => [
                    held.slug,
                    toScreen(at, held.x, held.z),
                ]),
            );

            context.lineWidth = 1.5;
            context.strokeStyle = COLORS.line;

            // Which lines are worth showing: all of them while the wire tool
            // is out or the toggle is on, and otherwise only the ones running
            // in or out of whatever is picked, so wiring does not clutter a
            // level somebody is drawing rooms in.
            const pickedThing =
                state.current.thing === null
                    ? null
                    : (map.things[state.current.thing]?.slug ?? null);

            const showsEvery =
                state.current.showLines || state.current.tool === 'wire';

            for (const drawn of map.lines ?? []) {
                const from = middles.get(drawn.from);
                const to = middles.get(drawn.to);

                if (from === undefined || to === undefined) {
                    continue;
                }

                if (
                    !showsEvery &&
                    drawn.from !== pickedThing &&
                    drawn.to !== pickedThing
                ) {
                    continue;
                }

                context.beginPath();
                context.moveTo(from[0], from[1]);
                context.lineTo(to[0], to[1]);
                context.stroke();

                // The head, three quarters of the way along rather than at the
                // end, so it is not buried under the thing it points at.
                const angle = Math.atan2(to[1] - from[1], to[0] - from[0]);
                const headX = from[0] + (to[0] - from[0]) * 0.72;
                const headY = from[1] + (to[1] - from[1]) * 0.72;

                context.beginPath();
                context.moveTo(headX, headY);
                context.lineTo(
                    headX - Math.cos(angle - 0.4) * 8,
                    headY - Math.sin(angle - 0.4) * 8,
                );
                context.lineTo(
                    headX - Math.cos(angle + 0.4) * 8,
                    headY - Math.sin(angle + 0.4) * 8,
                );
                context.closePath();
                context.fillStyle = COLORS.line;
                context.fill();
            }

            // And the one being dragged out, from its thing to the pointer.
            const pulling = state.current.wiring;

            if (pulling !== null) {
                const from = middles.get(pulling.from);

                if (from !== undefined) {
                    const to = toScreen(at, pulling.at.x, pulling.at.z);

                    context.strokeStyle = COLORS.lineDragging;
                    context.setLineDash([5, 4]);
                    context.beginPath();
                    context.moveTo(from[0], from[1]);
                    context.lineTo(to[0], to[1]);
                    context.stroke();
                    context.setLineDash([]);
                }
            }

            // Things, so there is something to place rooms around.
            map.things.forEach((held, index) => {
                const [x, y] = toScreen(at, held.x, held.z);
                const isPicked = index === state.current.thing;
                const isPerson = held.kind === 'actor';

                context.fillStyle = isPerson ? COLORS.actor : COLORS.thing;
                context.beginPath();
                context.arc(x, y, isPicked ? 6 : 4, 0, Math.PI * 2);
                context.fill();

                if (isPicked) {
                    context.strokeStyle = COLORS.wallSelected;
                    context.lineWidth = 2;
                    context.beginPath();
                    context.arc(x, y, 9, 0, Math.PI * 2);
                    context.stroke();
                }

                // A person is a person; furniture is the size it is.
                if (!isPerson) {
                    context.strokeStyle = COLORS.thing;
                    context.lineWidth = 1;
                    context.save();
                    context.translate(x, y);
                    context.rotate((held.angle * Math.PI) / 180);

                    // How it is drawn, legible in plan without picking it up.
                    // A cross shows the planes it actually stands, at their
                    // real angles, so a row of them reads at a glance; a
                    // billboard shows a ring, because it has no angle of its
                    // own and turning it does nothing.
                    if (held.render === 'cross') {
                        const reach =
                            (Math.max(held.width, held.depth) / 2) * at.scale;
                        const planes = held.planeCount === 3 ? 3 : 2;

                        for (let plane = 0; plane < planes; plane++) {
                            const turn = (Math.PI * plane) / planes;

                            context.beginPath();
                            context.moveTo(
                                Math.cos(turn) * -reach,
                                Math.sin(turn) * -reach,
                            );
                            context.lineTo(
                                Math.cos(turn) * reach,
                                Math.sin(turn) * reach,
                            );
                            context.stroke();
                        }
                    } else if (held.render === 'billboard') {
                        context.beginPath();
                        context.arc(
                            0,
                            0,
                            (Math.max(held.width, held.depth) / 2) * at.scale,
                            0,
                            Math.PI * 2,
                        );
                        context.stroke();
                    } else {
                        context.strokeRect(
                            (-held.width / 2) * at.scale,
                            (-held.depth / 2) * at.scale,
                            held.width * at.scale,
                            held.depth * at.scale,
                        );
                    }

                    context.restore();
                }

                if (isPicked) {
                    context.fillStyle = COLORS.label;
                    context.font =
                        '11px ui-sans-serif, system-ui, -apple-system, sans-serif';
                    context.textAlign = 'center';
                    context.fillText(held.name, x, y - 14);
                }
            });

            // The box being swept out of empty space.
            const sweep = sweepRef.current;

            if (sweep !== null && over !== null) {
                const [fromX, fromY] = toScreen(at, sweep.x, sweep.z);
                const [toX, toY] = toScreen(at, over.x, over.z);

                context.fillStyle = COLORS.sweep;
                context.strokeStyle = COLORS.wallSelected;
                context.lineWidth = 1;
                context.setLineDash([4, 3]);
                context.fillRect(fromX, fromY, toX - fromX, toY - fromY);
                context.strokeRect(fromX, fromY, toX - fromX, toY - fromY);
                context.setLineDash([]);
            }

            // Where the player starts, and which way they face.
            const [spawnX, spawnY] = toScreen(at, map.spawn.x, map.spawn.z);
            const heading = (map.spawn.angle * Math.PI) / 180;

            context.strokeStyle = COLORS.spawn;
            context.fillStyle = COLORS.spawn;
            context.lineWidth = 2;
            context.beginPath();
            context.arc(spawnX, spawnY, 6, 0, Math.PI * 2);
            context.stroke();
            context.beginPath();
            context.moveTo(spawnX, spawnY);
            context.lineTo(
                spawnX + Math.sin(heading) * 16,
                spawnY - Math.cos(heading) * 16,
            );
            context.stroke();

            // The shape being drawn right now.
            if (pending.length > 0) {
                context.strokeStyle = COLORS.pending;
                context.lineWidth = 2;
                context.beginPath();

                pending.forEach((point, index) => {
                    const [x, y] = toScreen(at, point.x, point.z);

                    if (index === 0) {
                        context.moveTo(x, y);
                    } else {
                        context.lineTo(x, y);
                    }
                });

                if (over !== null && using === 'draw') {
                    const [x, y] = toScreen(at, over.x, over.z);
                    context.lineTo(x, y);
                }

                context.stroke();

                for (const point of pending) {
                    const [x, y] = toScreen(at, point.x, point.z);

                    context.fillStyle = COLORS.pending;
                    context.fillRect(x - 3, y - 3, 6, 6);
                }
            }

            // The cursor's snapped position, so it is clear where a click lands.
            if (over !== null && using !== 'select') {
                const [x, y] = toScreen(at, over.x, over.z);

                context.strokeStyle = COLORS.pending;
                context.lineWidth = 1;
                context.beginPath();
                context.arc(x, y, 4, 0, Math.PI * 2);
                context.stroke();
            }
        };

        const observer = new ResizeObserver(resize);
        observer.observe(holder);
        resize();
        frame = requestAnimationFrame(draw);

        return () => {
            cancelAnimationFrame(frame);
            observer.disconnect();
        };
    }, []);

    const at = (event: React.MouseEvent<HTMLCanvasElement>): Point | null => {
        const canvas = canvasRef.current;

        if (canvas === null || view === null) {
            return null;
        }

        const bounds = canvas.getBoundingClientRect();

        return toWorld(
            view,
            event.clientX - bounds.left,
            event.clientY - bounds.top,
        );
    };

    const cornerNear = (spot: Point): Point | null => {
        const reach = GRAB_PIXELS / (view?.scale ?? 1);

        for (const sector of level.sectors) {
            for (const point of sector.points) {
                if (Math.hypot(point.x - spot.x, point.z - spot.z) < reach) {
                    return { x: point.x, z: point.z };
                }
            }
        }

        return null;
    };

    const nearSpawn = (spot: Point): boolean =>
        Math.hypot(level.spawn.x - spot.x, level.spawn.z - spot.z) <
        GRAB_PIXELS / (view?.scale ?? 1);

    /**
     * Every wall under a spot, topmost room first. Two rooms sharing a wall
     * both turn up, because each owns its own side of it.
     */
    const edgesNear = (spot: Point): { sector: number; edge: number }[] => {
        const reach = GRAB_PIXELS / (view?.scale ?? 1);
        const found: { sector: number; edge: number }[] = [];

        for (let index = level.sectors.length - 1; index >= 0; index--) {
            const sector = level.sectors[index];

            for (let edge = 0; edge < sector.points.length; edge++) {
                const from = sector.points[edge];
                const to = sector.points[(edge + 1) % sector.points.length];

                if (distanceToEdge(from, to, spot) < reach) {
                    found.push({ sector: index, edge });
                }
            }
        }

        return found;
    };

    const edgeNear = (spot: Point): { sector: number; edge: number } | null =>
        edgesNear(spot)[0] ?? null;

    /** The room under a spot, topmost first, or null. */
    const roomAt = (spot: Point): number | null => {
        for (let index = level.sectors.length - 1; index >= 0; index--) {
            if (pointInSector(level.sectors[index], spot)) {
                return index;
            }
        }

        return null;
    };

    /** Picks whatever is under a spot: a wall first, since it sits in a room. */
    const selectAt = (spot: Point, adding = false): boolean => {
        onSelectThing(null);

        // Holding shift builds a set of rooms up, one at a time.
        if (adding) {
            const room = roomAt(spot);

            if (room !== null) {
                onToggleRoom(room);

                return true;
            }

            return false;
        }

        const walls = edgesNear(spot);

        if (walls.length > 0) {
            const held = walls.findIndex(
                (wall) =>
                    wall.sector === selection?.sector &&
                    wall.edge === selection?.edge,
            );

            // Clicking a shared wall again crosses to the room on the other
            // side, so each room can be given its own texture for it.
            const picked =
                held === -1
                    ? (walls.find(
                          (wall) => wall.sector === selection?.sector,
                      ) ?? walls[0])
                    : walls[(held + 1) % walls.length];

            onSelect(picked);
            onSelectRooms([picked.sector]);

            return true;
        }

        const room = roomAt(spot);

        if (room !== null) {
            onSelect({ sector: room, edge: null });
            onSelectRooms([room]);

            return true;
        }

        onSelect(null);
        onSelectRooms([]);

        return false;
    };

    const handleDown = (event: React.PointerEvent<HTMLCanvasElement>): void => {
        const spot = at(event);

        if (spot === null) {
            return;
        }

        event.currentTarget.setPointerCapture(event.pointerId);

        const snapped = { x: snap(spot.x, grid), z: snap(spot.z, grid) };

        if (tool === 'spawn') {
            onMoveSpawn(snapped);

            return;
        }

        if (tool === 'person' || tool === 'prop') {
            onPlaceThing(snapped);

            return;
        }

        if (tool === 'draw') {
            if (
                drawing.length > 2 &&
                Math.hypot(drawing[0].x - snapped.x, drawing[0].z - snapped.z) <
                    GRAB_PIXELS / (view?.scale ?? 1)
            ) {
                onCloseShape();
            } else {
                onAddCorner(snapped);
            }

            return;
        }

        // A corner is grabbed in case this turns into a drag, but the click is
        // still a click: every corner sits on two walls, so grabbing one must
        // not cost the chance to pick the wall it is an end of.
        const corner = cornerNear(spot);

        if (corner !== null) {
            setDragging(corner);
            setGrabbed(spot);
            setMoved(false);

            return;
        }

        // The start marker is drawn over the things, so it is grabbed before
        // them; a corner still wins, being the smaller target of the two.
        if (nearSpawn(spot)) {
            setDraggingSpawn(true);

            return;
        }

        const held = thingNear(level, spot, GRAB_PIXELS / (view?.scale ?? 1));

        // The wire tool turns every thing into a peg to pull a line off. It is
        // the only tool that does not move what it grabs, which is why it is a
        // tool rather than a modifier: dragging a thing and dragging a line out
        // of a thing are the same gesture, and one of them has to say so first.
        if (tool === 'wire') {
            if (held !== null) {
                setWiring({ from: level.things[held].slug, at: spot });
            } else {
                // Nothing under it, so the click is asking to cut whatever line
                // it landed on.
                const cut = lineNear(
                    level,
                    spot,
                    GRAB_PIXELS / (view?.scale ?? 1),
                );

                if (cut !== null) {
                    onCutLine(cut.from, cut.to);
                }
            }

            return;
        }

        if (held !== null) {
            onSelectThing(held);
            setDraggingThing(held);

            return;
        }

        // The middle button always pans, whatever is under it.
        if (event.button === 1) {
            setPanning({ x: event.clientX, y: event.clientY });

            return;
        }

        if (!selectAt(spot, event.shiftKey)) {
            // Empty space, so sweep a box out of it. Panning has moved to the
            // middle button, since the left one is busy gathering rooms up.
            setSweeping(spot);
        }
    };

    /** Double-clicking a wall puts a corner in it, on the wall itself. */
    const handleDoubleClick = (
        event: React.MouseEvent<HTMLCanvasElement>,
    ): void => {
        const spot = at(event);

        if (tool !== 'select' || spot === null || cornerNear(spot) !== null) {
            return;
        }

        const wall = edgeNear(spot);

        if (wall === null) {
            return;
        }

        const points = level.sectors[wall.sector].points;
        const from = points[wall.edge];
        const to = points[(wall.edge + 1) % points.length];

        // Snapped for a tidy corner, then put back on the wall so that adding
        // one never bends the room out of shape.
        onSplitEdge(
            from,
            to,
            closestOnEdge(from, to, {
                x: snap(spot.x, grid),
                z: snap(spot.z, grid),
            }),
        );
    };

    const handleMove = (event: React.PointerEvent<HTMLCanvasElement>): void => {
        const spot = at(event);

        if (spot === null) {
            return;
        }

        setPointer({ x: snap(spot.x, grid), z: snap(spot.z, grid) });

        if (wiring !== null) {
            setWiring({ ...wiring, at: spot });

            return;
        }

        if (draggingSpawn) {
            onMoveSpawn({ x: snap(spot.x, grid), z: snap(spot.z, grid) });

            return;
        }

        if (draggingThing !== null) {
            onMoveThing(draggingThing, {
                x: snap(spot.x, grid),
                z: snap(spot.z, grid),
            });

            return;
        }

        if (dragging !== null) {
            const to = { x: snap(spot.x, grid), z: snap(spot.z, grid) };

            if (!samePoint(dragging, to)) {
                onMoveCorner(dragging, to);
                setDragging(to);
                setMoved(true);
            }

            return;
        }

        if (sweeping !== null) {
            return;
        }

        if (panning !== null && view !== null) {
            setView({
                ...view,
                originX:
                    view.originX - (event.clientX - panning.x) / view.scale,
                originZ:
                    view.originZ - (event.clientY - panning.y) / view.scale,
            });
            setPanning({ x: event.clientX, y: event.clientY });
        }
    };

    const handleUp = (event: React.PointerEvent<HTMLCanvasElement>): void => {
        event.currentTarget.releasePointerCapture(event.pointerId);

        // A line lands on whatever thing it was let go over, and on nothing at
        // all otherwise: a line has two ends and both of them are things.
        if (wiring !== null) {
            const spot = at(event);

            const landed =
                spot === null
                    ? null
                    : thingNear(level, spot, GRAB_PIXELS / (view?.scale ?? 1));

            if (landed !== null && level.things[landed].slug !== wiring.from) {
                onDrawLine(wiring.from, level.things[landed].slug);
            }

            setWiring(null);

            return;
        }

        if (dragging !== null) {
            if (moved) {
                // Dropping a corner mid-wall has to split that wall, or the two
                // rooms touch without naming the same corners and each draws its
                // own.
                onDropCorner();
            } else if (grabbed !== null) {
                selectAt(grabbed, event.shiftKey);
            }
        }

        if (sweeping !== null && pointer !== null) {
            const box = boxBetween(sweeping, pointer);
            const swept = roomsWithin(level, box);

            // A sweep that caught nothing is a click on empty space, and clears.
            onSelectRooms(swept);
            onSelect(swept.length ? { sector: swept[0], edge: null } : null);
        }

        setDragging(null);
        setGrabbed(null);
        setMoved(false);
        setDraggingThing(null);
        setDraggingSpawn(false);
        setSweeping(null);
        setPanning(null);
    };

    // How much floor plan there is, for the readout: a carve that goes wrong
    // quietly leaves four rooms where one was expected.
    const extent = useMemo(() => boundsOf(level.sectors), [level.sectors]);

    const handleWheel = (event: React.WheelEvent<HTMLCanvasElement>): void => {
        if (view === null || canvasRef.current === null) {
            return;
        }

        const bounds = canvasRef.current.getBoundingClientRect();
        const pointerX = event.clientX - bounds.left;
        const pointerY = event.clientY - bounds.top;
        const before = toWorld(view, pointerX, pointerY);

        const scale = Math.min(
            Math.max(
                view.scale * (event.deltaY < 0 ? 1.1 : 1 / 1.1),
                MIN_SCALE,
            ),
            MAX_SCALE,
        );

        const after = toWorld({ ...view, scale }, pointerX, pointerY);

        setView({
            scale,
            originX: view.originX + (before.x - after.x),
            originZ: view.originZ + (before.z - after.z),
        });
    };

    return (
        <div ref={holderRef} className="relative h-full w-full">
            <canvas
                ref={canvasRef}
                onPointerDown={handleDown}
                onDoubleClick={handleDoubleClick}
                onPointerMove={handleMove}
                onPointerUp={handleUp}
                onWheel={handleWheel}
                className={
                    tool === 'select' ? 'cursor-default' : 'cursor-crosshair'
                }
            />
            <p className="pointer-events-none absolute bottom-2 left-3 text-[11px] text-slate-500">
                {level.sectors.length === 1
                    ? '1 room'
                    : `${level.sectors.length} rooms`}
                {level.sectors.length > 0 &&
                    ` · ${(extent.maxX - extent.minX).toFixed(1)} × ${(extent.maxZ - extent.minZ).toFixed(1)} m`}
                {canUndo && ' · ⌘Z undoes'}
            </p>
            <p className="pointer-events-none absolute right-3 bottom-2 text-[11px] text-slate-500">
                {pointer === null
                    ? 'scroll to zoom · middle-drag to pan · shift-click to add a room'
                    : `${pointer.x.toFixed(2)}, ${pointer.z.toFixed(2)} m`}
            </p>
        </div>
    );
}
