<?php

use Symfony\Component\Process\Process;

/**
 * Photographing a game that is being played.
 *
 * The three pictures a ticket carries have to come out of a session that was
 * never started in debug mode, which is the whole difficulty. Reading a frame
 * off the canvas needs `preserveDrawingBuffer`, and that is a **context
 * attribute** — WebGL reads it once when the context is made and nothing can
 * change it later. So there is no switching into capture mode for one frame:
 * either every session pays for it forever, or the pictures are drawn again
 * somewhere else. They are drawn again somewhere else.
 *
 * What is testable without a GPU is the part that has actually been wrong
 * before. A render target comes back **linear and bottom-up**; a canvas wants
 * **encoded for display and top-down**. The scan harness already shipped that
 * bug once and wrote it down — a backdrop check that decoded as `room-2` and
 * `room-3` — so it is worth a test rather than a comment. The other half is
 * that a capture must hand the player's own level back to them afterwards:
 * `paintWalls` assigns over every material and keeps no way back, and a player
 * left in flat legend colours would be a worse fault than the one they stopped
 * to report.
 */

/**
 * @return array<string, mixed>
 */
function captureAnswer(string $body): array
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
        const {
            displayPixels,
            shotSize,
            rememberMaterials,
            restoreMaterials,
            wireframeScene,
            MAX_SHOT_PIXELS,
        } = await import('@/lib/engine/capture.ts');

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

it('turns the picture the right way up', function (): void {
    // Two rows of one pixel. Off the GPU the bottom row comes first; in the
    // picture it has to come last, or every screenshot is upside down.
    $answer = captureAnswer(<<<'JS'
        const pixels = new Uint8Array([
            255, 0, 0, 255,
            0, 0, 255, 255,
        ]);

        const out = displayPixels(pixels, 1, 2);

        process.stdout.write(JSON.stringify({
            first: [out[0], out[1], out[2]],
            second: [out[4], out[5], out[6]],
        }));
        JS);

    // Blue was the second row off the GPU, so it is the top row of the picture.
    expect($answer['first'])->toBe([0, 0, 255])
        ->and($answer['second'])->toBe([255, 0, 0]);
});

it('encodes the colours for display rather than handing back what the GPU held', function (): void {
    // Mid grey is the case that shows it: linear 128 is not display 128. Read
    // one as the other and every colour lands on the wrong legend entry.
    $answer = captureAnswer(<<<'JS'
        const out = displayPixels(new Uint8Array([128, 128, 128, 200]), 1, 1);

        process.stdout.write(JSON.stringify({
            colour: [out[0], out[1], out[2]],
            alpha: out[3],
        }));
        JS);

    expect($answer['colour'][0])->toBeGreaterThan(180)
        ->and($answer['colour'][0])->toBe($answer['colour'][1])
        ->and($answer['colour'][1])->toBe($answer['colour'][2]);

    // Alpha is not a colour. Encoding it would make every opaque pixel
    // slightly transparent.
    expect($answer['alpha'])->toBe(200);
});

it('leaves a picture the game could have drawn at the size it drew it', function (): void {
    // The game renders into a buffer a third of the canvas and upscales, so its
    // own buffer is small and is the honest size to report a fault at.
    $answer = captureAnswer(<<<'JS'
        process.stdout.write(JSON.stringify({
            small: shotSize(640, 360),
            cap: MAX_SHOT_PIXELS,
            huge: shotSize(8000, 4000),
        }));
        JS);

    expect($answer['small'])->toBe(['width' => 640, 'height' => 360]);

    // Brought under the server's limit, and by a whole step so the pixel grid
    // the whole look depends on survives.
    expect($answer['huge']['width'])->toBeLessThanOrEqual($answer['cap'])
        ->and($answer['huge']['height'])->toBeLessThanOrEqual($answer['cap'])
        ->and($answer['huge'])->toBe(['width' => 2000, 'height' => 1000]);
});

it('never asks for a picture with no pixels in it', function (): void {
    // A window one pixel tall is absurd but reachable by dragging, and a render
    // target of zero height throws rather than returning an empty picture.
    $answer = captureAnswer(<<<'JS'
        process.stdout.write(JSON.stringify({ sliver: shotSize(9000, 1) }));
        JS);

    expect($answer['sliver']['height'])->toBeGreaterThanOrEqual(1)
        ->and($answer['sliver']['width'])->toBeGreaterThanOrEqual(1);
});

it('gives the player their own level back after photographing it', function (): void {
    // The failure this exists to stop: somebody presses the report key and is
    // left standing in a level painted flat legend colours, with no way back.
    $answer = captureAnswer(<<<'JS'
        const scene = new THREE.Group();
        const mine = new THREE.MeshStandardMaterial({ color: 0x112233 });
        const mesh = new THREE.Mesh(new THREE.BoxGeometry(), mine);
        scene.add(mesh);

        const borrowed = rememberMaterials(scene);

        wireframeScene(scene);
        const during = mesh.material.wireframe === true;

        restoreMaterials(borrowed);

        process.stdout.write(JSON.stringify({
            during,
            back: mesh.material.uuid === mine.uuid,
            colour: mesh.material.color.getHex(),
        }));
        JS);

    expect($answer['during'])->toBeTrue()
        ->and($answer['back'])->toBeTrue()
        ->and($answer['colour'])->toBe(0x112233);
});

it('throws away the materials it made rather than leaking one per wall', function (): void {
    // Made fresh on every capture, one per surface. Left to the collector they
    // would leak a level's worth of GPU programs each time somebody reports
    // something, and a child reporting ten things in a session is the point.
    $answer = captureAnswer(<<<'JS'
        const scene = new THREE.Group();
        const mesh = new THREE.Mesh(
            new THREE.BoxGeometry(),
            new THREE.MeshStandardMaterial(),
        );
        scene.add(mesh);

        const borrowed = rememberMaterials(scene);
        wireframeScene(scene);

        let disposed = 0;
        const made = mesh.material;
        made.addEventListener('dispose', () => { disposed += 1; });

        restoreMaterials(borrowed);
        // Twice, because the capture restores once in its body and again in a
        // `finally`. The second must not dispose the player's own material.
        restoreMaterials(borrowed);

        process.stdout.write(JSON.stringify({
            disposed,
            keptMine: mesh.material.uuid === borrowed[0].material.uuid,
        }));
        JS);

    expect($answer['disposed'])->toBe(1)
        ->and($answer['keptMine'])->toBeTrue();
});
