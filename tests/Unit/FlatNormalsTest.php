<?php

use Symfony\Component\Process\Process;

/**
 * Which way a floor and a ceiling face.
 *
 * Both are the same polygon laid at a different height, and both are made by
 * rotating it a quarter turn about x — which leaves a ceiling's normal pointing
 * up, exactly like a floor's. Nothing is lit yet and every surface is drawn
 * double-sided, so it costs nothing today and every ceiling in the level lights
 * as though it were the floor on the day anything is lit.
 *
 * The polygon must not move while the normal turns over. Keeping it where it is
 * and flipping the normal is a reflection rather than a rotation, and a
 * reflection moves the room, so the winding is reversed instead — which also
 * puts the front of the face underneath, where a ceiling drawn FrontSide will
 * want it.
 */

/**
 * @param  string  $sectors  A JavaScript array of rooms.
 * @return array<string, mixed>
 */
function flatsOf(string $sectors, string $body): array
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

        built.group.updateMatrixWorld(true);

        /**
         * Every flat the level drew: which room and height it was tagged with,
         * the way its normals point in the world, the way the front of its
         * first triangle faces, and where its corners are.
         */
        const flats = [];

        built.group.traverse((node) => {
            if (node.isMesh !== true || node.parent?.userData.flat === undefined) {
                return;
            }

            const { sector, height } = node.parent.userData.flat;
            const geometry = node.geometry;
            const normal = geometry.getAttribute('normal');
            const position = geometry.getAttribute('position');
            const index = geometry.getIndex();

            const world = new THREE.Vector3(
                normal.getX(0),
                normal.getY(0),
                normal.getZ(0),
            )
                .transformDirection(node.matrixWorld)
                .normalize();

            // Where the front of the surface points, worked out from the order
            // the first triangle is wound in rather than from what the normal
            // attribute claims.
            const at = (of) => {
                const point = new THREE.Vector3().fromBufferAttribute(position, of);

                return point.applyMatrix4(node.matrixWorld);
            };

            const a = at(index.getX(0));
            const b = at(index.getX(1));
            const c = at(index.getX(2));
            const wound = new THREE.Vector3()
                .subVectors(b, a)
                .cross(new THREE.Vector3().subVectors(c, a))
                .normalize();

            const round = (value) => Number(value.toFixed(6));

            flats.push({
                sector,
                height,
                normal: [round(world.x), round(world.y), round(world.z)],
                wound: [round(wound.x), round(wound.y), round(wound.z)],
                corners: Array.from({ length: position.count }, (_, of) => {
                    const point = at(of);

                    return [round(point.x), round(point.y), round(point.z)];
                }).sort(),
            });
        });

        /** The lids, which are tagged by room rather than by height. */
        const lids = built.skyLids.map((lid) => {
            const mesh = lid.mesh.children[0];
            const normal = mesh.geometry.getAttribute('normal');

            const world = new THREE.Vector3(
                normal.getX(0),
                normal.getY(0),
                normal.getZ(0),
            )
                .transformDirection(mesh.matrixWorld)
                .normalize();

            return {
                room: lid.room,
                normal: [
                    Number(world.x.toFixed(6)),
                    Number(world.y.toFixed(6)),
                    Number(world.z.toFixed(6)),
                ],
            };
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

const ONE_ROOM = "[room('only', [corner(0, 0), corner(10, 0), corner(10, 10), corner(0, 10)])]";

it('points a floor up and a ceiling down', function (): void {
    $answer = flatsOf(ONE_ROOM, <<<'JS'
        process.stdout.write(JSON.stringify({
            flats: flats.map((flat) => ({
                height: flat.height,
                normal: flat.normal,
            })),
        }));
        JS);

    expect($answer['flats'])->toBe([
        ['height' => 0, 'normal' => [0, 1, 0]],
        ['height' => 3, 'normal' => [0, -1, 0]],
    ]);
});

it('winds a ceiling so its front face is the one underneath', function (): void {
    $answer = flatsOf(ONE_ROOM, <<<'JS'
        process.stdout.write(JSON.stringify({
            flats: flats.map((flat) => ({
                height: flat.height,
                normal: flat.normal,
                wound: flat.wound,
            })),
        }));
        JS);

    // The normal attribute and the winding have to agree, or a surface drawn
    // FrontSide is lit on the side you cannot see.
    foreach ($answer['flats'] as $flat) {
        expect($flat['wound'])->toBe($flat['normal']);
    }

    expect($answer['flats'][1]['wound'])->toBe([0, -1, 0]);
});

it('leaves the ceiling exactly where it was', function (): void {
    $answer = flatsOf(ONE_ROOM, <<<'JS'
        process.stdout.write(JSON.stringify({
            floor: flats[0].corners.map((corner) => [corner[0], corner[2]]),
            ceiling: flats[1].corners.map((corner) => [corner[0], corner[2]]),
            heights: flats.map((flat) => flat.corners.map((corner) => corner[1])),
        }));
        JS);

    // Turning a flat over by rotating it the other way would mirror the room in
    // z. The ceiling has to sit over the same floor plan as the floor, corner
    // for corner, with only the height differing.
    expect($answer['ceiling'])->toBe($answer['floor'])
        ->and($answer['heights'][0])->each->toEqual(0)
        ->and($answer['heights'][1])->each->toEqual(3);
});

it('turns the lid over a room open to the sky as well', function (): void {
    $answer = flatsOf(
        "[room('yard', [corner(0, 0), corner(10, 0), corner(10, 10), corner(0, 10)], { isSky: true })]",
        <<<'JS'
        process.stdout.write(JSON.stringify({ lids, flats: flats.length }));
        JS
    );

    // A lid paints nothing and so cannot be lit, but a surface that reports
    // which way it faces should not be the one that lies about it.
    expect($answer['lids'])->toBe([['room' => 'yard', 'normal' => [0, -1, 0]]])
        ->and($answer['flats'])->toBe(1);
});
