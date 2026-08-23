<?php

use Symfony\Component\Process\Process;

/**
 * Drawing the wiring by hand: a line pulled from one thing to another, and cut
 * again by clicking it.
 *
 * A line is one-way and there is only ever one of it, which is what makes
 * drawing it twice harmless and drawing the reverse a second line. Cutting one
 * is a matter of picking it up off the floor plan, so what counts is that the
 * nearest line to a click is the one under the pointer — including when two
 * lines cross.
 */

/**
 * Runs an edit against a level with two things in it and answers a question.
 *
 * @param  string  $lines  A JavaScript array of drawn lines.
 * @return array<string, mixed>
 */
function wiredLevel(string $lines, string $body): array
{
    $script = <<<JS
        const { cutLine, drawLine, lineNear } = await import('@/lib/editor/map.ts');

        const thing = (slug, x, z) => ({
            slug,
            name: slug,
            description: '',
            kind: 'prop',
            sprite: null,
            behaviour: null,
            stats: null,
            speed: 1,
            texture: null,
            render: 'box',
            planeCount: 2,
            uvMode: 'tile',
            textureAlt: null,
            altFlag: null,
            animationFrames: 1,
            animationFps: 8,
            x,
            z,
            elevation: 0,
            width: 1,
            depth: 1,
            height: 1,
            angle: 0,
            isSolid: false,
            hinge: null,
            emitWhen: null,
            triggeredBy: 'player',
            logic: 'any',
            readsFlag: null,
            writesFlag: null,
            bindings: [],
            verbs: [],
        });

        const level = {
            slug: 'test',
            name: 'test',
            description: '',
            spawn: { x: 0, z: 0, angle: 0 },
            ceilingHeight: 3,
            spriteStyle: 'realistic',
            playerSprite: 'paul',
            wallColor: '#ffffff',
            floorColor: '#888888',
            accentColor: '#ffcc00',
            sky: null,
            things: [
                thing('plate', 0, 0),
                thing('door', 4, 0),
                thing('lamp', 0, 4),
                thing('bell', 4, 4),
            ],
            sectors: [],
            lines: {$lines},
        };

        /** The wiring as pairs, which is the whole of what a line is. */
        const pairsIn = (edited) => edited.lines.map(({ from, to }) => [from, to]);

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

it('draws a line from one thing to another', function (): void {
    $answer = wiredLevel('[]', <<<'JS'
        process.stdout.write(JSON.stringify({
            drawn: pairsIn(drawLine(level, 'plate', 'door')),
        }));
        JS);

    expect($answer['drawn'])->toBe([['plate', 'door']]);
});

it('refuses a line from a thing to itself', function (): void {
    $answer = wiredLevel('[]', <<<'JS'
        process.stdout.write(JSON.stringify({
            drawn: pairsIn(drawLine(level, 'plate', 'plate')),
        }));
        JS);

    expect($answer['drawn'])->toBe([]);
});

it('keeps one line however often it is drawn, and both ways round', function (): void {
    $answer = wiredLevel("[{ from: 'plate', to: 'door' }]", <<<'JS'
        process.stdout.write(JSON.stringify({
            again: pairsIn(drawLine(level, 'plate', 'door')),
            back: pairsIn(drawLine(level, 'door', 'plate')),
        }));
        JS);

    expect($answer['again'])->toBe([['plate', 'door']])
        ->and($answer['back'])->toBe([['plate', 'door'], ['door', 'plate']]);
});

it('cuts the line it names and leaves the rest', function (): void {
    $answer = wiredLevel(
        "[{ from: 'plate', to: 'door' }, { from: 'lamp', to: 'bell' }]",
        <<<'JS'
        process.stdout.write(JSON.stringify({
            left: pairsIn(cutLine(level, 'plate', 'door')),
            // The other way round is a different line, so it cuts nothing.
            reversed: pairsIn(cutLine(level, 'door', 'plate')),
        }));
        JS
    );

    expect($answer['left'])->toBe([['lamp', 'bell']])
        ->and($answer['reversed'])->toBe([['plate', 'door'], ['lamp', 'bell']]);
});

it('picks up the line nearest a click, and nothing when the click is off it', function (): void {
    $answer = wiredLevel(
        "[{ from: 'plate', to: 'door' }, { from: 'lamp', to: 'bell' }]",
        <<<'JS'
        process.stdout.write(JSON.stringify({
            // Halfway along the near line, a hand's width off it.
            onIt: lineNear(level, { x: 2, z: 0.1 }, 0.4),
            // The same distance from the far one.
            other: lineNear(level, { x: 2, z: 3.9 }, 0.4),
            // Between the two, out of reach of both.
            between: lineNear(level, { x: 2, z: 2 }, 0.4),
            // Past the end of a line rather than beside it: a line is the
            // stretch between two things, not the whole of that direction.
            beyond: lineNear(level, { x: 6, z: 0 }, 0.4),
        }));
        JS
    );

    expect($answer['onIt'])->toBe(['from' => 'plate', 'to' => 'door'])
        ->and($answer['other'])->toBe(['from' => 'lamp', 'to' => 'bell'])
        ->and($answer['between'])->toBeNull()
        ->and($answer['beyond'])->toBeNull();
});

it('picks the nearer of two lines that cross', function (): void {
    $answer = wiredLevel(
        "[{ from: 'plate', to: 'bell' }, { from: 'lamp', to: 'door' }]",
        <<<'JS'
        process.stdout.write(JSON.stringify({
            // The two run corner to corner across the same square. Off the
            // crossing, each half belongs to one of them.
            lower: lineNear(level, { x: 1, z: 0.9 }, 0.4),
            upper: lineNear(level, { x: 1, z: 3.1 }, 0.4),
        }));
        JS
    );

    expect($answer['lower'])->toBe(['from' => 'plate', 'to' => 'bell'])
        ->and($answer['upper'])->toBe(['from' => 'lamp', 'to' => 'door']);
});
