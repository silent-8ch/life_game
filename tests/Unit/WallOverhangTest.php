<?php

use Symfony\Component\Process\Process;

/**
 * How far past its own ends a wall is drawn.
 *
 * Every wall is nudged a hair into its own room so that the two faces of a
 * shared wall do not fight over which is in front. That nudge pulls a corner
 * apart, so a wall is drawn a little past each end to close it again. Where a
 * wall carries straight on instead of turning there is no corner to close, and
 * the overhang put two faces in the same plane — a strip two centimetres wide
 * and the whole height of the wall, flickering as the player moved. Level 8 had
 * fifty of them, some fifteen metres tall.
 */

/**
 * Builds a level in the engine and answers a question about the geometry.
 *
 * @param  string  $sectors  A JavaScript array of rooms.
 * @return array<string, mixed>
 */
function builtLevel(string $sectors, string $body): array
{
    $script = <<<JS
        // Enough of a browser for the texture loader to do nothing quietly.
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
            x,
            z,
            blocks: false,
            wallTexture: null,
            isMirror: false,
            isSky: false,
            portalLink: null,
            ...extra,
        });

        const room = (slug, points, extra = {}) => ({
            slug,
            name: slug,
            floorHeight: 0,
            ceilingHeight: 3,
            floorTexture: null,
            ceilingTexture: null,
            wallTexture: null,
            isSky: false,
            isWater: false,
            points,
            ...extra,
        });

        const level = {
            slug: 'test',
            name: 'test',
            description: '',
            spawn: { x: 1, z: 1, angle: 0 },
            ceilingHeight: 3,
            spriteStyle: 'realistic',
            playerSprite: 'paul',
            wallColor: '#ffffff',
            floorColor: '#888888',
            accentColor: '#ffcc00',
            sky: null,
            things: [],
            sectors: {$sectors},
        };

        const built = buildLevel(level, createTextureLibrary());

        /** Every flat face the level draws, with the plane it lies in. */
        const faces = [];

        built.group.updateMatrixWorld(true);
        built.group.traverse((node) => {
            if (node.isMesh !== true) {
                return;
            }

            if (node.geometry?.type !== 'PlaneGeometry') {
                return;
            }

            const normal = new THREE.Vector3(0, 0, 1)
                .transformDirection(node.matrixWorld)
                .normalize();
            const centre = new THREE.Vector3().setFromMatrixPosition(node.matrixWorld);

            faces.push({
                width: node.geometry.parameters.width,
                height: node.geometry.parameters.height,
                normal,
                centre,
                box: new THREE.Box3().setFromObject(node),
                offset: normal.dot(centre),
            });
        });

        /** Pairs of faces lying in the same plane whose faces overlap. */
        const inTheSamePlane = () => {
            const found = [];

            for (let i = 0; i < faces.length; i++) {
                for (let j = i + 1; j < faces.length; j++) {
                    const a = faces[i];
                    const b = faces[j];
                    const turn = a.normal.dot(b.normal);

                    if (Math.abs(turn) < 0.999) {
                        continue;
                    }

                    if (Math.abs(a.offset - b.offset * Math.sign(turn)) > 0.005) {
                        continue;
                    }

                    if (!a.box.intersectsBox(b.box)) {
                        continue;
                    }

                    const size = a.box.clone().intersect(b.box).getSize(new THREE.Vector3());
                    const area = Math.max(size.x * size.y, size.y * size.z, size.x * size.z);

                    if (area > 1e-4) {
                        found.push({ area: Number(area.toFixed(4)) });
                    }
                }
            }

            return found;
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

it('draws a wall past the corners it turns', function (): void {
    $answer = builtLevel(
        "[room('only', [corner(0, 0), corner(10, 0), corner(10, 10), corner(0, 10)])]",
        <<<'JS'
        process.stdout.write(JSON.stringify({
            widths: faces.map((face) => Number(face.width.toFixed(3))).sort((a, b) => a - b),
        }));
        JS
    );

    // Four sides of ten metres, each drawn a centimetre past both of its ends.
    expect($answer['widths'])->toEqual([10.02, 10.02, 10.02, 10.02]);
});

it('stops a wall where another one carries straight on', function (): void {
    $answer = builtLevel(
        // The same square, with an extra corner halfway up its east side.
        "[room('only', [corner(0, 0), corner(10, 0), corner(10, 5), corner(10, 10), corner(0, 10)])]",
        <<<'JS'
        process.stdout.write(JSON.stringify({
            widths: faces.map((face) => Number(face.width.toFixed(3))).sort((a, b) => a - b),
            clashes: inTheSamePlane(),
        }));
        JS
    );

    // The two halves of the east side are five metres each and are drawn past
    // their outer ends only: 5.01, not 5.02. Nothing overlaps.
    expect($answer['widths'])->toEqual([5.01, 5.01, 10.02, 10.02, 10.02])
        ->and($answer['clashes'])->toBe([]);
});

it('stops a wall where the room next door carries it on', function (): void {
    $answer = builtLevel(
        // Two rooms stacked, so their east walls are one straight run split
        // between them — which is what carving a level leaves behind.
        "[
            room('lower', [corner(0, 0), corner(10, 0), corner(10, 5), corner(0, 5)]),
            room('upper', [corner(0, 5), corner(10, 5), corner(10, 10), corner(0, 10)]),
        ]",
        <<<'JS'
        process.stdout.write(JSON.stringify({ clashes: inTheSamePlane() }));
        JS
    );

    // A room only knows its own corners, so this is the one the first attempt at
    // the fix missed: the wall that carries on belongs to somebody else.
    expect($answer['clashes'])->toBe([]);
});

it('still closes the notch a nudged corner leaves', function (): void {
    $answer = builtLevel(
        "[room('only', [corner(0, 0), corner(10, 0), corner(10, 10), corner(0, 10)])]",
        <<<'JS'
        // Where two walls turn a corner, each is nudged a centimetre into the
        // room and away from the other. Whatever the overhang is, it has to
        // reach at least that far or there is daylight in the corner.
        const south = faces.find((face) => Math.abs(face.normal.z - 1) < 0.01);
        const east = faces.find((face) => Math.abs(face.normal.x + 1) < 0.01);

        // How far each one reaches along the other's plane, past x = 10 and
        // past z = 0.
        process.stdout.write(JSON.stringify({
            southReaches: Number((south.box.max.x - 10).toFixed(4)),
            eastReaches: Number((east.box.min.z - 0).toFixed(4)),
            southNudged: Number((south.centre.z - 0).toFixed(4)),
            eastNudged: Number((10 - east.centre.x).toFixed(4)),
        }));
        JS
    );

    expect($answer['southReaches'])->toBeGreaterThanOrEqual($answer['eastNudged'])
        ->and($answer['eastReaches'])->toBeLessThanOrEqual(-$answer['southNudged']);
});
