<?php

use Symfony\Component\Process\Process;

/**
 * Which way round the player's own hands are drawn.
 *
 * There is one drawing per person per pose, seen edge on, and the engine turns
 * it round to make the other hand. Which side it belongs on matters: put it on
 * the wrong one and both hands are wrong at once, since they are mirror images.
 *
 * The edge cards are consistently drawn as left hands. There is still a table
 * per pose because the back-of-hand cards have their own measured mapping.
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

    // Every regenerated edge card is a left hand, so its unmirrored drawing
    // belongs on the left side of the view in both poses.
    expect($answer['turned'])->toBe([
        'paul' => [-1, -1],
        'krystal' => [-1, -1],
        'luna' => [-1, -1],
        'wade' => [-1, -1],
        'luke' => [-1, -1],
        'william' => [-1, -1],
    ]);
});

it('turns the back-of-hand drawings round by their own measurements', function (): void {
    // The reaching pair, which the crosshair resting on something brings up.
    // These were read off all twelve cards one at a time rather than carried
    // over from the edge rows, and they are not the same pattern: every
    // person's open back-of-hand has the thumb on the left, and every person's
    // fist has it on the right. So each person's two back cards disagree with
    // each other, where on the edge cards only Paul and Wade did.
    $answer = handsAnswer(<<<'JS'
        const who = ['paul', 'krystal', 'luna', 'wade', 'luke', 'william'];
        const turned = {};

        for (const sprite of who) {
            const { hands, right } = cards(sprite);

            // Reaching but not gripping: the open hand, seen from the back.
            for (let i = 0; i < 40; i++) {
                hands.update(0.05, 0, false, true);
            }

            const reach = right.scale.x;

            // Reaching and gripping: the fist, seen from the back.
            for (let i = 0; i < 40; i++) {
                hands.update(0.05, 0, true, true);
            }

            turned[sprite] = [reach, right.scale.x];
        }

        process.stdout.write(JSON.stringify({ turned }));
        JS);

    expect($answer['turned'])->toBe([
        'paul' => [-1, 1],
        'krystal' => [-1, 1],
        'luna' => [-1, 1],
        'wade' => [-1, 1],
        'luke' => [-1, 1],
        'william' => [-1, 1],
    ]);
});

it('leaves the hands edge on until the crosshair is on something', function (): void {
    // The whole point of the pose: a hand held edge on reads as swinging past,
    // and the same hand turned to show its back reads as going for the thing.
    // If it turned for everything it would say nothing.
    $answer = handsAnswer(<<<'JS'
        const { hands, right } = cards('paul');

        for (let i = 0; i < 40; i++) {
            hands.update(0.05, 0, false, false);
        }

        const idle = right.scale.x;

        for (let i = 0; i < 40; i++) {
            hands.update(0.05, 0, false, true);
        }

        const reaching = right.scale.x;

        // And back again when they look away.
        for (let i = 0; i < 40; i++) {
            hands.update(0.05, 0, false, false);
        }

        process.stdout.write(JSON.stringify({
            idle,
            reaching,
            after: right.scale.x,
        }));
        JS);

    // Paul's walking and reaching cards are both left hands, so changing the
    // pose does not require mirroring the card.
    expect($answer['idle'])->toBe(-1)
        ->and($answer['reaching'])->toBe(-1)
        ->and($answer['after'])->toBe(-1);
});

it('still works for a caller that says nothing about reaching', function (): void {
    // The argument is optional so that anything calling `update` with three
    // arguments keeps the behaviour it had, rather than silently reaching for
    // everything or for nothing.
    $answer = handsAnswer(<<<'JS'
        const { hands, right } = cards('paul');

        for (let i = 0; i < 40; i++) {
            hands.update(0.05, 0, false);
        }

        process.stdout.write(JSON.stringify({ walk: right.scale.x }));
        JS);

    expect($answer['walk'])->toBe(-1);
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
