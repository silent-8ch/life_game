<?php

use Symfony\Component\Process\Process;

/**
 * The stick and the look pad, driven by made-up thumbs.
 *
 * There is no browser here, so the few bits of the DOM the controls touch are
 * stood in for. What is being checked is the arithmetic between a thumb and the
 * engine: which way a push means, how far over counts as a run, and that a drag
 * is handed over once and only once.
 */

/**
 * @param  string  $body  Runs with `controls` already built.
 * @return array<string, mixed>
 */
function touchAnswer(string $body): array
{
    $script = <<<JS
        // A screen 800 by 400, and just enough of a document to hang the
        // controls on. Nothing here draws.
        const node = () => ({
            className: '',
            style: {},
            type: '',
            title: '',
            textContent: '',
            children: [],
            classList: { add() {}, remove() {}, toggle() {} },
            setAttribute() {},
            appendChild(child) { this.children.push(child); },
            append(...kids) { this.children.push(...kids); },
            addEventListener() {},
            removeEventListener() {},
            remove() {},
        });

        globalThis.window = { matchMedia: () => ({ matches: true }) };
        globalThis.document = { createElement: node };

        const listeners = new Map();

        const container = {
            ...node(),
            getBoundingClientRect: () => ({ left: 0, top: 0, width: 800, height: 400 }),
            setPointerCapture() {},
            addEventListener(name, run) { listeners.set(name, run); },
            removeEventListener() {},
        };

        const { createTouchControls } = await import('./resources/js/lib/engine/touch.ts');

        const controls = createTouchControls({ container, buttons: [] });
        controls.show(true);

        /** A thumb going down, moving and lifting. */
        const touch = (id, name, x, y) => listeners.get(name)({
            pointerId: id,
            pointerType: 'touch',
            clientX: x,
            clientY: y,
            preventDefault() {},
            stopPropagation() {},
        });

        const round = (value) => Number(value.toFixed(3));

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

it('walks the way the thumb is pushed', function (): void {
    $answer = touchAnswer(<<<'JS'
        // Down on the left half, then pushed a long way up the screen.
        touch(1, 'pointerdown', 200, 200);
        touch(1, 'pointermove', 200, 100);
        const up = controls.walk();

        touch(1, 'pointermove', 300, 200);
        const right = controls.walk();

        touch(1, 'pointermove', 200, 300);
        const down = controls.walk();

        process.stdout.write(JSON.stringify({
            up: { forward: round(up.forward), strafe: round(up.strafe) },
            right: { forward: round(right.forward), strafe: round(right.strafe) },
            down: { forward: round(down.forward), strafe: round(down.strafe) },
        }));
        JS);

    // Up the screen is forward, right is right, down is backwards. Each is
    // pinned at 1: past the edge of the ring the stick stays at the edge.
    expect($answer['up'])->toEqual(['forward' => 1, 'strafe' => 0])
        ->and($answer['right'])->toEqual(['forward' => 0, 'strafe' => 1])
        ->and($answer['down'])->toEqual(['forward' => -1, 'strafe' => 0]);
});

it('stands still while the thumb is barely off the middle', function (): void {
    $answer = touchAnswer(<<<'JS'
        touch(1, 'pointerdown', 200, 200);
        touch(1, 'pointermove', 204, 200);

        const nudged = controls.walk();

        touch(1, 'pointerup', 204, 200);

        const lifted = controls.walk();

        process.stdout.write(JSON.stringify({
            nudged: [round(nudged.forward), round(nudged.strafe)],
            lifted: [round(lifted.forward), round(lifted.strafe)],
        }));
        JS);

    // A thumb resting on the glass shakes; without a dead zone the player
    // drifts across the room while nobody is asking them to.
    expect($answer['nudged'])->toEqual([0, 0])
        ->and($answer['lifted'])->toEqual([0, 0]);
});

it('runs only once the stick is most of the way over', function (): void {
    $answer = touchAnswer(<<<'JS'
        touch(1, 'pointerdown', 200, 200);

        touch(1, 'pointermove', 200, 175);
        const half = controls.running();

        touch(1, 'pointermove', 200, 130);
        const most = controls.running();

        process.stdout.write(JSON.stringify({ half, most }));
        JS);

    expect($answer['half'])->toBeFalse()
        ->and($answer['most'])->toBeTrue();
});

it('turns the view by a drag on the right, and hands it over once', function (): void {
    $answer = touchAnswer(<<<'JS'
        touch(2, 'pointerdown', 600, 200);
        touch(2, 'pointermove', 550, 180);

        const first = controls.takeLook();
        const again = controls.takeLook();

        process.stdout.write(JSON.stringify({
            first: [round(first.yaw), round(first.pitch)],
            again: [round(again.yaw), round(again.pitch)],
        }));
        JS);

    // Dragging left turns left, which is a rising yaw; dragging up looks up.
    expect($answer['first'][0])->toBeGreaterThan(0.0)
        ->and($answer['first'][1])->toBeGreaterThan(0.0);

    // Read once and gone, or the same drag turns the view every frame after.
    expect($answer['again'])->toEqual([0, 0]);
});

it('keeps the two thumbs apart', function (): void {
    $answer = touchAnswer(<<<'JS'
        touch(1, 'pointerdown', 200, 200);
        touch(2, 'pointerdown', 600, 200);

        // The walking thumb moves; the looking one does not.
        touch(1, 'pointermove', 200, 120);

        const walking = controls.walk();
        const looking = controls.takeLook();

        process.stdout.write(JSON.stringify({
            forward: round(walking.forward),
            yaw: round(looking.yaw),
        }));
        JS);

    expect($answer['forward'])->toBeGreaterThan(0.9)
        ->and($answer['yaw'])->toEqual(0);
});

it('takes nothing at all until the level is being played', function (): void {
    $answer = touchAnswer(<<<'JS'
        controls.show(false);

        touch(1, 'pointerdown', 200, 200);
        touch(1, 'pointermove', 200, 100);

        const walking = controls.walk();

        process.stdout.write(JSON.stringify({ forward: round(walking.forward) }));
        JS);

    // Otherwise the tap that starts the level is swallowed as the first push of
    // the stick and the level never starts.
    expect($answer['forward'])->toEqual(0);
});

it('builds nothing on a machine with a mouse', function (): void {
    $answer = touchAnswer(<<<'JS'
        globalThis.window.matchMedia = () => ({ matches: false });

        const { createTouchControls: again } = await import('./resources/js/lib/engine/touch.ts');
        const none = again({ container, buttons: [] });

        process.stdout.write(JSON.stringify({
            active: none.active,
            forward: none.walk().forward,
        }));
        JS);

    expect($answer['active'])->toBeFalse()
        ->and($answer['forward'])->toEqual(0);
});
