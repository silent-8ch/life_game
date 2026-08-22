<?php

use Symfony\Component\Process\Process;

/**
 * A room with no height in it draws one surface, not two.
 *
 * A floor and a ceiling are the same polygon laid at two heights. Where those
 * two heights are the same the polygons are the same surface, and two opaque
 * flats in one plane is a z-fight the size of the room: which one wins is
 * decided per pixel by the last bit of the depth value, so it changes as the
 * camera moves and the slab flashes between two textures.
 *
 * `buildWall` has refused a wall with no height between its ends since slopes
 * landed. Flats had no such guard. Level 8 carries two rooms left over from
 * carving — `room-11` and `room-12`, floor and ceiling both at 15 — hanging
 * fifteen metres above the edge of the yard, where a sky room's walls stop well
 * short of them and nothing hides the fight. Standing in the yard and looking up
 * is how it was reported.
 *
 * The guard has to survive slopes, which is the whole reason it asks the corners
 * rather than the two base heights: a room that pinches to nothing along its
 * hinge wall still has height at the far side and still needs its ceiling.
 */

/**
 * @param  string  $sectors  A JavaScript array of rooms.
 * @return array<string, mixed>
 */
function flatHeights(string $sectors): array
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

        built.group.updateMatrixWorld(true);

        /** Every flat drawn, by the room it belongs to and the height it sits at. */
        const flats = [];

        built.group.traverse((node) => {
            if (node.isMesh !== true || node.parent?.userData.flat === undefined) {
                return;
            }

            const { sector, height } = node.parent.userData.flat;

            // Where the surface actually ends up, not the base it was given.
            // Under a slope the base is the height along the hinge wall only, so
            // a floor and a ceiling can share a base and still be metres apart
            // across the room — comparing bases would call that a clash.
            const position = node.geometry.getAttribute('position');
            const point = new THREE.Vector3();
            let low = Infinity;
            let high = -Infinity;

            for (let of = 0; of < position.count; of++) {
                point.fromBufferAttribute(position, of).applyMatrix4(node.matrixWorld);
                low = Math.min(low, point.y);
                high = Math.max(high, point.y);
            }

            flats.push({
                sector,
                height: Number(height.toFixed(4)),
                low: Number(low.toFixed(4)),
                high: Number(high.toFixed(4)),
            });
        });

        /**
         * Pairs of flats in one room that occupy the same plane — the fight
         * itself, rather than a count that only stands in for it.
         */
        const fighting = [];

        for (let i = 0; i < flats.length; i++) {
            for (let j = i + 1; j < flats.length; j++) {
                if (
                    flats[i].sector === flats[j].sector &&
                    Math.abs(flats[i].low - flats[j].low) < 1e-4 &&
                    Math.abs(flats[i].high - flats[j].high) < 1e-4
                ) {
                    fighting.push(`\${flats[i].sector} @ \${flats[i].low}`);
                }
            }
        }

        process.stdout.write(JSON.stringify({
            flats: flats.map((flat) => `\${flat.sector} @ \${flat.height}`).sort(),
            fighting,
            skyLids: built.skyLids.map((lid) => lid.room).sort(),
        }));
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

it('draws one flat for a room whose ceiling sits on its floor', function (): void {
    $answer = flatHeights(
        "[room('flat-out', [corner(0, 0), corner(4, 0), corner(4, 4), corner(0, 4)], { floorHeight: 15, ceilingHeight: 15 })]"
    );

    // The floor stands: it is the one surface that is really there. What must
    // not happen is a second one in the same plane.
    expect($answer['flats'])->toEqual(['flat-out @ 15'])
        ->and($answer['fighting'])->toBe([]);
});

it('still draws both for an ordinary room', function (): void {
    $answer = flatHeights(
        "[room('ordinary', [corner(0, 0), corner(4, 0), corner(4, 4), corner(0, 4)])]"
    );

    expect($answer['flats'])->toEqual(['ordinary @ 0', 'ordinary @ 3'])
        ->and($answer['fighting'])->toBe([]);
});

it('still draws the ceiling of a room that pinches to nothing at one end', function (): void {
    // Hinged on the wall from (0,0) to (4,0), so the ceiling rises with z: it
    // meets the floor exactly along that wall and is two metres up at the far
    // side. Height nowhere is not the same as height somewhere.
    $answer = flatHeights(
        "[room('wedge', [corner(0, 0), corner(4, 0), corner(4, 4), corner(0, 4)], { floorHeight: 0, ceilingHeight: 0, ceilingSlope: 0.5, ceilingSlopeEdge: 0 })]"
    );

    expect($answer['flats'])->toHaveCount(2)
        ->and($answer['fighting'])->toBe([]);
});

it('gives a sky room with no height no lid either', function (): void {
    // A lid writes depth wherever it is seen from, so a spurious one hides
    // whatever is behind it across the whole footprint.
    $answer = flatHeights(
        "[room('yard', [corner(0, 0), corner(4, 0), corner(4, 4), corner(0, 4)], { isSky: true, floorHeight: 7, ceilingHeight: 7 })]"
    );

    expect($answer['skyLids'])->toBe([])
        ->and($answer['fighting'])->toBe([]);
});

it('gives an ordinary sky room its lid', function (): void {
    $answer = flatHeights(
        "[room('yard', [corner(0, 0), corner(4, 0), corner(4, 4), corner(0, 4)], { isSky: true, floorHeight: 0, ceilingHeight: 7 })]"
    );

    expect($answer['skyLids'])->toBe(['yard']);
});
