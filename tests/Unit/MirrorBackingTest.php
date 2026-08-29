<?php

use Symfony\Component\Process\Process;

/**
 * What a mirror hangs on.
 *
 * A mirrored edge used to build a pane and return, so no wall was made at all.
 * In an ordinary room that is invisible — the pane is opaque and covers exactly
 * what the wall would have. In a room whose **every** edge is a mirror it means
 * there is no geometry at eye level anywhere in the level.
 *
 * That is what made Paul's mirrors black, and the shape of the fault says so.
 * Floor and ceiling seeded the reflections above and below the horizon and came
 * out perfect — an infinite checker floor, the sky, his own sprite repeating.
 * Along the horizon there was nothing but panes showing panes, and at the last
 * bounce those close into a loop with nothing outside it. Black is that loop's
 * fixed point: it starts black on the first frame and no number of frames fills
 * it in. Photographed at his own spot: 86 to 100 per cent of every pane's
 * middle row pure black, at every one of the seventeen levels.
 *
 * So the corridor ends on a real wall now, a centimetre behind the glass, and
 * `prepareReflections` takes the mirrors out of the picture at the last bounce
 * to show it.
 */

/**
 * @return array<string, mixed>
 */
function mirrorBacking(string $body): array
{
    $script = <<<JS
        const THREE = await import('three');
        const { buildMirrorPane } = await import('@/lib/engine/build/mirrors.ts');
        const { prepareReflections } = await import('@/lib/engine/reflections.ts');
        const { PORTAL_BOUNCES } = await import('@/lib/engine/constants.ts');

        const scene = { group: new THREE.Group(), targets: [], mirrors: [] };
        const ctx = {
            scene,
            materials: { track: (what) => what },
            topology: { seenFrom: () => ['room'] },
        };

        /** Two mirrors facing each other across a room, which is a corridor. */
        const wall = (z, facing) => {
            buildMirrorPane(
                ctx,
                { sector: { slug: 'room' } },
                new THREE.Vector3(0, 1.5, z),
                new THREE.Vector3(0, 0, facing),
                4,
                3,
            );

            return scene.mirrors[scene.mirrors.length - 1];
        };

        const near = wall(-4, 1);
        const far = wall(4, -1);

        for (const pane of scene.mirrors) {
            pane.partner = pane.mesh;
        }

        const shown = [];

        for (const pane of scene.mirrors) {
            // `aim` is the real one. It was stubbed to hand back a camera at
            // the world origin, which was harmless while recursion was decided
            // by a bounding sphere somebody had also stubbed — and is not now
            // that it is decided by where the panes actually land on screen. A
            // mirror camera parked at the origin cannot see the far wall, so
            // the corridor these two mirrors make ended after one bounce. It
            // does no drawing and needs no renderer; only `render` does.
            pane.viewerAt = () => ({ x: 0, z: 0, yaw: 0 });
            pane.render = (r, s, from, depth) => {
                shown.push({
                    drew: depth,
                    hidden: scene.mirrors.filter((o) => !o.mesh.visible).length,
                });
            };
            // `bounds` is the real one too, for the same reason: it is what
            // decides whether the player can see a pane at all, and a stub
            // sphere five metres down -z said yes to both walls of a corridor
            // running the other way.
        }

        const camera = new THREE.PerspectiveCamera(75, 1, 0.1, 100);
        camera.updateMatrixWorld(true);

        const noop = { object: { visible: false }, faceViewer() {}, follow() {} };

        const frame = prepareReflections(
            scene.mirrors,
            [],
            noop,
            noop,
            noop,
            camera,
        );

        // Enough frames for the depth to settle, because it is settled rather
        // than set. `reach` starts at two and needs `PATIENCE` frames under
        // budget for each level it climbs — deliberately slow, because moving
        // it shifts where every chain in the level ends at once and Paul saw
        // that as walls flickering at the back of every reflection. So a
        // handful of frames measures the ramp instead of the answer.
        for (let n = 0; n < 900; n++) {
            shown.length = 0;
            frame({ getDrawingBufferSize: (v) => v.set(64, 64) }, {});
        }

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

it('hands the wall builder the surface, so the wall can be hidden from it', function (): void {
    $answer = mirrorBacking(<<<'JS'
        const made = buildMirrorPane(
            ctx,
            { sector: { slug: 'room' } },
            new THREE.Vector3(0, 1.5, 8),
            new THREE.Vector3(0, 0, -1),
            4,
            3,
        );

        process.stdout.write(JSON.stringify({
            handedBack: made === scene.mirrors[scene.mirrors.length - 1],
            behindStartsEmpty: made.behind.length,
        }));
        JS);

    // A mirror's camera stands behind the glass and so does the wall it hangs
    // on, which puts the wall between that camera and the room it is meant to
    // be looking into. The tilted near plane is supposed to cut everything on
    // that side away and mostly does — but WALL_INSET is 0.01 and CLIP_BIAS is
    // 0.005, so the wall sits inside the slack and survives, filling the pane
    // with the back of itself. Paul, the moment it shipped: *i see mostly the
    // backing walls*.
    //
    // Only from this mirror's own view. Every other pane still sees it, which
    // is what makes the far end of the tunnel a wall rather than a hole.
    // This returned nothing before, so `buildWall` had no way to say "and this
    // is the wall you hang on". It matters because of where the two sit: a
    // mirror's camera stands behind the glass and so does the wall, which puts
    // the wall between that camera and the room it is meant to be looking into.
    // The tilted near plane should cut everything on that side away and mostly
    // does — but WALL_INSET is 0.01 and CLIP_BIAS is 0.005, so the wall lands
    // inside the slack and survives, filling the pane with the back of itself.
    // Paul, the moment it shipped: *i see mostly the backing walls*.
    //
    // `buildWall` puts it in `behind`, which hides it for that pane's own pass
    // and no other — every *other* pane still sees it, which is what makes the
    // far end of the tunnel a wall rather than a hole.
    expect($answer['handedBack'])->toBeTrue()
        ->and($answer['behindStartsEmpty'])->toBe(0);
});

it('says outright whether a surface is a mirror', function (): void {
    $answer = mirrorBacking(<<<'JS'
        process.stdout.write(JSON.stringify({
            mirrored: scene.mirrors.map((pane) => pane.mirrored),
        }));
        JS);

    // Read off `partner` before, which is a mesh doing two unrelated jobs and
    // only accidentally told the two kinds apart.
    expect($answer['mirrored'])->toBe([true, true]);
});

it('ends a mirror on the wall it hangs on at the last bounce', function (): void {
    $answer = mirrorBacking(<<<'JS'
        const deepest = Math.max(...shown.map((row) => row.drew));

        process.stdout.write(JSON.stringify({
            deepest,
            hiddenAtDeepest: shown
                .filter((row) => row.drew === deepest)
                .map((row) => row.hidden),
            panes: scene.mirrors.length,
        }));
        JS);

    // **A mirror that has run out of levels comes out of the picture**, and the
    // wall a centimetre behind it is drawn instead.
    //
    // Both of the other two endings have been shipped and both were wrong, in
    // opposite directions, and the reason is the same reason:
    //
    // - Showing the level above folds the image into itself, which sounds
    //   right and is a lie. That picture was drawn from a camera one
    //   reflection further out, and a pane samples its target by **screen
    //   position** — so it is pasted onto the wall registered to a viewpoint
    //   it was never taken from. Down a corridor of portals two adjacent
    //   viewpoints are nearly the same picture and it passes; between two
    //   mirrors at right angles it is a different room at the wrong angle.
    //   That is *super stretched*.
    //
    // - Hiding them, which is this, was shipped once before and Paul said *i
    //   am not seeing a seamless infinite room, i see many walls*. He was
    //   right and the walls were not the fault. The recursion was starved by
    //   the draw budget and ended at the first or second bounce, so the walls
    //   landed where he was looking.
    //
    // What changed is where the ending is, not what it is. `aperture.ts`
    // decides how deep a chain goes by how much of the opening is left rather
    // than by a purse, so a corridor now runs the full `PORTAL_BOUNCES` and the
    // wall lands sixteen reflections back at a few pixels across — which is
    // where the last bounce of a real infinity mirror is.
    //
    // The rule the whole renderer now keeps: **no pane ever shows a picture
    // taken from a camera other than the one looking at it.**
    expect($answer['deepest'])->toBeGreaterThan(1);

    foreach ($answer['hiddenAtDeepest'] as $hidden) {
        expect($hidden)->toBe($answer['panes']);
    }
});
