import { useEffect, useRef, useState } from 'react';
import type { Level } from '@/types';

/**
 * A section cut through the level, looking north: every room drawn at the
 * height it sits at, across the ground it covers. The selected room's floor and
 * ceiling can be dragged, which is the quickest way to build a step, a ledge or
 * a low doorway.
 */

type SideViewProps = {
    level: Level;
    selected: number | null;
    onChangeHeights: (floorHeight: number, ceilingHeight: number) => void;
};

const COLORS = {
    background: '#0c0f14',
    grid: '#1b2430',
    zero: '#334155',
    room: 'rgba(125, 211, 252, 0.12)',
    roomEdge: '#38607a',
    selected: 'rgba(251, 191, 36, 0.2)',
    selectedEdge: '#fbbf24',
    handle: '#fbbf24',
    label: 'rgba(226, 232, 240, 0.7)',
};

const PADDING = 26;

/** How close in pixels you have to be to grab a floor or ceiling. */
const GRAB_PIXELS = 8;

/** Heights snap to this, in metres. */
const HEIGHT_STEP = 0.1;

export default function SideView({
    level,
    selected,
    onChangeHeights,
}: SideViewProps) {
    const canvasRef = useRef<HTMLCanvasElement>(null);
    const holderRef = useRef<HTMLDivElement>(null);
    const [dragging, setDragging] = useState<'floor' | 'ceiling' | null>(null);

    const state = useRef({ level, selected });
    useEffect(() => {
        state.current = { level, selected };
    });

    /** World x and height to canvas pixels, for the level as it stands now. */
    const mapping = (
        map: Level,
        width: number,
        height: number,
    ): { x: (at: number) => number; y: (at: number) => number } => {
        const points = map.sectors.flatMap((sector) => sector.points);
        const minX =
            points.length > 0 ? Math.min(...points.map((p) => p.x)) : 0;
        const maxX =
            points.length > 0 ? Math.max(...points.map((p) => p.x)) : 1;

        const floors = map.sectors.map((sector) => sector.floorHeight);
        const ceilings = map.sectors.map((sector) => sector.ceilingHeight);
        const low = Math.min(0, ...floors) - 0.5;
        const high = Math.max(map.ceilingHeight, ...ceilings) + 0.5;

        return {
            x: (at) =>
                PADDING +
                ((at - minX) / Math.max(maxX - minX, 1)) *
                    (width - PADDING * 2),
            y: (at) =>
                height -
                PADDING -
                ((at - low) / Math.max(high - low, 1)) * (height - PADDING * 2),
        };
    };

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
        };

        const draw = (): void => {
            frame = requestAnimationFrame(draw);

            const { level: map, selected: picked } = state.current;
            const ratio = window.devicePixelRatio;
            const width = canvas.width / ratio;
            const height = canvas.height / ratio;

            context.setTransform(ratio, 0, 0, ratio, 0, 0);
            context.fillStyle = COLORS.background;
            context.fillRect(0, 0, width, height);

            const to = mapping(map, width, height);

            // Ground level, and a line every metre.
            const floors = map.sectors.map((sector) => sector.floorHeight);
            const ceilings = map.sectors.map((sector) => sector.ceilingHeight);
            const low = Math.floor(Math.min(0, ...floors) - 0.5);
            const high = Math.ceil(Math.max(map.ceilingHeight, ...ceilings));

            context.font =
                '10px ui-sans-serif, system-ui, -apple-system, sans-serif';
            context.textAlign = 'left';

            for (let metre = low; metre <= high; metre++) {
                const y = to.y(metre);

                context.strokeStyle = metre === 0 ? COLORS.zero : COLORS.grid;
                context.lineWidth = 1;
                context.beginPath();
                context.moveTo(PADDING - 6, y);
                context.lineTo(width - PADDING + 6, y);
                context.stroke();

                context.fillStyle = COLORS.label;
                context.fillText(`${metre}`, 4, y + 3);
            }

            map.sectors.forEach((sector, index) => {
                const xs = sector.points.map((point) => point.x);
                const left = to.x(Math.min(...xs));
                const right = to.x(Math.max(...xs));
                const top = to.y(sector.ceilingHeight);
                const bottom = to.y(sector.floorHeight);
                const chosen = picked === index;

                context.fillStyle = chosen ? COLORS.selected : COLORS.room;
                context.fillRect(left, top, right - left, bottom - top);

                context.strokeStyle = chosen
                    ? COLORS.selectedEdge
                    : COLORS.roomEdge;
                context.lineWidth = chosen ? 2 : 1;
                context.setLineDash(sector.isSky ? [4, 3] : []);
                context.strokeRect(left, top, right - left, bottom - top);
                context.setLineDash([]);

                context.fillStyle = COLORS.label;
                context.textAlign = 'center';
                context.fillText(sector.name, (left + right) / 2, bottom - 6);

                if (chosen) {
                    for (const y of [top, bottom]) {
                        context.fillStyle = COLORS.handle;
                        context.fillRect(left, y - 2, right - left, 4);
                    }
                }
            });
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

    const heightAt = (event: React.PointerEvent<HTMLCanvasElement>): number => {
        const canvas = canvasRef.current;

        if (canvas === null) {
            return 0;
        }

        const bounds = canvas.getBoundingClientRect();

        const floors = level.sectors.map((sector) => sector.floorHeight);
        const ceilings = level.sectors.map((sector) => sector.ceilingHeight);
        const low = Math.min(0, ...floors) - 0.5;
        const high = Math.max(level.ceilingHeight, ...ceilings) + 0.5;
        const y = event.clientY - bounds.top;

        const fraction =
            (bounds.height - PADDING - y) / (bounds.height - PADDING * 2);

        return (
            Math.round((low + fraction * (high - low)) / HEIGHT_STEP) *
            HEIGHT_STEP
        );
    };

    const handleDown = (event: React.PointerEvent<HTMLCanvasElement>): void => {
        if (selected === null || canvasRef.current === null) {
            return;
        }

        const sector = level.sectors[selected];
        const bounds = canvasRef.current.getBoundingClientRect();
        const to = mapping(level, bounds.width, bounds.height);
        const y = event.clientY - bounds.top;

        const nearFloor = Math.abs(y - to.y(sector.floorHeight)) < GRAB_PIXELS;
        const nearCeiling =
            Math.abs(y - to.y(sector.ceilingHeight)) < GRAB_PIXELS;

        if (!nearFloor && !nearCeiling) {
            return;
        }

        event.currentTarget.setPointerCapture(event.pointerId);
        setDragging(nearCeiling ? 'ceiling' : 'floor');
    };

    const handleMove = (event: React.PointerEvent<HTMLCanvasElement>): void => {
        if (dragging === null || selected === null) {
            return;
        }

        const sector = level.sectors[selected];
        const height = heightAt(event);

        if (dragging === 'floor') {
            onChangeHeights(
                Math.min(height, sector.ceilingHeight - 0.2),
                sector.ceilingHeight,
            );
        } else {
            onChangeHeights(
                sector.floorHeight,
                Math.max(height, sector.floorHeight + 0.2),
            );
        }
    };

    const handleUp = (event: React.PointerEvent<HTMLCanvasElement>): void => {
        event.currentTarget.releasePointerCapture(event.pointerId);
        setDragging(null);
    };

    return (
        <div ref={holderRef} className="relative h-full w-full">
            <canvas
                ref={canvasRef}
                onPointerDown={handleDown}
                onPointerMove={handleMove}
                onPointerUp={handleUp}
                className={
                    selected === null ? 'cursor-default' : 'cursor-ns-resize'
                }
            />
            <p className="pointer-events-none absolute top-2 right-3 text-[11px] text-slate-500">
                {selected === null
                    ? 'section · pick a room to change its heights'
                    : 'drag the highlighted floor or ceiling'}
            </p>
        </div>
    );
}
