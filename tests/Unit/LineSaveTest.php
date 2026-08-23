<?php

use Symfony\Component\Process\Process;

/**
 * What the save file is told about the wiring, and how little that is.
 *
 * The frame loop hands the whole list of written flags over sixty times a
 * second, and almost every one of those is the same list as last time. What
 * matters is that the same list costs nothing, and that a change costs exactly
 * one message per flag that changed — not one per flag, and not one per frame
 * for as long as it stays changed.
 */

/**
 * @return array<string, mixed>
 */
function lineSaves(string $body): array
{
    $script = <<<JS
        const { createLineSaver } = await import('@/lib/engine/line-saves.ts');

        /** Everything a saver sent, in order. */
        const sent = [];

        const send = (flag, on) => sent.push([flag, on]);

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

it('says nothing at all while nothing changes', function (): void {
    $answer = lineSaves(<<<'JS'
        const saver = createLineSaver(new Set(['lit']), send);

        // The flag the save already has, pushed for a hundred frames.
        for (let frame = 0; frame < 100; frame++) {
            saver.push(['lit']);
        }

        process.stdout.write(JSON.stringify({ sent }));
        JS);

    expect($answer['sent'])->toBe([]);
});

it('sends a flag once when it goes on, and once when it goes off', function (): void {
    $answer = lineSaves(<<<'JS'
        const saver = createLineSaver(new Set(), send);

        saver.push([]);
        saver.push(['lit']);
        saver.push(['lit']);
        saver.push(['lit']);
        saver.push([]);
        saver.push([]);

        process.stdout.write(JSON.stringify({ sent }));
        JS);

    expect($answer['sent'])->toBe([['lit', true], ['lit', false]]);
});

it('sends only the flag that moved when several are written', function (): void {
    $answer = lineSaves(<<<'JS'
        const saver = createLineSaver(new Set(['lit', 'open']), send);

        saver.push(['lit', 'open', 'alarm']);
        saver.push(['open', 'alarm']);

        process.stdout.write(JSON.stringify({ sent }));
        JS);

    expect($answer['sent'])->toBe([['alarm', true], ['lit', false]]);
});

it('starts from what the save already says rather than from nothing', function (): void {
    $answer = lineSaves(<<<'JS'
        // A level walked into with the lamp already lit. The first frame must
        // not announce it: the save knows, and a saver that told it anyway
        // would cost a request every time anybody opened a level.
        const saver = createLineSaver(new Set(['lit']), send);

        saver.push(['lit']);

        process.stdout.write(JSON.stringify({ sent }));
        JS);

    expect($answer['sent'])->toBe([]);
});
