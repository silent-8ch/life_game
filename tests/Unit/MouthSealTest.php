<?php

use Symfony\Component\Process\Process;

/**
 * The wall beside a portal's mouth has to reach the top of it.
 *
 * A mouth covers the height of the room that owns it, and that room's floor can
 * sit well above the floor of the room on the other side of the wall — a
 * landing at the top of a staircase, over the room below it. The band between
 * the lower room's ceiling and the mouth's own floor belongs to neither, and
 * nothing used to be drawn there.
 *
 * That band is a hole in the level however you look at it, but it shows worst
 * through the portal itself: within `CLIP_MINIMUM` of a mouth the tilted near
 * plane is dropped, and the pane's camera then sees straight out through the
 * band — sky above and below the far room, exactly when the pane is hugged
 * across the whole screen and there is nothing else to look at.
 */

/**
 * @return array<string, mixed>
 */
function sealAnswer(string $body): array
{
    $script = <<<JS
        const blank = () => ({
            width: 0,
            height: 0,
            style: {},
            addEventListener() {},
            removeEventListener() {},
            getContext: () => null,
        });

        globalThis.document = { createElementNS: blank, createElement: blank };

        const THREE = await import('three');
        const { buildLevel } = await import('@/lib/engine/build-level.ts');
        const { createTextureLibrary } = await import('@/lib/engine/textures.ts');

        const corner = (x, z, extra = {}) => ({
            x, z, blocks: false, wallTexture: null, isMirror: false,
            isSky: false, portalLink: null, ...extra,
        });

        const room = (slug, points, floorHeight, ceilingHeight) => ({
            slug, name: slug, floorHeight, ceilingHeight,
            floorTexture: null, ceilingTexture: null, wallTexture: null,
            isSky: false, isWater: false, points,
        });

        const level = {
            slug: 'test', name: 'Test', description: '',
            spawn: { x: 1, z: 1, angle: 0 }, ceilingHeight: 3,
            spriteStyle: 'realistic', playerSprite: 'paul',
            wallColor: '#ffffff', floorColor: '#888888', accentColor: '#ffcc00',
            sky: null, things: [],
            sectors: [
                // A landing high up, whose south wall is the mouth.
                room('landing', [
                    corner(0, 0, { blocks: true, portalLink: 'hop' }),
                    corner(4, 0),
                    corner(4, 2),
                    corner(0, 2),
                ], 4.8, 8.6),
                // The room underneath it, well below the mouth's own floor.
                room('below', [
                    corner(0, -6),
                    corner(4, -6),
                    corner(4, 0),
                    corner(0, 0, { blocks: true }),
                ], 0, 3.8),
                // The far end of the portal, off on its own.
                room('far', [
                    corner(100, 0),
                    corner(104, 0, { portalLink: 'hop' }),
                    corner(104, 4),
                    corner(100, 4),
                ], 4.8, 8.6),
            ],
        };

        const built = buildLevel(level, createTextureLibrary());
        built.group.updateMatrixWorld(true);

        /** Every band of surface standing on the mouth's own wall. */
        const bandsOnTheMouth = () => {
            const bands = [];

            built.group.traverse((node) => {
                if (node.isMesh !== true) return;

                const box = new THREE.Box3().setFromObject(node);

                // Standing in the wall's own plane at z = 0 — thin that way —
                // and within the mouth's width. The rooms' side walls run away
                // from it and are somebody else's business.
                if (box.max.z - box.min.z > 0.1) return;
                if (box.min.z > 0.1 || box.max.z < -0.1) return;
                if (box.min.x < -0.1 || box.max.x > 4.1) return;
                if (box.max.y - box.min.y < 0.05) return;

                bands.push({
                    from: Number(box.min.y.toFixed(2)),
                    to: Number(box.max.y.toFixed(2)),
                    pane: node.material.type === 'ShaderMaterial',
                });
            });

            return bands.sort((a, b) => a.from - b.from);
        };

        {$body}
        JS;

    $process = new Process([
        'node',
        '--experimental-strip-types',
        '--import',
        './tests/js/typescript-imports.mjs',
        '--input-type=module',
        '--eval',
        $script,
    ], dirname(__DIR__, 2));

    $process->mustRun();

    return json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR);
}

it('leaves no band open between the lower room and the top of the mouth', function (): void {
    $answer = sealAnswer(<<<'JS'
        const bands = bandsOnTheMouth();
        let reach = bands.length ? bands[0].from : 0;
        const holes = [];

        for (const band of bands) {
            if (band.from > reach + 1e-3) holes.push([reach, band.from]);
            reach = Math.max(reach, band.to);
        }

        process.stdout.write(JSON.stringify({ bands, holes, reach }));
        JS);

    // The room below runs 0 to 3.8 and the mouth 4.8 to 8.6. Before the wall
    // was carried up, the metre between them was open air.
    expect($answer['holes'])->toBe([])
        ->and($answer['reach'])->toEqual(8.6);
});

it('still draws only one surface on that wall, not two stacked', function (): void {
    $answer = sealAnswer(<<<'JS'
        const bands = bandsOnTheMouth();

        process.stdout.write(JSON.stringify({
            walls: bands.filter((band) => !band.pane).map((b) => [b.from, b.to]),
            panes: bands.filter((band) => band.pane).map((b) => [b.from, b.to]),
        }));
        JS);

    // One wall from the room below, carried to the top of the mouth, and the
    // pane over the mouth itself. Two walls in the same plane would fight.
    expect($answer['walls'])->toEqual([[0, 8.6]])
        ->and($answer['panes'])->toEqual([[4.8, 8.6]]);
});
