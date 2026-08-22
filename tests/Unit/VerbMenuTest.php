<?php

use Symfony\Component\Process\Process;

/**
 * Which verbs the menu offers for a thing.
 *
 * The filtering happens in the browser because the inventory changes without
 * the level being resent — but it is only ever a tidying of what the server
 * already said. Anything the menu wrongly offers is refused when it is sent.
 */

/**
 * @return array<string, mixed>
 */
function verbMenuAnswer(string $body): array
{
    $script = <<<JS
        const { offersFor } = await import('@/lib/verb-offers.ts');

        const thing = (verbs) => ({
            slug: 'pot',
            name: 'Flower pot',
            description: 'A cracked pot.',
            kind: 'prop',
            sprite: null,
            behaviour: null,
            speed: 0,
            texture: null,
            x: 0,
            z: 0,
            elevation: 0,
            width: 1,
            depth: 1,
            height: 1,
            angle: 0,
            isSolid: true,
            verbs,
        });

        const item = (slug) => ({ slug, name: slug, description: '', icon: null });

        const said = (offers) => offers.map((offer) => `\${offer.verb}:\${offer.item ?? '-'}`);

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

it('always offers looking, even at something with nothing to it', function (): void {
    $answer = verbMenuAnswer(<<<'JS'
        process.stdout.write(JSON.stringify({
            offers: said(offersFor(thing([]), [])),
        }));
        JS);

    // Everything in a level has a description, and looking at it is what shows
    // it. A menu with no entries would be a dead end.
    expect($answer['offers'])->toBe(['look:-']);
});

it('does not offer looking twice when the thing answers to it', function (): void {
    $answer = verbMenuAnswer(<<<'JS'
        process.stdout.write(JSON.stringify({
            offers: said(offersFor(thing([{ verb: 'look', item: null }]), [])),
        }));
        JS);

    expect($answer['offers'])->toBe(['look:-']);
});

it('hides a verb needing an item the player is not carrying', function (): void {
    $answer = verbMenuAnswer(<<<'JS'
        const pot = thing([
            { verb: 'take', item: null },
            { verb: 'use', item: 'shed-key' },
        ]);

        process.stdout.write(JSON.stringify({
            empty: said(offersFor(pot, [])),
            carrying: said(offersFor(pot, [item('shed-key')])),
            wrongOne: said(offersFor(pot, [item('crowbar')])),
        }));
        JS);

    expect($answer['empty'])->toBe(['look:-', 'take:-'])
        ->and($answer['carrying'])->toBe(['look:-', 'take:-', 'use:shed-key'])
        ->and($answer['wrongOne'])->toBe(['look:-', 'take:-']);
});
