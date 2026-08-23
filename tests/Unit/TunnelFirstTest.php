<?php

use Symfony\Component\Process\Process;

/**
 * Which pane the frame's depth is spent on first.
 *
 * A pane that can see itself is a tunnel — the demo's loop portal is a corridor
 * with no end — and a tunnel is the one thing in the renderer made entirely of
 * depth. The draw budget is finite, so the order it is spent in decides what
 * runs out, and in plain array order a mirror three rooms away took the bounces
 * the corridor needed. The end of the corridor then showed the sky: 72 px of it
 * at `portals-loop-wide`, and none once the order changed.
 *
 * What has to hold is narrow and worth pinning exactly, because the temptation
 * when reading this is to think something is being skipped: **the same panes
 * are visited, in a different order.** Drop one and a mirror stops being
 * redrawn, which is the frozen-reflection bug this engine has already had once.
 */

/**
 * @return array<string, mixed>
 */
function tunnelOrder(string $body): array
{
    $script = <<<JS
        const { tunnelFirst } = await import('@/lib/engine/reflections.ts');

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

it('brings a pane that can see itself to the front', function (): void {
    $answer = tunnelOrder(<<<'JS'
        process.stdout.write(JSON.stringify({
            order: tunnelFirst(['mirror', 'far-mirror', 'loop', 'turn'], 'loop'),
        }));
        JS);

    expect($answer['order'])->toBe(['loop', 'mirror', 'far-mirror', 'turn']);
});

it('keeps every pane, and each of them once', function (): void {
    $answer = tunnelOrder(<<<'JS'
        const panes = ['a', 'b', 'c', 'd', 'e'];
        const order = tunnelFirst(panes, 'd');

        process.stdout.write(JSON.stringify({
            // The set is what matters: a pane dropped here is a mirror that
            // stops being redrawn for this view and freezes.
            same: [...order].sort().join(',') === [...panes].sort().join(','),
            length: order.length,
        }));
        JS);

    expect($answer['same'])->toBeTrue()
        ->and($answer['length'])->toBe(5);
});

it('leaves the array alone when the pane is already first', function (): void {
    $answer = tunnelOrder(<<<'JS'
        const panes = ['loop', 'mirror'];

        process.stdout.write(JSON.stringify({
            // Returned as it came, rather than copied: every pane runs this on
            // every bounce of every frame.
            untouched: tunnelFirst(panes, 'loop') === panes,
        }));
        JS);

    expect($answer['untouched'])->toBeTrue();
});

it('leaves the array alone when the pane is not a tunnel at all', function (): void {
    $answer = tunnelOrder(<<<'JS'
        // The ordinary case, and the common one: a portal pair takes its
        // partner out of its own view, so it never appears in its own list.
        const panes = ['mirror', 'other-mirror'];

        process.stdout.write(JSON.stringify({
            untouched: tunnelFirst(panes, 'loop') === panes,
            order: tunnelFirst(panes, 'loop'),
        }));
        JS);

    expect($answer['untouched'])->toBeTrue()
        ->and($answer['order'])->toBe(['mirror', 'other-mirror']);
});
