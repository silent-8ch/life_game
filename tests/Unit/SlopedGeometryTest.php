<?php

use Symfony\Component\Process\Process;

/**
 * What a sloped room is actually built out of.
 *
 * `SectorSlopeTest` pins the arithmetic; this pins the geometry that comes out
 * of it — that a floor's vertices sit on its plane, that a wall under a slope
 * is a trapezoid whose corners match the plane at the wall's own ends, that the
 * overhang closing a corner is extrapolated rather than left flat, and that a
 * wall which reaches nothing at one end is drawn as the triangle it is instead
 * of being skipped for having no height.
 */

/**
 * @param  string  $sectors  A JavaScript array of rooms.
 * @return array<string, mixed>
 */
function slopedLevel(string $sectors, string $body): array
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
        const { floorAt, ceilingAt } = await import('@/lib/engine/sectors.ts');

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
            floorSlope: 0,
            floorSlopeEdge: null,
            ceilingSlope: 0,
            ceilingSlopeEdge: null,
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

        const round = (value) => Number(value.toFixed(4));

        /** Every flat the level drew, as world-space corners. */
        const flats = [];
        /** Every wall face, as world-space corners. */
        const walls = [];

        built.group.traverse((node) => {
            if (node.isMesh !== true) {
                return;
            }

            const position = node.geometry.getAttribute('position');
            const points = [];

            for (let index = 0; index < position.count; index++) {
                points.push(
                    new THREE.Vector3()
                        .fromBufferAttribute(position, index)
                        .applyMatrix4(node.matrixWorld),
                );
            }

            const tag = node.parent?.userData;

            if (tag?.flat !== undefined) {
                flats.push({ ...tag.flat, points });
            }

            if (tag?.wall !== undefined) {
                walls.push({ ...tag.wall, points });
            }
        });

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

const A_RAMP = "[room('ramp', [corner(0, 0), corner(10, 0), corner(10, 10), corner(0, 10)], { floorSlope: 0.25, floorSlopeEdge: 0 })]";

it('lays every vertex of a sloped floor on its own plane', function (): void {
    $answer = slopedLevel(A_RAMP, <<<'JS'
        const floor = flats.find((flat) => flat.height === 0);
        const ramp = level.sectors[0];

        // Each corner of the drawn surface against what floorAt says the floor
        // is at that spot. If the displacement went in on the wrong axis, or
        // the group's own height was moved as well, these part company.
        const off = floor.points.map((point) =>
            round(point.y - floorAt(ramp, point.x, point.z)),
        );

        process.stdout.write(JSON.stringify({
            corners: floor.points.length,
            off,
            highest: round(Math.max(...floor.points.map((point) => point.y))),
            lowest: round(Math.min(...floor.points.map((point) => point.y))),
        }));
        JS);

    expect($answer['corners'])->toBeGreaterThan(2)
        ->and($answer['off'])->each->toEqual(0)
        // Ten metres in at a quarter each, and flat along the hinge.
        ->and($answer['highest'])->toEqual(2.5)
        ->and($answer['lowest'])->toEqual(0);
});

it('turns a wall under a slope into a trapezoid', function (): void {
    $answer = slopedLevel(A_RAMP, <<<'JS'
        const ramp = level.sectors[0];

        // The east wall, which runs from the hinge end to the far end and so
        // has a different floor at each of its ends.
        const east = walls.find((wall) => wall.index === 1);

        const bottoms = east.points
            .map((point) => round(point.y))
            .sort((a, b) => a - b);

        process.stdout.write(JSON.stringify({
            corners: east.points.length,
            heights: [...new Set(bottoms)],
            atFrom: round(floorAt(ramp, east.from.x, east.from.z)),
            atTo: round(floorAt(ramp, east.to.x, east.to.z)),
        }));
        JS);

    // Four corners at three distinct heights: the flat ceiling, and the floor
    // at each end of the wall. A rectangle would have two.
    expect($answer['corners'])->toBe(4)
        ->and($answer['atFrom'])->toEqual(0)
        ->and($answer['atTo'])->toEqual(2.5)
        ->and($answer['heights'])->toHaveCount(3);
});

it('extrapolates the overhang past the corner it closes', function (): void {
    $answer = slopedLevel(A_RAMP, <<<'JS'
        const east = walls.find((wall) => wall.index === 1);

        const lowest = Math.min(...east.points.map((point) => point.y));
        const highest = Math.max(
            ...east.points
                .filter((point) => point.y < 2.9)
                .map((point) => point.y),
        );

        process.stdout.write(JSON.stringify({
            lowest: round(lowest),
            highest: round(highest),
        }));
        JS);

    // Every wall is drawn a centimetre past each of its ends to close the notch
    // a corner leaves. Under a slope that centimetre has to carry the slope
    // with it: a quarter of a centimetre below the bottom end and above the
    // top. Left flat, the overhang pokes out through the neighbouring wall
    // exactly where the two are meant to meet.
    expect($answer['lowest'])->toEqual(-0.0025)
        ->and($answer['highest'])->toEqual(2.5025);
});

it('draws a wall that runs out of height as the triangle it is', function (): void {
    $answer = slopedLevel(
        // Two rooms sharing the wall at x = 10. The east room's floor climbs
        // away from that wall; the west room's is flat at head height. So the
        // step between them is nothing at one end of the shared wall and a
        // couple of metres at the other.
        <<<'JS'
        [
            room('west', [corner(0, 0), corner(10, 0), corner(10, 10), corner(0, 10)], {
                floorHeight: 2,
            }),
            room('east', [corner(10, 0), corner(20, 0), corner(20, 10), corner(10, 10)], {
                floorHeight: 0,
                floorSlope: 0.4,
                floorSlopeEdge: 3,
            }),
        ]
        JS,
        <<<'JS'
        // The east room's face of the shared wall: its step up to the west room.
        const shared = walls.filter((wall) => wall.beyond !== null);

        process.stdout.write(JSON.stringify({
            faces: shared.length,
            spans: shared.map((wall) => {
                const ys = wall.points.map((point) => round(point.y));

                return {
                    room: wall.sector,
                    low: round(Math.min(...ys)),
                    high: round(Math.max(...ys)),
                };
            }),
        }));
        JS
    );

    // The step is built and is not skipped for reaching zero height somewhere
    // along itself. Before slopes the whole wall was thrown away when its top
    // was not above its bottom, which is exactly what a Build staircase looks
    // like at one end.
    expect($answer['faces'])->toBeGreaterThan(0)
        ->and($answer['spans'])->not->toBeEmpty();

    foreach ($answer['spans'] as $span) {
        expect($span['high'])->toBeGreaterThan($span['low']);
    }
});
