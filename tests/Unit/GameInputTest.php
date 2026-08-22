<?php

use Symfony\Component\Process\Process;

/**
 * The keys, the mouse and the pointer lock.
 *
 * None of this was reachable before: it lived inside the viewport's effect,
 * where the only way to exercise it was to play the game. It is
 * `lib/engine/input.ts` now and it takes a container and a set of callbacks, so
 * a stub browser is enough to press a key at it and ask what it reports.
 *
 * The rules it keeps are small and every one of them has bitten: keys are
 * ignored unless the pointer is locked, everything held down is let go when the
 * lock breaks, and whatever is on top of the level takes the keys away without
 * stopping the level itself.
 */

/**
 * @return array<string, mixed>
 */
function gameInput(string $body): array
{
    $script = <<<JS
        const listeners = { window: {}, document: {}, container: {} };

        const listenerBag = (which) => ({
            addEventListener(name, run) {
                (listeners[which][name] ??= []).push(run);
            },
            removeEventListener(name, run) {
                listeners[which][name] = (listeners[which][name] ?? []).filter(
                    (was) => was !== run,
                );
            },
        });

        const container = {
            ...listenerBag('container'),
            requestPointerLock() {
                globalThis.document.pointerLockElement = container;
            },
            requestFullscreen: () => Promise.resolve(),
        };

        globalThis.window = {
            ...listenerBag('window'),
            // No coarse pointer, so createTouchControls draws nothing and hands
            // back a set that reports no stick and no buttons.
            matchMedia: () => ({ matches: false }),
        };

        globalThis.document = {
            ...listenerBag('document'),
            pointerLockElement: null,
            fullscreenElement: null,
            exitPointerLock() {
                globalThis.document.pointerLockElement = null;
            },
            exitFullscreen: () => Promise.resolve(),
        };

        /** Fires one event at whoever registered for it. */
        const fire = (which, name, event = {}) => {
            for (const run of listeners[which][name] ?? []) {
                run({ preventDefault() {}, repeat: false, ...event });
            }
        };

        const { createInput } = await import('@/lib/engine/input.ts');

        const done = [];
        let holding = false;
        const turns = [];

        const input = createInput(container, false, {
            examine: () => done.push('examine'),
            markHere: () => done.push('mark'),
            recall: () => done.push('recall'),
            takeInHand: (item) => done.push(`hold:\${item ?? 'nothing'}`),
            takeSnapshot: () => done.push('snapshot'),
            fire: () => done.push('fire'),
            look: (turned) => turns.push(turned),
            held: () => holding,
            onLockChange: (locked) => done.push(`lock:\${locked}`),
            onPlaying: (playing) => done.push(`playing:\${playing}`),
            onFullscreen: (full) => done.push(`fullscreen:\${full}`),
        });

        const lock = () => {
            fire('container', 'click');
            fire('document', 'pointerlockchange');
        };

        const unlock = () => {
            globalThis.document.pointerLockElement = null;
            fire('document', 'pointerlockchange');
        };

        const press = (code) => fire('window', 'keydown', { code });
        const release = (code) => fire('window', 'keyup', { code });

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

it('ignores the keys until the pointer is locked', function (): void {
    $answer = gameInput(<<<'JS'
        press('KeyW');

        const before = input.read().push;

        lock();
        press('KeyW');

        const after = input.read().push;

        process.stdout.write(JSON.stringify({ before, after }));
        JS);

    // A key pressed while the page is a page and not a game does nothing. This
    // is what stops the level walking off while somebody is scrolling.
    expect($answer['before']['forward'])->toBe(0)
        ->and($answer['after']['forward'])->toBe(1);
});

it('turns keys into a push, and the two sides cancel', function (): void {
    $answer = gameInput(<<<'JS'
        lock();

        press('KeyW');
        press('KeyD');

        const both = input.read().push;

        press('KeyA');

        const cancelled = input.read().push;

        press('ShiftLeft');

        const running = input.read().push.running;

        release('KeyW');

        const stopped = input.read().push;

        process.stdout.write(JSON.stringify({ both, cancelled, running, stopped }));
        JS);

    expect($answer['both'])->toBe(['forward' => 1, 'strafe' => 1, 'running' => false])
        ->and($answer['cancelled']['strafe'])->toBe(0)
        ->and($answer['running'])->toBeTrue()
        ->and($answer['stopped']['forward'])->toBe(0);
});

it('lets go of everything when the lock breaks', function (): void {
    $answer = gameInput(<<<'JS'
        lock();
        press('KeyW');
        press('ShiftLeft');

        const held = input.read().push;

        unlock();

        const dropped = input.read().push;

        process.stdout.write(JSON.stringify({ held, dropped, done }));
        JS);

    // Escape out of the game with a key down and the key stays down for ever:
    // the level would keep walking with nobody at the keyboard.
    expect($answer['held']['forward'])->toBe(1)
        ->and($answer['dropped'])->toBe(['forward' => 0, 'strafe' => 0, 'running' => false])
        ->and($answer['done'])->toContain('lock:false');
});

it('hands the keys to whatever is on top without stopping the level', function (): void {
    $answer = gameInput(<<<'JS'
        lock();
        press('KeyW');

        holding = true;

        const whileHeld = input.read();

        press('KeyD');
        press('KeyE');

        const stillHeld = input.read();

        holding = false;

        const after = input.read().push;

        process.stdout.write(JSON.stringify({
            whileHeld,
            stillPushing: stillHeld.push,
            after,
            done,
        }));
        JS);

    // The verb menu is open. No walking, no turning, and no keys reaching the
    // level — but read() still answers, because the frame carries on running:
    // people keep walking and spells keep burning while somebody reads.
    expect($answer['whileHeld']['holding'])->toBeTrue()
        ->and($answer['whileHeld']['push']['forward'])->toBe(0)
        ->and($answer['stillPushing']['strafe'])->toBe(0)
        ->and($answer['done'])->not->toContain('examine')
        // And the keys held before it opened are let go, so nothing is still
        // down when it closes.
        ->and($answer['after']['forward'])->toBe(0);
});

it('does the things a key does, once per press', function (): void {
    $answer = gameInput(<<<'JS'
        lock();

        press('KeyE');
        press('KeyM');
        press('KeyR');
        press('KeyF');
        press('Digit1');
        press('Digit0');

        // A held key repeats. The verbs must not.
        fire('window', 'keydown', { code: 'KeyE', repeat: true });

        process.stdout.write(JSON.stringify({ done }));
        JS);

    // The lock is the first thing that happens, and the page is told about it.
    expect($answer['done'])->toBe([
        'lock:true',
        'examine',
        'mark',
        'recall',
        'snapshot',
        'hold:wand',
        'hold:nothing',
    ]);
});

it('turns the head as the mouse moves, and not while something is on top', function (): void {
    $answer = gameInput(<<<'JS'
        lock();

        fire('document', 'mousemove', { movementX: 10, movementY: -4 });

        holding = true;
        fire('document', 'mousemove', { movementX: 10, movementY: -4 });

        process.stdout.write(JSON.stringify({ turns }));
        JS);

    // One turn, not two: the mouse is ignored while the level is not listening.
    // Left on the screen is a turn to the left, which is a negative yaw.
    expect($answer['turns'])->toHaveCount(1)
        ->and($answer['turns'][0]['yaw'])->toBeLessThan(0)
        ->and($answer['turns'][0]['pitch'])->toBeGreaterThan(0);
});
