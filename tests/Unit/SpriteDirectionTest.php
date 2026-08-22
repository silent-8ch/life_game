<?php

use Symfony\Component\Process\Process;

/**
 * Which drawing gets shown for each way round a body.
 *
 * The six sheets were not drawn to one order — Paul's diagonals run backwards
 * against Wade's, Krystal's cardinals backwards against Paul's — so each has a
 * table of its own, read off the artwork by eye with
 * public/sprite-directions.html. These are those readings, pinned so that a
 * tidy-up cannot quietly put everybody back to facing the wrong way.
 */

/**
 * @return array<int, array{sheet: string, row: int}>
 */
function directionsFor(string $sprite): array
{
    $script = <<<JS
        import { spriteDirection } from './resources/js/lib/engine/sprite-direction.ts';

        const directions = Array.from({ length: 8 }, (_, index) =>
            spriteDirection('{$sprite}', (index * Math.PI) / 4),
        );

        process.stdout.write(JSON.stringify(directions));
        JS;

    $process = new Process([
        'node',
        '--experimental-strip-types',
        '--input-type=module',
        '--eval',
        $script,
    ], dirname(__DIR__, 2));

    $process->mustRun();

    return json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR);
}

/**
 * A table written the way the sheets are talked about: `c` for a row of the
 * cardinal sheet, `d` for the diagonal one, in the order a viewer meets them
 * walking round the body from the front.
 */
function asOrder(string $sprite): string
{
    return collect(directionsFor($sprite))
        ->map(fn (array $at): string => ($at['mirror'] ? '~' : '')
            .($at['sheet'] === 'cardinal' ? 'c' : 'd')
            .$at['row'])
        ->implode(' ');
}

it('shows each person the way they were drawn', function (string $sprite, string $order): void {
    expect(asOrder($sprite))->toBe($order);
})->with([
    //                   0°  45°  90° 135° 180° 225° 270° 315°
    ['paul', 'c0 d3 c1 d2 c2 d1 c3 d0'],
    ['krystal', 'c0 d0 c3 d2 c2 d1 c1 d3'],
    ['luna', 'c0 d0 c3 d2 c2 d1 c1 d3'],
    ['wade', 'c0 ~d3 c1 d1 c2 d2 c3 d3'],
    ['luke', 'c0 ~d3 c1 d1 c2 d2 c3 d3'],
    ['william', 'c0 ~d3 c1 ~d2 c2 d2 c3 d3'],
]);

it('gives every way round the body a drawing of its own', function (string $sprite): void {
    $directions = directionsFor($sprite);

    expect($directions)->toHaveCount(8)
        ->and(collect($directions)->unique())->toHaveCount(8);

    foreach ($directions as $at) {
        expect($at)->toHaveKeys(['sheet', 'row', 'mirror'])
            ->and($at['row'])->toBeGreaterThanOrEqual(0)
            ->and($at['row'])->toBeLessThan(4);
    }
})->with(['paul', 'krystal', 'luna', 'wade', 'luke', 'william']);

it('only flips a drawing where the sheet has none of its own', function (string $sprite): void {
    // These sheets were generated, and some of their cells came back facing a
    // way that was already drawn, leaving another way round the body unpainted.
    // A flip stands in for those and nothing else: a flipped person leads with
    // the wrong foot, so it is worth knowing which ones are made up.
    $directions = collect(directionsFor($sprite));

    $flipped = $directions->filter(fn (array $at): bool => $at['mirror']);
    $drawn = $directions->reject(fn (array $at): bool => $at['mirror']);

    foreach ($flipped as $at) {
        // The drawing it is flipped from has to be one the sheet really has.
        $source = $drawn->first(
            fn (array $other): bool => $other['sheet'] === $at['sheet']
                && $other['row'] === $at['row']
        );

        expect($source)->not->toBeNull(
            "{$sprite} flips a drawing that is not used the right way round anywhere."
        );
    }

    // Paul, Krystal and Luna came back whole; nothing of theirs is made up.
    if (in_array($sprite, ['paul', 'krystal', 'luna'], strict: true)) {
        expect($flipped)->toBeEmpty();
    }
})->with(['paul', 'krystal', 'luna', 'wade', 'luke', 'william']);

it('keeps front and back on the cardinal sheet for everyone', function (string $sprite): void {
    // Whatever order a sheet runs in, straight on and straight away are the two
    // nobody has ever had trouble telling apart.
    $directions = directionsFor($sprite);

    expect($directions[0]['sheet'])->toBe('cardinal')
        ->and($directions[4]['sheet'])->toBe('cardinal')
        ->and($directions[2]['sheet'])->toBe('cardinal')
        ->and($directions[6]['sheet'])->toBe('cardinal');

    // And the four diagonals are the ones in between.
    foreach ([1, 3, 5, 7] as $index) {
        expect($directions[$index]['sheet'])->toBe('diagonal');
    }
})->with(['paul', 'krystal', 'luna', 'wade', 'luke', 'william']);

it('falls back rather than failing on a sheet nobody has read yet', function (): void {
    expect(directionsFor('nobody'))->toHaveCount(8)
        ->and(collect(directionsFor('nobody'))->unique())->toHaveCount(8);
});
