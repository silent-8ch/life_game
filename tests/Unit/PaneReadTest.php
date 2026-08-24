<?php

use Symfony\Component\Process\Process;

/**
 * What a pane does to the picture it reads, and that it is nothing.
 *
 * A pane samples its target by where its fragment lands, so the read has to be
 * the identity: whatever the target holds at a point, the pane shows at that
 * same point. Anything else is a lens, and a lens applied once per level of a
 * tunnel is applied again inside itself, so whatever it does it does more of
 * the deeper you look.
 *
 * ## The one that was there
 *
 * The read used to be pulled towards the pane's own middle by up to a quarter,
 * sized in texels of the target, to keep it off the rim. That is a radial
 * shrink. It compounds down a chain, and it grows as the targets get smaller
 * with depth — so a corridor of two facing mirrors, which should vanish to a
 * point, instead bulged about one.
 *
 * Paul, from the middle of a four-mirror room looking almost straight down the
 * tunnel: *not sure how to describe the distortion i see, it is focused on with
 * my reticle. it looks like the shape is warped into a circle around a point.*
 * And, in the two-mirror room he built for the purpose: *the illusion of two
 * mirrors facing each other in a corridor should be a room that vanishes to
 * infinity. i see a distortion much sooner than the vanishing lines
 * converging.* Both are the signature of a scale error about a fixed point,
 * compounding.
 *
 * A clamp does the same job with no radial term: the target is cropped to
 * exactly the window the pane reads through, so straying past the rim is
 * straying outside nought to one, and stopping a texel short of the edge is the
 * whole of it.
 */

/**
 * The read a pane is set up to do, as a scale and an offset per axis.
 *
 * @return array<string, mixed>
 */
function paneRead(string $body): array
{
    $script = <<<JS
        const THREE = await import('three');
        const { buildMirrorPane } = await import('@/lib/engine/build/mirrors.ts');
        const { FAR_PLANE } = await import('@/lib/engine/constants.ts');

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

        /** Paul's 2-mirrors-oposite: 8 m across, two facing walls, a real ceiling. */
        const wall = (z, facing) =>
            buildMirrorPane(
                ctx,
                { sector: { slug: 'room' } },
                new THREE.Vector3(0, 1.5, z),
                new THREE.Vector3(0, 0, facing),
                8,
                3,
            );

        const near = wall(-4, 1);
        const far = wall(4, -1);

        const renderer = {
            getDrawingBufferSize: (v) => v.set(1920, 1080),
            getRenderTarget: () => null,
            setRenderTarget: () => {},
            shadowMap: {},
            state: { buffers: { depth: { setMask: () => {} } } },
            autoClear: true,
            render: () => {},
        };

        const camera = new THREE.PerspectiveCamera(75, 1.6, 0.05, FAR_PLANE);
        camera.rotation.order = 'YXZ';
        camera.position.set(-0.13, 1.6, 0.0014);
        camera.updateMatrixWorld(true);
        camera.updateProjectionMatrix();

        /** The read a pane is holding, as the shader would apply it. */
        const readOf = (pane) => ({
            scaleX: pane.mesh.material.uniforms.paneScale.value.x,
            scaleY: pane.mesh.material.uniforms.paneScale.value.y,
            offsetX: pane.mesh.material.uniforms.paneOffset.value.x,
            offsetY: pane.mesh.material.uniforms.paneOffset.value.y,
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

it('reads its target straight through, at every depth of a tunnel',
    function (): void {
        $answer = paneRead(<<<'JS'
            // A pass drawn through a window, then read back through that same
            // window. Whatever the target holds at a point, the pane must show
            // at that same point — so composing the two is the identity, and
            // stays the identity however small the window gets.
            const drift = [];

            for (let depth = 0; depth < 14; depth++) {
                // Windows shrink down a corridor. These are near enough the
                // measured ones for his room: about 0.55 at the first bounce,
                // falling away as one over the depth.
                const span = 0.55 / (depth + 1);
                const window = {
                    left: -span / 2,
                    right: span / 2,
                    bottom: -span / 2,
                    top: span / 2,
                };

                near.render(renderer, {}, camera, depth, window);
                near.show(depth, window);

                const read = readOf(near);

                // A mirror turns the picture left for right and does nothing
                // else, so the read is a scale of exactly minus one across and
                // plus one down, with the offsets that put nought on one.
                drift.push({
                    depth,
                    across: Number((read.scaleX + 1).toFixed(9)),
                    down: Number((read.scaleY - 1).toFixed(9)),
                    // Where the middle of the pass lands in the target: the
                    // middle, or the picture is being moved.
                    middleX: Number((0.5 * read.scaleX + read.offsetX).toFixed(9)),
                    middleY: Number((0.5 * read.scaleY + read.offsetY).toFixed(9)),
                });
            }

            process.stdout.write(JSON.stringify({
                worstScale: Math.max(...drift.map((d) => Math.max(Math.abs(d.across), Math.abs(d.down)))),
                worstMiddle: Math.max(...drift.map((d) => Math.max(Math.abs(d.middleX - 0.5), Math.abs(d.middleY - 0.5)))),
            }));
            JS);

        // Exactly one, and exactly the middle, at every depth. Not close — a scale
        // of 0.99 per level is a third of the picture gone by fourteen levels,
        // which is the fault this test exists for, and it would pass any tolerance
        // loose enough to be comfortable.
        expect($answer['worstScale'])->toBe(0)
            ->and($answer['worstMiddle'])->toBe(0);
    });

it('holds the read off the rim without bending it', function (): void {
    $answer = paneRead(<<<'JS'
        const window = { left: -0.2, right: 0.2, bottom: -0.2, top: 0.2 };

        near.render(renderer, {}, camera, 6, window);
        near.show(6, window);

        const texels = near.mesh.material.uniforms.paneTexels.value;
        const bias = near.mesh.material.uniforms.edgeBias.value;

        process.stdout.write(JSON.stringify({
            // How far in from the target's edge the read is held, as a
            // fraction of the target.
            insetX: Number((bias / (texels.x * 2)).toFixed(6)),
            texelsAcross: texels.x * 2,
        }));
        JS);

    // The rim guard is an inset from the target's own edge, so it costs a texel
    // and a half wherever the pane happens to be and however deep it is. The
    // thing it replaced cost a *fraction* of the picture, which is what made it
    // compound.
    expect($answer['insetX'])->toBeGreaterThan(0.0)
        ->and($answer['insetX'] * $answer['texelsAcross'])->toBeGreaterThan(1.0)
        ->and($answer['insetX'] * $answer['texelsAcross'])->toBeLessThan(3.0);
});
