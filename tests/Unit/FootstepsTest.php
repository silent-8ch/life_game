<?php

use Symfony\Component\Process\Process;

/**
 * When a foot lands, and what it lands on.
 *
 * Footsteps are counted in metres rather than in seconds, off the same tally
 * that picks the sprite's walk frame and swings the hands. That is the whole
 * design, and it is arithmetic on one number — so everything that could go
 * wrong with it can be checked here without a speaker: a foot falling twice for
 * one step, a foot lost across a long frame, or a burst of them the moment the
 * player is carried back to where they started.
 *
 * The sound coming out is not testable headlessly and is not tested. There is
 * no audio device in CI, `Audio` does not exist in node, and a stub would only
 * assert that the stub was called.
 */

/**
 * @return array<string, mixed>
 */
function stepsAnswer(string $body): array
{
    $script = <<<JS
        const { createPace, surfaceOf, STEP_METRES, SURFACE_SOUNDS } =
            await import('@/lib/engine/audio.ts');

        /** How many feet land over a straight walk of this many metres. */
        const overWalk = (metres, step = 0.05) => {
            const pace = createPace();
            let fell = 0;

            for (let walked = 0; walked <= metres + 1e-9; walked += step) {
                fell += pace.advance(walked);
            }

            return fell;
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

it('lands a foot every half stride, whatever the frame rate', function (): void {
    $answer = stepsAnswer(<<<'JS'
        process.stdout.write(JSON.stringify({
            step: STEP_METRES,
            // The same hundred-metre walk taken in longer and shorter frames.
            // Distance is what counts, so the answer must not move.
            hundred: overWalk(100),
            crawling: overWalk(100, 0.005),
            stuttering: overWalk(100, 0.24),
        }));
        JS);

    // Half of hands.ts's STRIDE of 1.1: two feet to a full swing of the arms.
    expect($answer['step'])->toEqual(0.55);

    // A hundred metres is a hundred divided by half a stride, give or take the
    // one at either end depending on where in the swing the walk started.
    expect($answer['hundred'])->toBeGreaterThanOrEqual((int) floor(100 / 0.55))
        ->and($answer['hundred'])->toBeLessThanOrEqual((int) ceil(100 / 0.55) + 1);

    // And it is the same number of feet however the frames fell.
    expect($answer['crawling'])->toBe($answer['hundred'])
        ->and($answer['stuttering'])->toBe($answer['hundred']);
});

it('lands a foot once for one step, however often it is asked', function (): void {
    $answer = stepsAnswer(<<<'JS'
        const pace = createPace();

        // Somewhere in the middle of a walk, so nothing depends on where in
        // the swing it started.
        pace.advance(5);

        // Asked again and again from exactly where it stands. A frame in which
        // nobody moved is not a step.
        const standing = [pace.advance(5), pace.advance(5), pace.advance(5)];

        // Creeping forward a tenth of a step at a time: one foot lands, once,
        // and no sooner than a tenth of the way and no later than all of it.
        let walked = 5;
        let creeps = 0;
        let fell = 0;

        while (fell === 0 && creeps < 100) {
            walked += STEP_METRES / 10;
            creeps += 1;
            fell = pace.advance(walked);
        }

        process.stdout.write(JSON.stringify({ standing, creeps, fell }));
        JS);

    expect($answer['standing'])->toBe([0, 0, 0])
        ->and($answer['fell'])->toBe(1)
        ->and($answer['creeps'])->toBeGreaterThanOrEqual(1)
        ->and($answer['creeps'])->toBeLessThanOrEqual(11);
});

it('does not fire a burst when the player is carried back to the start', function (): void {
    $answer = stepsAnswer(<<<'JS'
        const pace = createPace();

        // Twenty metres of walking, then the tally is zeroed — which is what
        // the viewport does when a wizard recalls to their mark.
        for (let walked = 0; walked <= 20; walked += 0.05) {
            pace.advance(walked);
        }

        const arriving = pace.advance(0);
        const stillThere = pace.advance(0);

        // Walking on from there starts counting again from nothing.
        const afterHalfAStride = pace.advance(STEP_METRES);

        process.stdout.write(JSON.stringify({
            arriving,
            stillThere,
            afterHalfAStride,
            reset: (() => {
                const fresh = createPace();
                fresh.advance(20);
                fresh.reset();

                return [fresh.advance(0), fresh.advance(STEP_METRES)];
            })(),
        }));
        JS);

    expect($answer['arriving'])->toBe(0)
        ->and($answer['stillThere'])->toBe(0)
        ->and($answer['afterHalfAStride'])->toBe(1)
        // reset() puts it back where a fresh one starts: nothing owed, and the
        // next half stride is the next foot.
        ->and($answer['reset'])->toBe([0, 1]);
});

it('walks differently on grass, planks and tile, and on anything else at all', function (): void {
    $answer = stepsAnswer(<<<'JS'
        const of = (name) => surfaceOf(name);

        process.stdout.write(JSON.stringify({
            grass: of('spring-grass'),
            leaves: of('fallen-leaves'),
            planks: of('dock-planks'),
            oak: of('oak-floor'),
            tile: of('kitchen-tile'),
            marble: of('marble-floor'),
            water: of('shallow-water'),
            // Water before path: a pool is not walked on like a pavement.
            poolPath: of('pool-water'),
            carpet: of('rose-carpet'),
            snow: of('snow-ground'),
            gravel: of('gravel-ground'),
            unknown: of('something-nobody-classified'),
            bare: of(null),
            sounds: SURFACE_SOUNDS,
        }));
        JS);

    expect($answer['grass'])->toBe('grass')
        ->and($answer['leaves'])->toBe('grass')
        ->and($answer['planks'])->toBe('wood')
        ->and($answer['oak'])->toBe('wood')
        ->and($answer['tile'])->toBe('tile')
        ->and($answer['marble'])->toBe('stone')
        ->and($answer['water'])->toBe('water')
        ->and($answer['poolPath'])->toBe('water')
        ->and($answer['carpet'])->toBe('carpet')
        ->and($answer['snow'])->toBe('snow')
        ->and($answer['gravel'])->toBe('gravel');

    // A room with no floor texture, and a texture nobody has classified, both
    // still make a noise. Silence would read as a bug in the level.
    expect($answer['unknown'])->toBe('default')
        ->and($answer['bare'])->toBe('default');

    // The list of sounds to record is the list of files to supply, so it says
    // what it is and nothing is in it twice.
    expect($answer['sounds'])->toContain('default')
        ->and($answer['sounds'])->toBe(array_values(array_unique($answer['sounds'])));
});

it('names a step sound for every floor texture the game has', function (): void {
    $names = array_values(array_map(
        fn (string $path): string => pathinfo($path, PATHINFO_FILENAME),
        glob(dirname(__DIR__, 2).'/public/sprites/textures/*.png') ?: [],
    ));

    expect($names)->not->toBeEmpty();

    $textures = json_encode($names, JSON_THROW_ON_ERROR);

    $answer = stepsAnswer(<<<JS
        const textures = {$textures};

        process.stdout.write(JSON.stringify({
            surfaces: textures.map((name) => surfaceOf(name)),
            sounds: SURFACE_SOUNDS,
        }));
        JS);

    // Nothing may resolve to a sound that is not on the list of files to
    // supply, or a room would ask for a recording nobody was ever told about.
    expect(array_values(array_diff(
        array_unique($answer['surfaces']),
        $answer['sounds'],
    )))->toBe([]);
});
