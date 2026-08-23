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
function actionLines(string $things, string $lines, string $body): array
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
            emitWhen: null, triggeredBy: 'player', logic: 'any',
            readsFlag: null, writesFlag: null, bindings: [],
            isSolid: true, verbs: [], ...extra,
        });

        const wire = (from, to) => ({ from, to });

        const level = {
            slug: 't', name: 't', things: {$things}, lines: {$lines}, sectors: [],
        };

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

it('runs a drawn line from a plate to a door, with nothing named', function (): void {
    $answer = actionLines(
        "[thing({ slug: 'plate', emitWhen: 'stood_on', triggeredBy: 'anyone', width: 2, depth: 2 }),
          thing({ slug: 'door', x: 9, bindings: [
              { response: 'rotate', on: '90', off: '0' },
              { response: 'blocking', on: '0', off: '1' },
          ] })]",
        "[wire('plate', 'door')]",
        <<<'JS'
        const walk = [];

        lines.settle(nobody, responders);
        walk.push(['away', lines.isOn('door'), told.length]);

        lines.settle(at(0.5, 0.5), responders);
        walk.push(['on it', lines.isOn('door'), told.length]);

        // Still standing there. Nothing changed, so nothing is told again,
        // which is the property that makes this safe to run every frame.
        lines.settle(at(0.5, 0.5), responders);
        lines.settle(at(0.5, 0.5), responders);
        walk.push(['still', lines.isOn('door'), told.length]);

        lines.settle(at(9, 9), responders);
        walk.push(['off', lines.isOn('door'), told.length]);

        process.stdout.write(JSON.stringify({ walk, told }));
        JS
    );

    // The door's own output follows its input, so a plain thing is a relay
    // without being told to be one — which is what makes a chain possible at
    // all.
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

it('follows a chain of drawn lines to the end within one frame', function (): void {
    $answer = actionLines(
        "[thing({ slug: 'plate', emitWhen: 'stood_on', width: 2, depth: 2 }),
          thing({ slug: 'relay', x: 4 }),
          thing({ slug: 'door', x: 9, bindings: [{ response: 'rotate', on: '90', off: '0' }] })]",
        "[wire('plate', 'relay'), wire('relay', 'door')]",
        <<<'JS'
        lines.settle(at(0.5, 0.5), responders);

        process.stdout.write(JSON.stringify({
            told,
            on: ['plate', 'relay', 'door'].map((slug) => lines.isOn(slug)),
        }));
        JS
    );

    // Plate to relay to door, two lines and not one name typed. This is the
    // thing the first slice could only do by inventing two names that existed
    // for no reason but to join things already next to each other.
    expect($answer['on'])->toBe([true, true, true]);

    // And it arrived in the one settle. A chain that took a frame per link
    // would read as lag, which is what the pass loop exists to avoid.
    expect($answer['told'])->toEqual([['turn', 'door', 90]]);
});

it('makes a gate out of a thing with an opinion about its inputs', function (): void {
    $answer = actionLines(
        "[thing({ slug: 'left', emitWhen: 'stood_on', width: 2, depth: 2 }),
          thing({ slug: 'right', x: 20, emitWhen: 'stood_on', width: 2, depth: 2 }),
          thing({ slug: 'and', x: 4, logic: 'all' }),
          thing({ slug: 'or', x: 5, logic: 'any' }),
          thing({ slug: 'not', x: 6, logic: 'none' }),
          thing({ slug: 'torch', x: 7, logic: 'none' })]",
        "[wire('left', 'and'), wire('right', 'and'),
          wire('left', 'or'), wire('right', 'or'),
          wire('left', 'not')]",
        <<<'JS'
        const read = () => ['and', 'or', 'not', 'torch'].map((slug) => lines.isOn(slug));

        lines.settle(nobody, responders);
        const neither = read();

        lines.settle(at(0.5, 0.5), responders);
        const justLeft = read();

        lines.settle([{ x: 0.5, z: 0.5, isPlayer: true }, { x: 20, z: 0, isPlayer: true }], responders);
        const both = read();

        process.stdout.write(JSON.stringify({ neither, justLeft, both }));
        JS
    );

    // AND, OR and NOT, out of one column and no thing kind. A gate is not a
    // different sort of object; it is an ordinary one with an opinion about how
    // the lines drawn into it combine.
    expect($answer['neither'])->toBe([false, false, true, true])
        ->and($answer['justLeft'])->toBe([false, true, false, true])
        ->and($answer['both'])->toBe([true, true, false, true]);

    // And the last of those four is the one worth keeping: a NOT with nothing
    // drawn into it is always on, which is a redstone torch. Nobody designed
    // that; it falls out of *none of my inputs are on* being true when there
    // are none.
});

it('lets a wanderer stand on a plate and never lets one throw a lever', function (): void {
    $answer = actionLines(
        "[thing({ slug: 'ours', emitWhen: 'stood_on', triggeredBy: 'player' }),
          thing({ slug: 'theirs', emitWhen: 'stood_on', triggeredBy: 'actors' }),
          thing({ slug: 'either', emitWhen: 'stood_on', triggeredBy: 'anyone' }),
          thing({ slug: 'lever', emitWhen: 'used' })]",
        '[]',
        <<<'JS'
        lines.settle([{ x: 0, z: 0, isPlayer: true }], responders);
        const byPlayer = ['ours', 'theirs', 'either'].map((slug) => lines.isOn(slug));

        lines.settle([{ x: 0, z: 0, isPlayer: false }], responders);
        const byActor = ['ours', 'theirs', 'either'].map((slug) => lines.isOn(slug));

        const threw = [lines.use('lever'), lines.use('ours')];

        process.stdout.write(JSON.stringify({ byPlayer, byActor, threw }));
        JS
    );

    // Paul's ruling: standing on something is physical, so anybody may; but
    // flipping a switch is a deliberate act and feels wrong for a wanderer.
    expect($answer['byPlayer'])->toBe([true, false, true])
        ->and($answer['byActor'])->toBe([false, true, true]);

    // And only a lever answers being used at all.
    expect($answer['threw'])->toBe(['lever', null]);
});

it('bridges to the flag namespace only through a listener', function (): void {
    $answer = actionLines(
        "[thing({ slug: 'reader', readsFlag: 'power' }),
          thing({ slug: 'door', x: 9, bindings: [{ response: 'rotate', on: '90', off: '0' }] }),
          thing({ slug: 'plate', x: 20, emitWhen: 'stood_on', width: 2, depth: 2 }),
          thing({ slug: 'writer', x: 21, writesFlag: 'opened' })]",
        "[wire('reader', 'door'), wire('plate', 'writer')]",
        <<<'JS'
        lines.restore(new Set());
        lines.settle(nobody, responders);
        const dark = [lines.isOn('reader'), lines.writing()];

        // A flag arriving from the save lights the reader, which drives its
        // line the same as any other source.
        lines.restore(new Set(['power']));
        lines.settle(nobody, responders);
        const lit = [lines.isOn('reader'), lines.isOn('door')];

        // And the other way: a listener whose input is on names the flag the
        // save should be told about.
        lines.settle(at(20, 0), responders);
        const writing = lines.writing();

        process.stdout.write(JSON.stringify({ dark, lit, writing, told }));
        JS
    );

    // The only bridge between drawn wiring and the flag namespace, in both
    // directions. Everything else in a chain gets by without a name, which was
    // Paul's question — *how do things in a chain work with names?* They do
    // not, and the listener is there for the few that must.
    expect($answer['dark'])->toBe([false, []])
        ->and($answer['lit'])->toBe([true, true])
        ->and($answer['writing'])->toBe(['opened']);

    // And that list is what the save is told, which is also the only name the
    // browser is allowed to write — the guard the first slice put on emitters
    // had to move here when lines stopped having names.
});

it('remembers a lever and deliberately forgets a plate', function (): void {
    $answer = actionLines(
        "[thing({ slug: 'lever', emitWhen: 'used' }),
          thing({ slug: 'plate', x: 5, emitWhen: 'stood_on' })]",
        '[]',
        <<<'JS'
        lines.restore(new Set(['lever:lever', 'lever:plate']));
        lines.settle(nobody, responders);

        process.stdout.write(JSON.stringify({
            lever: lines.isOn('lever'),
            plate: lines.isOn('plate'),
        }));
        JS
    );

    // The lever is still thrown, because a lever you threw stays thrown.
    expect($answer['lever'])->toBeTrue();

    // The plate is not, because you are not standing on it. Restoring it would
    // hold a door open in an empty room, which is the difference between a
    // latching source and a momentary one and the reason `emitWhen` decides it
    // rather than a column somebody could set the other way.
    expect($answer['plate'])->toBeFalse();
});

it('stops rather than hanging on a ring of things driving each other', function (): void {
    $answer = actionLines(
        "[thing({ slug: 'a', logic: 'none' }), thing({ slug: 'b', logic: 'none' })]",
        "[wire('a', 'b'), wire('b', 'a')]",
        <<<'JS'
        const began = Date.now === undefined ? 0 : 0;

        lines.settle(nobody, responders);

        process.stdout.write(JSON.stringify({
            settled: true,
            a: lines.isOn('a'),
            b: lines.isOn('b'),
            began,
        }));
        JS
    );

    // Two NOTs pointed at each other have no resting state — it is a redstone
    // clock, and somebody will build one on purpose. The pass limit is what
    // makes that oscillate rather than hang the tab looking for a rest it does
    // not have, which is exactly why `resolveCollisions` bounds itself too.
    //
    // The assertion is that this test returns at all.
    expect($answer['settled'])->toBeTrue();
});
