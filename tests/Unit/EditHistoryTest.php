<?php

use Symfony\Component\Process\Process;

/**
 * The editor's way back from an edit. A draft is a whole level, so the history
 * is a run of them: undo hands back the one before, redo hands back the one
 * undone away, and a fresh edit drops anything there was to go forward to.
 *
 * The run is bounded because the drafts are big, so the oldest fall off the
 * back rather than the editor holding every state a session ever passed
 * through.
 */

/**
 * Runs a piece of the history under node and answers a question about it.
 *
 * @return array<string, mixed>
 */
function editHistory(string $body): array
{
    $script = <<<JS
        const {
            EMPTY_HISTORY,
            HISTORY_LIMIT,
            isContinuousEdit,
            redo,
            remember,
            undo,
        } = await import('@/lib/editor/history.ts');

        /** A draft, told apart by its name, which is all the history reads. */
        const draft = (name) => ({
            slug: 'test',
            name,
            description: '',
            spawn: { x: 1, z: 1, angle: 0 },
            ceilingHeight: 3,
            playerSprite: 'paul',
            sky: null,
            things: [],
            sectors: [],
        });

        /** The names in a history, past first, so an order is easy to read. */
        const namesIn = (history) => ({
            past: history.past.map((level) => level.name),
            future: history.future.map((level) => level.name),
        });

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

it('hands back the draft as it was before the last edit, then forward again', function (): void {
    $answer = editHistory(<<<'JS'
        // Three edits: the draft in hand is 'd', with a, b and c behind it.
        const filed = ['a', 'b', 'c'].reduce(
            (history, name) => remember(history, draft(name)),
            EMPTY_HISTORY,
        );

        const first = undo(filed, draft('d'));
        const second = undo(first.history, first.level);
        const forward = redo(second.history, second.level);

        process.stdout.write(JSON.stringify({
            filed: namesIn(filed),
            first: { level: first.level.name, history: namesIn(first.history) },
            second: { level: second.level.name, history: namesIn(second.history) },
            forward: { level: forward.level.name, history: namesIn(forward.history) },
        }));
        JS);

    expect($answer['filed'])->toBe(['past' => ['a', 'b', 'c'], 'future' => []]);

    // Undo walks back from the newest, and the draft it displaces is what
    // redo will hand forward.
    expect($answer['first']['level'])->toBe('c')
        ->and($answer['first']['history'])->toBe(['past' => ['a', 'b'], 'future' => ['d']]);

    expect($answer['second']['level'])->toBe('b')
        ->and($answer['second']['history'])->toBe(['past' => ['a'], 'future' => ['c', 'd']]);

    expect($answer['forward']['level'])->toBe('c')
        ->and($answer['forward']['history'])->toBe(['past' => ['a', 'b'], 'future' => ['d']]);
});

it('has nothing to hand back at either end', function (): void {
    $answer = editHistory(<<<'JS'
        const stepped = undo(remember(EMPTY_HISTORY, draft('a')), draft('b'));

        process.stdout.write(JSON.stringify({
            nothingBehind: undo(EMPTY_HISTORY, draft('a')),
            nothingAhead: redo(EMPTY_HISTORY, draft('a')),
            // Two steps back where only one was ever filed.
            pastTheEnd: undo(stepped.history, stepped.level),
        }));
        JS);

    expect($answer['nothingBehind'])->toBeNull()
        ->and($answer['nothingAhead'])->toBeNull()
        ->and($answer['pastTheEnd'])->toBeNull();
});

it('drops the way forward as soon as a new edit is made', function (): void {
    $answer = editHistory(<<<'JS'
        const filed = ['a', 'b'].reduce(
            (history, name) => remember(history, draft(name)),
            EMPTY_HISTORY,
        );

        const stepped = undo(filed, draft('c'));

        // An edit made after stepping back: 'c' is no longer reachable.
        const edited = remember(stepped.history, stepped.level);

        process.stdout.write(JSON.stringify({
            stepped: namesIn(stepped.history),
            edited: namesIn(edited),
            nothingAhead: redo(edited, draft('d')),
        }));
        JS);

    expect($answer['stepped'])->toBe(['past' => ['a'], 'future' => ['c']])
        ->and($answer['edited'])->toBe(['past' => ['a', 'b'], 'future' => []])
        ->and($answer['nothingAhead'])->toBeNull();
});

it('keeps only the last few drafts, oldest off the back', function (): void {
    $answer = editHistory(<<<'JS'
        const many = (limit, count) =>
            Array.from({ length: count }, (_, index) => index).reduce(
                (history, index) => remember(history, draft(String(index)), limit),
                EMPTY_HISTORY,
            );

        process.stdout.write(JSON.stringify({
            limit: HISTORY_LIMIT,
            underTheCap: namesIn(many(3, 2)).past,
            atTheCap: namesIn(many(3, 5)).past,
            // A level's worth of drafts is large, so the cap is real.
            atTheRealCap: namesIn(many(undefined, HISTORY_LIMIT + 10)).past.length,
            // A history that cannot hold anything never hands one back.
            noCap: namesIn(many(0, 4)).past,
        }));
        JS);

    expect($answer['limit'])->toBe(40)
        ->and($answer['underTheCap'])->toBe(['0', '1'])
        ->and($answer['atTheCap'])->toBe(['2', '3', '4'])
        ->and($answer['atTheRealCap'])->toBe(40)
        ->and($answer['noCap'])->toBe([]);
});

it('knows which edits arrive a bit at a time', function (): void {
    $answer = editHistory(<<<'JS'
        process.stdout.write(JSON.stringify({
            continuous: ['corner', 'thing', 'spawn', 'heights', 'nudge', 'field']
                .map(isContinuousEdit),
            atOnce: ['carve', 'duplicate', 'delete', 'place', 'split', 'revert']
                .map(isContinuousEdit),
        }));
        JS);

    // A drag or a typed name is one step however many changes it arrives in;
    // a carve or a delete is a step of its own every time.
    expect($answer['continuous'])->toBe([true, true, true, true, true, true])
        ->and($answer['atOnce'])->toBe([false, false, false, false, false, false]);
});
