<?php

use Symfony\Component\Process\Process;

/**
 * Which way round the player's own hands are drawn.
 *
 * There is one drawing per person per pose, seen edge on, and the engine turns
 * it round to make the other hand. Which side it belongs on matters: put it on
 * the wrong one and both hands are wrong at once, since they are mirror images.
 *
 * The art does not agree with itself — it was generated a person and a pose at a
 * time, and Paul's and Wade's fists face the opposite way to their own open
 * hands — so there is a table per pose, and this pins it.
 */

/**
 * @return array<string, mixed>
 */
function handsAnswer(string $body): array
{
    $script = <<<JS
        const blank = () => ({
            width: 0,
            height: 0,
            style: {},
            addEventListener() {},
            removeEventListener() {},
            getContext: () => null,
        });

        globalThis.document = { createElementNS: blank, createElement: blank };

        const { createHands, HELD_ITEMS } = await import('@/lib/engine/hands.ts');

        /**
         * The two hand cards, in screen order: the one to the left of the view
         * and the one to the right.
         */
        const cards = (sprite) => {
            const hands = createHands(sprite);
            const pair = hands.object.children
                .slice(0, 2)
                .sort((a, b) => a.position.x - b.position.x);

            return { hands, left: pair[0], right: pair[1] };
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

it('turns each drawing round for whichever side it was not made for', function (): void {
    $answer = handsAnswer(<<<'JS'
        const who = ['paul', 'krystal', 'luna', 'wade', 'luke', 'william'];
        const turned = {};

        for (const sprite of who) {
            const { hands, right } = cards(sprite);

            // Settle on the walking pose, then on the running one, and note
            // which way the card on the right of the view is turned for each.
            // 1 means the drawing as it was made; -1 means turned round.
            for (let i = 0; i < 40; i++) {
                hands.update(0.05, 0, false);
            }

            const walk = right.scale.x;

            for (let i = 0; i < 40; i++) {
                hands.update(0.05, 0, true);
            }

            turned[sprite] = [walk, right.scale.x];
        }

        process.stdout.write(JSON.stringify({ turned }));
        JS);

    // Measured off the artwork by which side carries the finger outlines; the
    // thumb is the other one, and it goes on the outside. Edge on you are
    // seeing the back of the hand, so it reads the other way to a palm held up
    // — which is what made the first attempt at this table backwards.
    expect($answer['turned'])->toBe([
        'paul' => [1, -1],
        'krystal' => [1, 1],
        'luna' => [1, 1],
        'wade' => [1, -1],
        'luke' => [1, 1],
        'william' => [-1, -1],
    ]);
});

it('always ends up with a right hand on the right of the screen', function (): void {
    $answer = handsAnswer(<<<'JS'
        const who = ['paul', 'krystal', 'luna', 'wade', 'luke', 'william', 'nobody'];
        const wrong = [];

        for (const sprite of who) {
            const { hands, left, right } = cards(sprite);

            for (const running of [false, true]) {
                for (let i = 0; i < 40; i++) {
                    hands.update(0.05, 0, running);
                }

                // Whatever the art drew, the two cards are mirror images either
                // side of the middle, in both poses. Both turned the same way
                // would put the same hand on both wrists.
                if (left.scale.x === right.scale.x) {
                    wrong.push(`${sprite}: both hands the same way round`);
                }
            }

            if (!(left.position.x < 0 && right.position.x > 0)) {
                wrong.push(`${sprite}: the hands are not either side of the view`);
            }

            if (left.position.y >= 0 || left.position.z >= 0) {
                wrong.push(`${sprite}: the hands are not below and in front`);
            }
        }

        process.stdout.write(JSON.stringify({ wrong }));
        JS);

    expect($answer['wrong'])->toBe([]);
});

it('keeps whatever is held in the hand on the right', function (): void {
    $answer = handsAnswer(<<<'JS'
        const { hands, right } = cards('william');

        hands.hold('wand');

        for (let i = 0; i < 40; i++) {
            hands.update(0.05, 0, false);
        }

        const wand = hands.object.children.slice(2).find((mesh) => mesh.visible);

        process.stdout.write(JSON.stringify({
            held: hands.holding(),
            byTheRightHand: Math.abs(wand.position.x - right.position.x) < 0.3,
            onTheRightOfTheView: right.position.x > 0,
        }));
        JS);

    // Turning the drawing round must not move what is being held: the wand
    // stays in the hand on the right whichever way that hand was drawn.
    expect($answer['held'])->toBe('wand')
        ->and($answer['byTheRightHand'])->toBeTrue()
        ->and($answer['onTheRightOfTheView'])->toBeTrue();
});
