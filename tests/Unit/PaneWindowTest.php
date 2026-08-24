<?php

use Symfony\Component\Process\Process;

/**
 * A pass draws the window its picture will be read through, and reads it back.
 *
 * This is the sampling identity the whole renderer rests on, so it is worth
 * having in numbers rather than in reasoning. Get the crop wrong and every
 * reflection is offset or scaled — obviously wrong rather than subtly. Get the
 * read wrong the same way, and the two even cancel for the player's own view
 * while breaking every level below it.
 */

/**
 * @return array<string, mixed>
 */
function paneWindow(string $body): array
{
    $script = <<<JS
        const THREE = await import('three');
        const { buildMirrorPane } = await import('@/lib/engine/build/mirrors.ts');

        const scene = { group: new THREE.Group(), targets: [], mirrors: [] };
        const ctx = {
            scene,
            materials: { track: (what) => what },
            topology: { seenFrom: () => ['room'] },
        };

        const mirror = buildMirrorPane(
            ctx,
            { sector: { slug: 'room' } },
            new THREE.Vector3(0, 1.5, -4),
            new THREE.Vector3(0, 0, 1),
            8,
            3,
        );

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

it('crops a projection so the window fills the frame', function (): void {
    $answer = paneWindow(<<<'JS'
        const camera = new THREE.PerspectiveCamera(75, 1.6, 0.1, 200);
        camera.position.set(0, 1.5, 4);
        camera.updateMatrixWorld(true);
        camera.updateProjectionMatrix();

        // Off-centre and small, which is where a sign error shows. Wider than
        // NARROWEST in both directions so nothing is widened underneath us.
        const window = { left: 0.2, right: 0.6, bottom: -0.5, top: -0.1 };

        // The camera the pass is drawn with, caught mid-render — the crop is
        // applied to it in place and `aim` copies a fresh projection over the
        // top on the next call, so this is the only moment it can be read.
        let drawnWith = null;

        const renderer = {
            getDrawingBufferSize: (v) => v.set(1920, 1080),
            getRenderTarget: () => null,
            setRenderTarget: () => {},
            shadowMap: {},
            state: { buffers: { depth: { setMask: () => {} } } },
            autoClear: true,
            render: (scene, from) => {
                drawnWith = {
                    projection: from.projectionMatrix.clone(),
                    view: from.matrixWorldInverse.clone(),
                };
            },
        };

        // Where three points of the window land once it has been cropped. They
        // are unprojected through the UNCROPPED camera first, so each is a real
        // place in the world that sits at a known spot in the window.
        const uncropped = new THREE.PerspectiveCamera(75, 1.6, 0.1, 200);
        uncropped.position.copy(camera.position);
        uncropped.updateMatrixWorld(true);
        uncropped.projectionMatrix.copy(camera.projectionMatrix);
        uncropped.projectionMatrixInverse.copy(camera.projectionMatrixInverse);

        // The pane's own camera is the player's reflected, so the points have
        // to be measured against that rather than against the player's.
        const beyond = mirror.aim(camera);
        const beyondUncropped = new THREE.PerspectiveCamera(75, 1.6, 0.1, 200);
        beyondUncropped.matrixWorld.copy(beyond.matrixWorld);
        beyondUncropped.matrixWorldInverse.copy(beyond.matrixWorldInverse);
        beyondUncropped.projectionMatrix.copy(beyond.projectionMatrix);
        beyondUncropped.projectionMatrixInverse
            .copy(beyond.projectionMatrix)
            .invert();

        const spots = [
            ['middle', (window.left + window.right) / 2, (window.bottom + window.top) / 2],
            ['low left', window.left, window.bottom],
            ['high right', window.right, window.top],
        ];

        const markers = spots.map(([name, x, y]) => {
            const at = new THREE.Vector3(x, y, 0.5);
            at.unproject(beyondUncropped);

            return { name, at };
        });

        mirror.render(renderer, {}, camera, 0, window);

        const landed = markers.map(({ name, at }) => {
            const clip = at
                .clone()
                .applyMatrix4(drawnWith.view)
                .applyMatrix4(drawnWith.projection);

            return { name, x: Number(clip.x.toFixed(4)), y: Number(clip.y.toFixed(4)) };
        });

        process.stdout.write(JSON.stringify({ landed }));
        JS);

    // The middle of the window is the middle of the frame, and its corners are
    // the corners of the frame. That is the whole of what cropping means, and a
    // sign or a row index wrong here shows as every reflection sliding or
    // scaling rather than as anything subtle.
    expect($answer['landed'])->toEqual([
        ['name' => 'middle', 'x' => 0, 'y' => 0],
        ['name' => 'low left', 'x' => -1, 'y' => -1],
        ['name' => 'high right', 'x' => 1, 'y' => 1],
    ]);
});

it('never crops onto a window too narrow to divide by', function (): void {
    $answer = paneWindow(<<<'JS'
        let drawnWith = null;

        const renderer = {
            getDrawingBufferSize: (v) => v.set(1920, 1080),
            getRenderTarget: () => null,
            setRenderTarget: () => {},
            shadowMap: {},
            state: { buffers: { depth: { setMask: () => {} } } },
            autoClear: true,
            render: (scene, from) => {
                drawnWith = from.projectionMatrix.clone();
            },
        };

        const camera = new THREE.PerspectiveCamera(75, 1.6, 0.1, 200);
        camera.position.set(0, 1.5, 4);
        camera.updateMatrixWorld(true);
        camera.updateProjectionMatrix();

        // A chain grazing the very edge of a mirror. `narrow` hands back a
        // rectangle whenever two openings overlap at all, with no floor under
        // it, so this really does arrive here.
        const sliver = {
            left: 0.30000001,
            right: 0.30000002,
            bottom: -0.2,
            top: -0.19999999,
        };

        mirror.render(renderer, {}, camera, 0, sliver);

        const biggest = Math.max(
            ...drawnWith.elements.map((v) => Math.abs(v)),
        );

        process.stdout.write(JSON.stringify({
            biggest,
            finite: drawnWith.elements.every((v) => Number.isFinite(v)),
        }));
        JS);

    // Cropping divides by the window's span, and a span of a hundred-millionth
    // is a crop factor in the hundreds of millions. What comes out is a
    // projection whose numbers no longer mean anything, and the symptom is not
    // a wrong picture — it is the page ceasing to paint while its scripts carry
    // on. Paul: *the game crashed... looks like the game halts at some point.*
    // This engine has met the same failure once before, from pushing a clip
    // plane forward, and the note left then says the same thing.
    //
    // So a window is widened about its own middle before anything divides by
    // it. The pass then draws a little more than will be read, which costs
    // nothing.
    expect($answer['finite'])->toBeTrue()
        ->and($answer['biggest'])->toBeLessThan(1000);
});
