import { cn } from '@/lib/utils';
import type { Hotspot, Scene } from '@/types';

type SceneStageProps = {
    scene: Scene;
    hotspots: Hotspot[];
    disabled: boolean;
    onHotspotClick: (hotspot: Hotspot) => void;
};

export default function SceneStage({
    scene,
    hotspots,
    disabled,
    onHotspotClick,
}: SceneStageProps) {
    return (
        <div
            className="relative aspect-video w-full overflow-hidden rounded-lg border border-white/10 bg-cover bg-center shadow-2xl"
            style={{
                backgroundColor: scene.backgroundColor,
                backgroundImage: scene.backgroundImage
                    ? `url(${scene.backgroundImage})`
                    : undefined,
            }}
        >
            {hotspots.map((hotspot) => (
                <button
                    key={hotspot.slug}
                    type="button"
                    disabled={disabled}
                    onClick={() => onHotspotClick(hotspot)}
                    title={hotspot.name}
                    aria-label={hotspot.name}
                    className={cn(
                        'group absolute rounded-md border border-dashed border-white/20 transition',
                        'hover:border-amber-300/80 hover:bg-amber-200/10 focus-visible:border-amber-300/80 focus-visible:outline-none',
                        disabled && 'cursor-wait opacity-60',
                    )}
                    style={{
                        left: `${hotspot.x}%`,
                        top: `${hotspot.y}%`,
                        width: `${hotspot.width}%`,
                        height: `${hotspot.height}%`,
                    }}
                >
                    <span className="pointer-events-none absolute -top-6 left-1/2 -translate-x-1/2 rounded bg-black/80 px-2 py-0.5 text-[11px] whitespace-nowrap text-amber-100 opacity-0 transition group-hover:opacity-100 group-focus-visible:opacity-100">
                        {hotspot.name}
                    </span>
                </button>
            ))}
        </div>
    );
}
