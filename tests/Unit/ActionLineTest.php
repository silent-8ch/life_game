<?php

use Symfony\Component\Process\Process;

/**
 * Named lines that are on or off, and the things that answer them.
 *
 * Paul: *maybe we steal from redstone. Have an interactions level where things
 * trigger other things. Invisible non-solid things triggering more complex
 * things. All lines are either on or off and things interact with it being on
 * or off.*
 *
 * The half tested here is the frame: reading the emitters after everything has
 * moved, working out what changed, and telling only the things that answer what
 * changed. That last part is what makes it cheap enough to leave running — the
 * frame does not look through the level for things that might care, it is told
 * by an index built once.
 */

/**
 * @return array<string, mixed>
 */
function actionLines(string $things, string $body): array
{
    $script = <<<JS
        const { createActionLines } = await import('@/lib/engine/action-lines.ts');

        const thing = (extra = {}) => ({
            slug: 'thing', name: 'Thing', description: '', kind: 'prop',
            sprite: null, behaviour: null, stats: null, speed: 0,
            texture: null, render: 'flat', planeCount: 2, uvMode: 'fit',
            textureAlt: null, altFlag: null, animationFrames: 1,
            animationFps: 1, x: 0, z: 0, elevation: 0,
            width: 1, depth: 1, height: 2, angle: 0, hinge: 'left',
            emits: null, emitWhen: null, triggeredBy: 'player', bindings: [],
            isSolid: true, verbs: [], ...extra,
        });

        const level = { slug: 't', name: 't', things: {$things}, sectors: [] };

        const lines = createActionLines(level);

        /** What the responders were told, in order. */
        const told = [];

        const responders = {
            turn: (slug, degrees) => told.push(['turn', slug, degrees]),
            block: (slug, blocking) => told.push(['block', slug, blocking]),
        };

        const at = (x, z, isPlayer = true) => [{ x, z, isPlayer }];
        const nobody = [];

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

it('puts a line on while somebody is standing on the plate and off when they step away', function (): void {
    $answer = actionLines(
        "[thing({ slug: 'plate', emits: 'power', emitWhen: 'stood_on', width: 2, depth: 2 }),
          thing({ slug: 'door', x: 9, bindings: [
              { line: 'power', response: 'rotate', on: '90', off: '0' },
              { line: 'power', response: 'blocking', on: '0', off: '1' },
          ] })]",
        <<<'JS'
        const walk = [];

        lines.settle(nobody, responders);
        walk.push(['away', lines.isOn('power'), told.length]);

        lines.settle(at(0.5, 0.5), responders);
        walk.push(['on it', lines.isOn('power'), told.length]);

        // Still standing there. Nothing changed, so nothing is told again —
        // which is the property that makes this safe to run every frame.
        lines.settle(at(0.5, 0.5), responders);
        lines.settle(at(0.5, 0.5), responders);
        walk.push(['still', lines.isOn('power'), told.length]);

        lines.settle(at(9, 9), responders);
        walk.push(['off', lines.isOn('power'), told.length]);

        process.stdout.write(JSON.stringify({ walk, told }));
        JS
    );

    // On when stood on, off when not, and the door told both times.
    expect($answer['walk'])->toEqual([
        ['away', false, 0],
        ['on it', true, 2],
        ['still', true, 2],
        ['off', false, 4],
    ]);

    // Both sides authored, so the door shuts behind you rather than staying
    // open because nobody said what off meant.
    expect($answer['told'])->toEqual([
        ['turn', 'door', 90],
        ['block', 'door', false],
        ['turn', 'door', 0],
        ['block', 'door', true],
    ]);
});

it('is the footprint that counts, turned the way the plate is turned', function (): void {
    $answer = actionLines(
        "[thing({ slug: 'plate', emits: 'power', emitWhen: 'stood_on', width: 4, depth: 1, angle: 90 })]",
        <<<'JS'
        const spots = [[1.9, 0], [2.1, 0], [0, 1.9], [0, 2.1]].map((where) => {
            lines.settle(at(where[0], where[1]), responders);

            return [where, lines.isOn('power')];
        });

        process.stdout.write(JSON.stringify({ spots }));
        JS
    );

    // Four metres by one, turned a quarter: so it reaches two metres along z
    // and half a metre along x, not the other way round. The plate covers what
    // it looks like it covers, because this is the same rectangle its collider
    // would be — it simply has no collider, being something you walk over.
    expect($answer['spots'])->toEqual([
        [[1.9, 0], false],
        [[2.1, 0], false],
        [[0, 1.9], true],
        [[0, 2.1], false],
    ]);
});

it('lets a wanderer stand on a plate and never lets one throw a lever', function (): void {
    $answer = actionLines(
        "[thing({ slug: 'ours', emits: 'a', emitWhen: 'stood_on', triggeredBy: 'player' }),
          thing({ slug: 'theirs', emits: 'b', emitWhen: 'stood_on', triggeredBy: 'actors' }),
          thing({ slug: 'either', emits: 'c', emitWhen: 'stood_on', triggeredBy: 'anyone' }),
          thing({ slug: 'lever', emits: 'd', emitWhen: 'used' })]",
        <<<'JS'
        lines.settle([{ x: 0, z: 0, isPlayer: true }], responders);
        const byPlayer = ['a', 'b', 'c'].map((line) => lines.isOn(line));

        lines.settle([{ x: 0, z: 0, isPlayer: false }], responders);
        const byActor = ['a', 'b', 'c'].map((line) => lines.isOn(line));

        // A lever is thrown by name, and only a lever answers.
        const threw = [lines.use('lever'), lines.use('ours')];

        process.stdout.write(JSON.stringify({ byPlayer, byActor, threw }));
        JS
    );

    // Paul's ruling: standing on something is physical, so anybody may; but
    // flipping a switch is a deliberate act and feels wrong for a wanderer.
    expect($answer['byPlayer'])->toBe([true, false, true])
        ->and($answer['byActor'])->toBe([false, true, true]);

    // And `use` hands back the line it moved, or null — so the caller knows
    // whether there is anything worth telling the save about.
    expect($answer['threw'])->toBe(['d', null]);
});

it('follows a chain of lines to the end of itself within one frame', function (): void {
    $answer = actionLines(
        "[thing({ slug: 'plate', emits: 'first', emitWhen: 'stood_on', width: 2, depth: 2 }),
          thing({ slug: 'relay', x: 5, emits: 'second', emitWhen: 'used', bindings: [
              { line: 'first', response: 'rotate', on: '90', off: '0' },
          ] }),
          thing({ slug: 'far', x: 9, bindings: [
              { line: 'second', response: 'rotate', on: '45', off: '0' },
          ] })]",
        <<<'JS'
        // The relay is thrown by hand here rather than by the plate, because
        // driving a line from a binding is the second slice. What this pins is
        // that a frame settles every line rather than only the ones an emitter
        // read directly — so when a binding can raise a line, the frame it
        // happens in is already the frame it arrives in.
        lines.use('relay');
        lines.settle(at(0.5, 0.5), responders);

        process.stdout.write(JSON.stringify({
            told,
            on: ['first', 'second'].map((line) => lines.isOn(line)),
        }));
        JS
    );

    expect($answer['on'])->toBe([true, true]);

    // Both reached in the one settle, in one frame. A chain that took a frame
    // per link would read as lag, which is the thing the pass loop exists to
    // avoid.
    expect($answer['told'])->toEqual([
        ['turn', 'relay', 90],
        ['turn', 'far', 45],
    ]);
});

it('remembers a lever and deliberately forgets a plate', function (): void {
    $answer = actionLines(
        "[thing({ slug: 'lever', emits: 'thrown', emitWhen: 'used' }),
          thing({ slug: 'plate', x: 5, emits: 'trodden', emitWhen: 'stood_on' })]",
        <<<'JS'
        // What a save would hand back: both lines were on when it was written.
        lines.restore(new Set(['thrown', 'trodden']));
        lines.settle(nobody, responders);

        process.stdout.write(JSON.stringify({
            thrown: lines.isOn('thrown'),
            trodden: lines.isOn('trodden'),
        }));
        JS
    );

    // The lever is still thrown, because a lever you threw stays thrown.
    expect($answer['thrown'])->toBeTrue();

    // The plate is not, because you are not standing on it. Restoring it would
    // hold a door open in an empty room, which is the difference between a
    // latching line and a momentary one and the reason `emitWhen` decides it
    // rather than a column somebody could set the other way.
    expect($answer['trodden'])->toBeFalse();
});
