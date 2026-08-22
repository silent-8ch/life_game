<?php

use Symfony\Component\Process\Process;

/**
 * How far a level has to be seen across.
 *
 * `FAR_PLANE` is what an ordinary level needs and it is deliberately tight:
 * every wall is nudged a centimetre into its own room, and the further the far
 * plane goes the less depth there is left to tell two of them apart. But a
 * person a hundred metres tall had their head cut off by it, and somebody who
 * builds one would rather see all of them than keep the precision. So it opens
 * up exactly as far as the level asks and no further — which is a rule with two
 * ways to be wrong and, until this, nothing checking either.
 */

/**
 * @param  string  $level  A JavaScript object overriding the level's defaults.
 * @return array<string, mixed>
 */
function levelReach(string $level): array
{
    $script = <<<JS
        const { reachOf } = await import('@/lib/engine/view.ts');
        const constants = await import('@/lib/engine/constants.ts');

        const square = (across) => [
            { x: 0, z: 0 },
            { x: across, z: 0 },
            { x: across, z: across },
            { x: 0, z: across },
        ];

        const room = (extra = {}) => ({
            slug: 'only',
            name: 'only',
            floorHeight: 0,
            ceilingHeight: 3,
            floorTexture: null,
            ceilingTexture: null,
            wallTexture: null,
            isSky: false,
            isWater: false,
            points: square(10),
            ...extra,
        });

        const thing = (extra = {}) => ({
            slug: 'thing',
            kind: 'prop',
            width: 1,
            height: 2,
            depth: 1,
            x: 1,
            z: 1,
            elevation: 0,
            angle: 0,
            ...extra,
        });

        const built = { sectors: [room()], things: [], ...{$level} };

        process.stdout.write(JSON.stringify({
            reach: Number(reachOf(built).toFixed(3)),
            farPlane: constants.FAR_PLANE,
        }));
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

it('keeps the far plane tight for an ordinary level', function (): void {
    $answer = levelReach('{}');

    // A ten-metre room with a two-metre chair in it asks for nothing, and gets
    // the tight far plane, which is what keeps the depth buffer able to tell
    // two walls a centimetre apart.
    expect($answer['reach'])->toEqual($answer['farPlane']);
});

it('opens the far plane up for somebody a hundred metres tall', function (): void {
    $answer = levelReach('{ things: [thing({ height: 100 })] }');

    // Their head was being cut off. The far plane has to clear the tallest
    // thing with room over it, and 1.2x is that room.
    expect($answer['reach'])->toBeGreaterThan(120.0)
        ->and($answer['reach'])->toBeGreaterThan($answer['farPlane']);
});

it('opens up for a level that is simply wide', function (): void {
    $answer = levelReach('{ sectors: [room({ points: square(400) })] }');

    // Across the diagonal, not along a side: standing in one corner of a square
    // four hundred metres on a side, the far corner is five hundred and sixty
    // away.
    expect($answer['reach'])->toBeGreaterThan(560.0);
});

it('counts a tall ceiling as well as a tall person', function (): void {
    $flat = levelReach('{}');
    $tall = levelReach('{ sectors: [room({ ceilingHeight: 300 })] }');

    // A room three hundred metres to the ceiling is as much to see across as a
    // person that tall, and the same rule has to catch it.
    expect($tall['reach'])->toBeGreaterThan(300.0)
        ->and($flat['reach'])->toBeLessThan(300.0);
});
