<?php

use App\Services\LevelAssets;
use Symfony\Component\Process\Process;

/**
 * Numbers written down twice, in two languages, with nothing checking they agree.
 *
 * Two sets of them:
 *
 * 1. `tests/Pest.php` copies `MAX_STEP` and `MIN_HEADROOM` out of
 *    `resources/js/lib/engine/constants.ts`. Every level-geometry invariant is
 *    asserted against those copies, so if the engine's real values moved, the
 *    suite would go on passing while every seeded level quietly stopped
 *    matching the engine it is meant to be walkable in.
 * 2. `LevelAssets::HEIGHTS` is mirrored in `sprite-actor.ts`.
 *    `.ai/rules/services.md` already says "change one, change the other" — this
 *    is what makes forgetting fail loudly instead of half-working.
 * 3. The sky files' extension. PHP builds the editor's preview URL from it and
 *    the engine builds the texture URL from it, and a change of image format
 *    that reaches only one of them leaves the other loading a file that is not
 *    there — with nothing to show for it but a sky that does not appear.
 *
 * The check imports the real modules and prints their values rather than
 * parsing them. Parsing TypeScript to find out what a number is would be a
 * second thing that can drift.
 *
 * Deliberately not covered: `tests/Pest.php` also re-implements `pointInSector`
 * and `sectorAt` in PHP, mirroring `engine/sectors.ts`. Two implementations of
 * an algorithm cannot be compared by printing them, and guarding it needs a
 * shared body of cases both sides run. Noted here so the gap is on the record.
 */

/**
 * Whatever a JavaScript snippet prints, decoded.
 *
 * @return array<string, mixed>
 */
function engineValues(string $body): array
{
    $process = new Process([
        'node',
        '--experimental-strip-types',
        '--import',
        './tests/js/typescript-imports.mjs',
        '--input-type=module',
        '--eval',
        $body,
    ], dirname(__DIR__, 2));

    $process->mustRun();

    return json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR);
}

it('keeps the limits the level checks use in step with the engine', function (): void {
    $engine = engineValues(<<<'JS'
        const constants = await import('@/lib/engine/constants.ts');

        process.stdout.write(JSON.stringify({
            maxStep: constants.MAX_STEP,
            minHeadroom: constants.MIN_HEADROOM,
            playerRadius: constants.PLAYER_RADIUS,
        }));
        JS);

    // These two are straight copies, so they must be equal. If this fails,
    // the fix is to change tests/Pest.php to match constants.ts — not the
    // other way round. The engine is the authority; the test copy exists only
    // because Pest cannot import TypeScript.
    expect($engine['maxStep'])->toEqual(MAX_STEP)
        ->and($engine['minHeadroom'])->toEqual(MIN_HEADROOM);

    // CLEARANCE is a different animal, and the plan for this task had it wrong:
    // it is not copied from constants.ts, which has no such constant. It is a
    // judgement the tests make about how much room counts as "clear", and its
    // docblock says only that it is comfortably wider than the player. So what
    // is worth pinning is that relationship, not a value.
    //
    // (The engine does have a CLEARANCE, in portals.ts at 0.02, but it is the
    // nudge that lands a body inside the far room after a portal crossing and
    // has nothing to do with this one. Same name, unrelated meaning.)
    expect(CLEARANCE)->toBeGreaterThan($engine['playerRadius']);
});

it('keeps how tall everybody stands in step across PHP and TypeScript', function (): void {
    $engine = engineValues(<<<'JS'
        const actor = await import('@/lib/engine/sprite-actor.ts');

        process.stdout.write(JSON.stringify({ heights: actor.HEIGHTS }));
        JS);

    $php = LevelAssets::HEIGHTS;
    $typescript = $engine['heights'];

    // Both directions on purpose. Checking only that PHP's people appear in
    // TypeScript would let somebody add a seventh person to the TypeScript
    // alone and never hear about it — they would simply never be placeable,
    // which is the quiet half-working failure this test exists to prevent.
    expect(array_keys($typescript))->toEqualCanonicalizing(array_keys($php));

    foreach ($php as $person => $height) {
        expect($typescript)->toHaveKey($person);
        expect((float) $typescript[$person])->toEqual((float) $height);
    }
});

it('keeps everybody in the level assets tallest first, as the heights claim', function (): void {
    // The docblock on HEIGHTS says "tallest first", and LevelAssets::people()
    // hands that order to callers. A person inserted in the wrong place would
    // still pass the comparison above.
    $heights = array_values(LevelAssets::HEIGHTS);
    $sorted = $heights;

    rsort($sorted);

    expect($heights)->toBe($sorted);
});

it('keeps the sky file extension the same on both sides of the wire', function (): void {
    // PHP builds the Filament preview's URL and the engine builds the texture's,
    // from a constant each. They were four hardcoded `.png`s until the art was
    // re-sourced; one constant per language is only an improvement while
    // something holds the two together.
    $engine = engineValues(<<<'JS'
        const sky = await import('@/lib/engine/sky.ts');

        process.stdout.write(JSON.stringify({
            extension: sky.SKY_EXTENSION,
            url: sky.skyUrl('sky-day-1'),
        }));
        JS);

    expect($engine['extension'])->toBe(LevelAssets::SKY_EXTENSION)
        ->and($engine['url'])->toBe('/'.app(LevelAssets::class)->skyPath('sky-day-1'));
});
