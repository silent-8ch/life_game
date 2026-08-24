<?php

use Symfony\Component\Process\Process;

/**
 * The room again, standing where a mirror's reflection of it is.
 *
 * A chain of reflections has to stop somewhere, and where it stops the mirror
 * comes out of the picture — a pane cannot show a level nobody drew without
 * showing a picture taken from the wrong viewpoint, which is the one thing this
 * renderer will not do. What is behind it then is the wall it hangs on. Paul,
 * after everything else was fixed: *no black mirrors or stretching, only bare
 * walls where mirrors should be.*
 *
 * Neither of the two obvious answers works. Going deeper does not: the levels
 * can be pushed until the openings close on their own — twenty-three in his
 * eight-metre room, measured — and there is still a wall at the end, a little
 * smaller. Fading the far end out does, and he has ruled it out twice: a mirror
 * that loses light is not what he asked for.
 *
 * So the room goes there instead. **A mirror's image of a room is a real
 * place** — the method of images, the same fact the mirror camera itself is
 * built from — so a reflected copy of the room's own geometry, standing where
 * that image is, is not a stand-in for the continuation. It *is* the
 * continuation, correct from every camera at every depth, and it costs no
 * passes at all.
 */

/**
 * @return array<string, mixed>
 */
function mirrorImages(string $body): array
{
    $script = <<<JS
        const THREE = await import('three');
        const { buildMirrorPane } = await import('@/lib/engine/build/mirrors.ts');
        const { buildMirrorImages } = await import('@/lib/engine/build/images.ts');

        const scene = {
            group: new THREE.Group(),
            targets: [],
            mirrors: [],
            skyLids: [],
            drawnByRoom: new Map(),
        };
        const ctx = {
            scene,
            materials: { track: (what) => what },
            topology: { seenFrom: () => ['room'] },
        };

        /** Something for the room to be made of, at a known place. */
        const slab = (name, x, z) => {
            const mesh = new THREE.Mesh(
                new THREE.PlaneGeometry(1, 1),
                new THREE.MeshBasicMaterial(),
            );
            mesh.name = name;
            mesh.position.set(x, 1.5, z);
            mesh.updateMatrixWorld(true);
            scene.group.add(mesh);
            scene.drawnByRoom.set('room', [
                ...(scene.drawnByRoom.get('room') ?? []),
                mesh,
            ]);

            return mesh;
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

it('stands a copy of the room where the mirror reflects it to', function (): void {
    $answer = mirrorImages(<<<'JS'
        // A wall at z = -4 facing into the room, and something two metres in
        // front of it. Its reflection belongs two metres behind: z = -6.
        slab('marker', 0, -2);

        const mirror = buildMirrorPane(
            ctx,
            { sector: { slug: 'room' } },
            new THREE.Vector3(0, 1.5, -4),
            new THREE.Vector3(0, 0, 1),
            8,
            3,
        );

        buildMirrorImages(ctx);

        const image = mirror.image[0];
        const copy = image.children.find((what) => what.name === 'marker');
        const at = new THREE.Vector3().setFromMatrixPosition(copy.matrixWorld);

        process.stdout.write(JSON.stringify({
            images: mirror.image.length,
            children: image.children.length,
            hidden: image.visible === false,
            x: Number(at.x.toFixed(4)),
            y: Number(at.y.toFixed(4)),
            z: Number(at.z.toFixed(4)),
            // A reflection is left-handed, and three compensates for that from
            // the sign of the object's own matrixWorld determinant. If this is
            // ever positive the copy is not a reflection at all.
            handedness: Math.sign(copy.matrixWorld.determinant()),
            // Shared, not rebuilt: a room's image costs objects, not buffers.
            sharesGeometry: copy.geometry === scene.drawnByRoom.get('room')[0].geometry,
        }));
        JS);

    // Two metres in front of a wall at z = -4 reflects to two metres behind it.
    // Get this wrong and the room beyond the glass is not the room the glass
    // shows, which reads as a second room sliding about at the back of every
    // reflection — worse than the wall it replaced.
    expect((float) $answer['z'])->toBe(-6.0)
        ->and((float) $answer['x'])->toBe(0.0)
        ->and((float) $answer['y'])->toBe(1.5);

    // Hidden until a pass asks for it. Seen from inside the real room it is
    // behind the walls and mostly occluded, and "mostly" is not something to
    // leave in a renderer — a room open to the sky has sight-lines over its own
    // walls.
    expect($answer['hidden'])->toBeTrue();

    expect($answer['handedness'])->toBe(-1);
    expect($answer['sharesGeometry'])->toBeTrue();
    expect($answer['images'])->toBe(1);
});

it('leaves a sky lid out of the copy', function (): void {
    $answer = mirrorImages(<<<'JS'
        const lid = slab('lid', 0, 0);
        slab('floor', 0, -2);

        scene.skyLids.push({ mesh: lid, room: 'room' });

        const mirror = buildMirrorPane(
            ctx,
            { sector: { slug: 'room' } },
            new THREE.Vector3(0, 1.5, -4),
            new THREE.Vector3(0, 0, 1),
            8,
            3,
        );

        buildMirrorImages(ctx);

        process.stdout.write(JSON.stringify({
            names: mirror.image[0].children.map((what) => what.name),
        }));
        JS);

    // A lid paints nothing and writes depth, so it hides whatever is behind it
    // and shows the sky through instead. That is right over the room it belongs
    // to and wrong anywhere else: a copy of one hangs a hole in the middle of
    // the image, with everything past it cut away.
    expect($answer['names'])->toBe(['floor']);
});

it('gives a mirror no image when its room drew nothing', function (): void {
    $answer = mirrorImages(<<<'JS'
        const mirror = buildMirrorPane(
            ctx,
            { sector: { slug: 'room' } },
            new THREE.Vector3(0, 1.5, -4),
            new THREE.Vector3(0, 0, 1),
            8,
            3,
        );

        buildMirrorImages(ctx);

        process.stdout.write(JSON.stringify({
            images: mirror.image.length,
            added: scene.group.children.length,
        }));
        JS);

    // An empty group added to the scene is a draw call for nothing, every pass
    // it is shown in. There is no image to hang if the room drew nothing to
    // reflect.
    expect($answer['images'])->toBe(0);
});
