<?php

use Symfony\Component\Process\Process;

/**
 * How a thing that is not a person gets drawn.
 *
 * Before this every one of them was an opaque box wearing a tiling wall
 * texture, which crops a door to 45% of its own picture and cannot draw a
 * silhouette at all. Three modes now, and the rules that keep them honest:
 * cutout rather than blending, so nothing has to be sorted; a cross locked to
 * its own angle, so it costs nothing across forty portal passes; and a
 * billboard turned per drawing camera, which is tested in ReflectionsTest
 * because that is where the trap lives.
 */

/**
 * @param  string  $things  A JavaScript array of things.
 * @return array<string, mixed>
 */
function propsBuilt(string $things, string $body): array
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

        /**
         * A texture library that loads nothing and remembers what it was asked
         * for, so a test can see which folder a picture came from and which
         * frame is showing without a file existing.
         */
        const asked = [];

        const stub = (kind, name) => {
            const texture = new THREE.Texture();

            texture.name = kind + ':' + name;
            asked.push(texture.name);

            return texture;
        };

        const textures = {
            surface: (name) => (name === null ? null : stub('surface', name)),
            prop: (name, frame) =>
                name === null
                    ? null
                    : stub('prop', frame === undefined ? name : name + '-' + frame),
            water: () => new THREE.Texture(),
            useRenderer: () => undefined,
            tick: () => undefined,
            dispose: () => undefined,
        };

        const thing = (extra = {}) => ({
            slug: 'thing',
            name: 'thing',
            description: '',
            kind: 'fixture',
            sprite: null,
            behaviour: null,
            stats: null,
            speed: 0,
            texture: 'desk',
            render: 'box',
            planeCount: 2,
            uvMode: 'fit',
            textureAlt: null,
            altFlag: null,
            animationFrames: 1,
            animationFps: 8,
            x: 5,
            z: 5,
            elevation: 0,
            width: 1,
            height: 2,
            depth: 1,
            angle: 0,
            isSolid: false,
            interactions: [],
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
            sectors: [{
                slug: 'only',
                name: 'only',
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
                points: [
                    { x: 0, z: 0, blocks: true, wallTexture: null, isMirror: false, isSky: false, portalLink: null },
                    { x: 10, z: 0, blocks: true, wallTexture: null, isMirror: false, isSky: false, portalLink: null },
                    { x: 10, z: 10, blocks: true, wallTexture: null, isMirror: false, isSky: false, portalLink: null },
                    { x: 0, z: 10, blocks: true, wallTexture: null, isMirror: false, isSky: false, portalLink: null },
                ],
            }],
            things: {$things},
        };

        const built = buildLevel(level, textures);

        built.group.updateMatrixWorld(true);

        const round = (value) => Number(value.toFixed(4));

        /** Every mesh that belongs to a thing rather than to the room. */
        const propMeshes = [];

        built.group.traverse((node) => {
            if (node.isMesh === true && node.userData.thingSlug !== undefined) {
                propMeshes.push(node);
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

it('spaces a cross prop evenly and turns it by the thing’s own angle', function (): void {
    $answer = propsBuilt(
        "[thing({ slug: 'two', render: 'cross', planeCount: 2 }), thing({ slug: 'three', render: 'cross', planeCount: 3, x: 8, angle: 30 })]",
        <<<'JS'
        const turns = (slug) => propMeshes
            .filter((mesh) => mesh.userData.thingSlug === slug)
            .map((mesh) => round((mesh.rotation.y * 180) / Math.PI));

        process.stdout.write(JSON.stringify({
            two: turns('two'),
            three: turns('three'),
            threeHolder: round(
                (propMeshes.find((mesh) => mesh.userData.thingSlug === 'three')
                    .parent.rotation.y * 180) / Math.PI,
            ),
        }));
        JS
    );

    // Spaced over a half turn, not a whole one: a quad drawn on both sides is
    // the same quad turned round, so 2 planes are 0 and 90 rather than 0 and
    // 180. The thing's own angle is the holder's, so the planes stay evenly
    // spaced whatever it is turned to.
    expect($answer['two'])->toEqual([0, 90])
        ->and($answer['three'])->toEqual([0, 60, 120])
        ->and($answer['threeHolder'])->toEqual(-30);
});

it('cuts a prop out rather than blending it', function (): void {
    $answer = propsBuilt(
        "[thing({ slug: 'plant', render: 'cross' }), thing({ slug: 'sign', render: 'billboard', x: 7 }), thing({ slug: 'switch', render: 'box', uvMode: 'fit', x: 3 })]",
        <<<'JS'
        process.stdout.write(JSON.stringify({
            materials: propMeshes.map((mesh) => ({
                slug: mesh.userData.thingSlug,
                alphaTest: mesh.material.alphaTest,
                transparent: mesh.material.transparent,
                doubleSided: mesh.material.side === THREE.DoubleSide,
            })),
        }));
        JS
    );

    // Alpha testing writes depth and needs no sorting. Blending would put every
    // prop into a sort against every other prop and against the portal panes,
    // which is a class of bug not worth inviting for a leaf.
    foreach ($answer['materials'] as $material) {
        expect($material['alphaTest'])->toBe(0.5)
            ->and($material['transparent'])->toBeFalse()
            ->and($material['doubleSided'])->toBeTrue();
    }
});

it('reads a fitting prop from the props folder and a tiling one from the textures', function (): void {
    $answer = propsBuilt(
        "[thing({ slug: 'fitted', uvMode: 'fit', texture: 'door-front' }), thing({ slug: 'tiled', uvMode: 'tile', texture: 'brick', x: 8 })]",
        <<<'JS'
        process.stdout.write(JSON.stringify({ asked }));
        JS
    );

    // The two folders hold two different kinds of picture — a surface texture
    // is opaque, square and tiles; a prop carries a silhouette and never
    // repeats — and uvMode is exactly that distinction already written down.
    // So it decides the folder, which is what stops a box being drawn opaque
    // over art with holes in it and showing the holes as solid colour.
    expect($answer['asked'])->toContain('prop:door-front')
        ->and($answer['asked'])->toContain('surface:brick');
});

it('shows the alt picture only while its flag is set', function (): void {
    $answer = propsBuilt(
        "[thing({ slug: 'switch', texture: 'light-switch-off', textureAlt: 'light-switch-on', altFlag: 'kitchen-light' })]",
        <<<'JS'
        const showing = () => propMeshes[0].material.map.name;

        const before = showing();

        built.props.setFlags(new Set(['kitchen-light']));

        const on = showing();

        built.props.setFlags(new Set());

        process.stdout.write(JSON.stringify({ before, on, off: showing() }));
        JS
    );

    // The flag lives on the saved game, so a flipped switch survives a reload —
    // which is what anyone would expect and would otherwise be a bug report.
    expect($answer['before'])->toBe('prop:light-switch-off')
        ->and($answer['on'])->toBe('prop:light-switch-on')
        ->and($answer['off'])->toBe('prop:light-switch-off');
});

it('walks an animated prop through its frames', function (): void {
    $answer = propsBuilt(
        "[thing({ slug: 'tv', texture: 'tv-screen', animationFrames: 4, animationFps: 10 })]",
        <<<'JS'
        const showing = () => propMeshes[0].material.map.name;

        const seen = [showing()];

        // A tenth of a second each, so ten steps of a tenth walk it round and
        // back to where it started.
        for (let step = 0; step < 5; step++) {
            built.props.update(0.1);
            seen.push(showing());
        }

        process.stdout.write(JSON.stringify({ seen }));
        JS
    );

    // A file per frame rather than cells on a sheet — the same decision as the
    // hand poses, and for the same reason: another frame is another file and
    // nothing depends on the order they were cut in. The base name is never a
    // file, which is why `tv-screen.png` does not exist and is not missing.
    expect($answer['seen'])->toBe([
        'prop:tv-screen-1',
        'prop:tv-screen-2',
        'prop:tv-screen-3',
        'prop:tv-screen-4',
        'prop:tv-screen-1',
        'prop:tv-screen-2',
    ]);
});

it('leaves a tiling box tiling and lets a fitting one fill its faces', function (): void {
    $answer = propsBuilt(
        "[thing({ slug: 'counter', uvMode: 'tile', texture: 'wood', width: 6, height: 1, depth: 0.6 }), thing({ slug: 'door', uvMode: 'fit', texture: 'door-front', width: 0.9, height: 2, depth: 0.1, x: 8 })]",
        <<<'JS'
        const uvRange = (slug) => {
            const mesh = propMeshes.find(
                (each) => each.userData.thingSlug === slug,
            );
            const uv = mesh.geometry.getAttribute('uv');
            const values = [];

            for (let index = 0; index < uv.count; index++) {
                values.push(uv.getX(index), uv.getY(index));
            }

            return [round(Math.min(...values)), round(Math.max(...values))];
        };

        process.stdout.write(JSON.stringify({
            counter: uvRange('counter'),
            door: uvRange('door'),
        }));
        JS
    );

    // A box comes out of three with its UVs already running 0..1 across each
    // face, which is what `fit` means — so fitting is the absence of work, and
    // only tiling has anything to do. Six metres of counter at two metres a
    // tile is three repeats; a door fills itself exactly once.
    expect($answer['door'])->toEqual([0, 1])
        ->and($answer['counter'][1])->toEqual(3);
});
