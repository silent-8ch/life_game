<?php

use Symfony\Component\Process\Process;

/**
 * What tilting a camera's near plane onto a portal mouth does to its far plane.
 *
 * Lengyel's method replaces the third row of the projection outright, so the
 * far plane afterwards is `row4 - row3` and has nothing to do with the far
 * plane that was asked for. Work it through and it lands at
 *
 *     d / (d / far + bias * (1 - d / far) / 2)
 *
 * which for small `d` is `2d / bias` — a number that does not mention `far` at
 * all. So a level asking for a hundred metres of view got sixteen and a half
 * when the pane's camera stood five centimetres off a mouth, and everything
 * past that was clipped away and showed the background instead.
 *
 * Paul, on the demo: *it looks black where it should be a wall*. It was the
 * chamber's far wall, twenty-six metres from the pane's camera, at the end of a
 * sightline through the portal demo's long hall — and standing in that corridor
 * looking at the same doorway directly drew it correctly, which is what said
 * the fault was in the camera rather than in the room.
 */

/**
 * @return array<string, mixed>
 */
function obliqueClip(string $body): array
{
    $script = <<<JS
        const THREE = await import('three');
        const { tiltNearPlaneOnto, biasFor } = await import(
            '@/lib/engine/portal-surface.ts'
        );
        const { FIELD_OF_VIEW, NEAR_PLANE } = await import(
            '@/lib/engine/constants.ts'
        );

        const scratch = {
            plane: new THREE.Plane(),
            clip: new THREE.Vector4(),
            corner: new THREE.Vector4(),
        };

        /**
         * How far ahead the far plane ends up, for a camera at the origin
         * looking down -z with the mouth's plane `off` metres in front of it.
         *
         * Read straight off the matrix rather than by drawing anything: the far
         * plane is `row4 - row3`, and on the view axis that is one division.
         */
        const farPlaneAt = (off, far, bias) => {
            const camera = new THREE.PerspectiveCamera(
                FIELD_OF_VIEW,
                894 / 502,
                NEAR_PLANE,
                far,
            );
            camera.updateProjectionMatrix();

            const projection = camera.projectionMatrix.clone();

            tiltNearPlaneOnto(
                projection,
                new THREE.Plane(new THREE.Vector3(0, 0, -1), -off),
                new THREE.Matrix4(),
                scratch,
                bias,
            );

            const at = projection.elements;

            return -(-(0 - at[14]) / (-1 - at[10]));
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

it('drags the far plane in towards the camera when the bias is fixed', function (): void {
    $answer = obliqueClip(<<<'JS'
        const fixed = [0.05, 0.07, 0.15, 0.5, 2].map((off) => ({
            off,
            far: Number(farPlaneAt(off, 100, 0.005).toFixed(1)),
        }));

        process.stdout.write(JSON.stringify({ fixed }));
        JS);

    // The number this test exists to keep visible. A hundred metres asked for,
    // sixteen and a half delivered, at the range a player walking into a portal
    // spends every crossing in.
    expect($answer['fixed'][0])->toBe(['off' => 0.05, 'far' => 16.7])
        ->and($answer['fixed'][1])->toBe(['off' => 0.07, 'far' => 21.9]);

    // And it comes back as the camera backs off, which is why the fault had a
    // band rather than being on all the time — measured in the portal demo as
    // black at seven centimetres and clean by fifteen, because the chamber's
    // far wall is twenty-six metres away.
    expect($answer['fixed'][2]['far'])->toBeGreaterThan(26.0)
        ->and($answer['fixed'][4]['far'])->toBeGreaterThan(85.0);
});

it('keeps the far plane where the level asked for it', function (): void {
    $answer = obliqueClip(<<<'JS'
        const { PANE_CLEARANCE } = await import('@/lib/engine/constants.ts');

        // Every distance from touching the mouth out to well clear of it, at
        // the two view distances levels actually ask for.
        const held = [];

        for (const far of [100, 400]) {
            for (const off of [0.05, 0.06, 0.07, 0.1, PANE_CLEARANCE, 0.3, 1, 5, 20]) {
                held.push({
                    far,
                    off,
                    bias: Number(biasFor(off, far).toFixed(6)),
                    reached: Number(farPlaneAt(off, far, biasFor(off, far)).toFixed(1)),
                });
            }
        }

        process.stdout.write(JSON.stringify({
            held,
            kept: Number(
                Math.min(...held.map((one) => one.reached / one.far)).toFixed(4),
            ),
        }));
        JS);

    // Nine tenths of the asked-for distance, everywhere, which is the trade
    // FAR_KEPT states. It cannot be ten tenths: rearranged for the bias, the
    // identity says the far plane sits at `far` only when the bias is exactly
    // zero, and a bias of zero is what the nudge exists to avoid. So the choice
    // is not whether to pay but how much, and this is the receipt.
    expect($answer['kept'])->toBeGreaterThanOrEqual(0.9);

    // The bias only ever shrinks. Making it larger than it was would trade the
    // far plane back for seam margin, which is the wrong way round.
    expect(collect($answer['held'])->pluck('bias')->max())->toBeLessThanOrEqual(0.005);
});
