<?php

use Symfony\Component\Process\Process;

/**
 * The two unwritten rules holding collision together.
 *
 * Collision here is 2D only — a circle against segments and rotated boxes —
 * and there is no swept test. Nothing asks whether the path from where you were
 * to where you are crossed a wall; it only asks whether where you ended up is
 * inside one. That works exactly as long as a single frame's movement is
 * shorter than the player is wide, and nothing in the code enforces it.
 *
 * The symptom when it breaks is falling out of the level at speed, occasionally,
 * and it is miserable to diagnose from scratch. Somebody making the game feel
 * faster will trip it, so it is worth one assertion and a paragraph.
 *
 * The second rule is why `RESOLVE_PASSES` is 12 rather than 3: corners are
 * resolved by pushing out of one collider at a time, over and over, and two
 * walls at a sharp angle need several goes because leaving one pushes back into
 * the other. Each pass only halves what is left. At 3 the player settles inside
 * an acute corner — close enough for the camera's near plane to cut through the
 * wall, which is what it looked like when it was wrong.
 */

/**
 * @return array<string, mixed>
 */
function collisionAnswer(string $body): array
{
    $script = <<<JS
        const { moveWithCollisions } = await import('@/lib/engine/collision.ts');
        const constants = await import('@/lib/engine/constants.ts');

        const wall = (x1, z1, x2, z2) => ({ kind: 'segment', x1, z1, x2, z2 });

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

it('cannot move further in one frame than the player is wide', function (): void {
    $answer = collisionAnswer(<<<'JS'
        process.stdout.write(JSON.stringify({
            runSpeed: constants.RUN_SPEED,
            maxFrame: constants.MAX_FRAME_SECONDS,
            radius: constants.PLAYER_RADIUS,
        }));
        JS);

    $step = $answer['runSpeed'] * $answer['maxFrame'];
    $width = 2 * $answer['radius'];

    // If this fails, somebody has raised RUN_SPEED or MAX_FRAME_SECONDS without
    // raising PLAYER_RADIUS, and the player can now cross a wall inside a single
    // frame. Collision has no swept test to catch that: it only checks the spot
    // they landed on, and they landed on the far side.
    //
    // The fix is to raise PLAYER_RADIUS to suit, lower the frame ceiling, or
    // write a swept test — not to delete this assertion.
    expect($step)->toBeLessThan($width);
});

it('keeps the passes that stop the player sinking into an acute corner', function (): void {
    $answer = collisionAnswer(<<<'JS'
        /** How far a point sits from a wall, on the floor plan. */
        const distanceTo = (segment, at) => {
            const spanX = segment.x2 - segment.x1;
            const spanZ = segment.z2 - segment.z1;
            const length = spanX * spanX + spanZ * spanZ;
            const along = Math.max(
                0,
                Math.min(
                    1,
                    ((at.x - segment.x1) * spanX + (at.z - segment.z1) * spanZ) /
                        length,
                ),
            );

            return Math.hypot(
                at.x - (segment.x1 + along * spanX),
                at.z - (segment.z1 + along * spanZ),
            );
        };

        // A twelve-degree wedge, the sharpest corner a room is likely to have.
        const angle = (12 * Math.PI) / 180;
        const reach = 60;

        const walls = [
            wall(0, 0, reach, 0),
            wall(0, 0, reach * Math.cos(angle), reach * Math.sin(angle)),
        ];

        const radius = constants.PLAYER_RADIUS;

        // Swept rather than one shot, because the case that needs the passes is
        // not the obvious one. Shoving the player hard at the point pushes them
        // straight back out of the mouth and settles in a single pass; what
        // needs twelve is a *partial* move that stops them somewhere inside,
        // where leaving one wall pushes them into the other. Nothing short of
        // sweeping finds it, and a fixed shot silently tests nothing.
        let worst = Infinity;

        for (let startX = 0.5; startX <= 30; startX += 0.5) {
            for (const push of [0.5, 0.9, 1, 1.2, 2]) {
                const settled = moveWithCollisions(
                    { x: startX, z: (startX * Math.tan(angle)) / 2 },
                    -startX * push,
                    -(startX * Math.tan(angle)) / 4,
                    walls,
                    radius,
                );

                worst = Math.min(
                    worst,
                    ...walls.map((w) => distanceTo(w, settled)),
                );
            }
        }

        process.stdout.write(JSON.stringify({ radius, worst }));
        JS);

    // Measured across that sweep: 0.064 m at three passes, 0.154 m at twelve.
    // The threshold sits between them, so dropping RESOLVE_PASSES back to three
    // fails here rather than showing up as the player quietly standing inside
    // an acute corner with the near plane cutting through the wall.
    expect($answer['worst'])->toBeGreaterThan(0.12);

    // Worth saying plainly, because .ai/rules/engine.md claims otherwise: the
    // solver does *not* settle "no closer than 0.28 m to a wall even in a
    // 12-degree wedge". The worst case found here is 0.154 m, and at 3 degrees
    // it is 0.011 m. The rule's figure holds for the case it was measured on,
    // not in general. Raised on the task board rather than edited here, since
    // the rules file is shared.
    expect($answer['worst'])->toBeLessThan($answer['radius']);
});
