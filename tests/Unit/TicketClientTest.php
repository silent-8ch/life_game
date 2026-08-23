<?php

use Symfony\Component\Process\Process;

/**
 * Turning a spot and a sentence into something the endpoint will take.
 *
 * The join is the part worth testing, because it is where two shapes that were
 * designed apart have to meet. `describeSpot()` calls the boundaries within
 * reach `edgesNearby`; the server calls them `nearby`. `standingIn` is a whole
 * room on one side and was a bare slug on the other until it was widened. A
 * mismatch here does not crash — it comes back 422 after the player has
 * already stopped playing, written their sentence and pressed send, which is
 * the worst possible moment to lose a report.
 *
 * The other half is `FormData`, which has exactly one type: string. Every null
 * that goes near it comes back as the four characters `null`, so a room nobody
 * was standing in arrives as a room *called* null unless it is left out.
 */

/**
 * @return array<string, mixed>
 */
function ticketAnswer(string $body): array
{
    $script = <<<JS
        globalThis.document = { cookie: '' };

        const { ticketFromSpot } = await import('@/lib/engine/ticket.ts');
        // The flattener lives with `guardHeaders` now, because a debug snapshot
        // carries the same pictures and wants the same form — and a ticket that
        // imported it back from here would close a cycle between the two.
        const { reportForm } = await import('@/lib/engine/snapshot.ts');

        /** A spot as `describeSpot()` hands one back. */
        const aSpot = (changes = {}) => ({
            takenAt: '2026-08-22T18:00:00.000Z',
            level: { slug: 'the-house', name: 'The House' },
            at: { x: 2.5, z: -4.25, eye: 1.62, yaw: 135, pitch: -8.5 },
            standingIn: {
                slug: 'hall',
                name: 'Hall',
                floorHeight: 0,
                ceilingHeight: 3,
                isSky: false,
                isWater: false,
                wallTexture: 'cream-plaster-wall',
                floorTexture: null,
                ceilingTexture: 'white-plaster',
            },
            edgesNearby: [
                { distance: -0.04, rooms: ['hall', 'kitchen'], open: true },
            ],
            lookingAt: 'crate',
            holding: null,
            running: false,
            screen: { width: 1512, height: 893, pixelRatio: 2, touch: false },
            note: '',
            ...changes,
        });

        /** What a FormData actually holds, as plain data. */
        const contents = (form) => {
            const out = {};

            for (const [key, value] of form.entries()) {
                out[key] = typeof value === 'string' ? value : '[file]';
            }

            return out;
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

it('renames the boundaries to what the server calls them', function (): void {
    // The one place the snapshot's shape and the ticket's disagree. Done here
    // rather than left for whoever wires the next client to rediscover.
    $answer = ticketAnswer(<<<'JS'
        const fields = ticketFromSpot(aSpot(), 'the floor has a hole in it');

        process.stdout.write(JSON.stringify({
            nearby: fields.nearby,
            hasEdgesNearby: 'edgesNearby' in fields,
        }));
        JS);

    expect($answer['nearby'])->toHaveCount(1)
        ->and($answer['nearby'][0]['rooms'])->toBe(['hall', 'kitchen'])
        ->and($answer['hasEdgesNearby'])->toBeFalse();
});

it('carries the room whole, textures and all', function (): void {
    // The reason `standingIn` was widened from a slug. A null floor texture is
    // a real reading and the one that would have ended an evening's hunt — so
    // it has to survive the trip rather than be tidied away as empty.
    $answer = ticketAnswer(<<<'JS'
        const fields = ticketFromSpot(aSpot(), 'wrong');

        process.stdout.write(JSON.stringify({ room: fields.standingIn }));
        JS);

    expect($answer['room']['slug'])->toBe('hall')
        ->and($answer['room']['wallTexture'])->toBe('cream-plaster-wall')
        ->and($answer['room']['floorTexture'])->toBeNull()
        ->and($answer['room']['ceilingHeight'])->toEqual(3);
});

it('leaves out what nobody said rather than sending the word null', function (): void {
    // `FormData` stringifies everything it is given. A player standing outside
    // every room would otherwise report standing in a room called "null".
    $answer = ticketAnswer(<<<'JS'
        const fields = ticketFromSpot(
            aSpot({ standingIn: null, lookingAt: null }),
            'I fell out of the world',
        );

        process.stdout.write(JSON.stringify({
            held: contents(reportForm(fields, {})),
        }));
        JS);

    expect($answer['held'])->not->toHaveKey('standingIn[slug]')
        ->and($answer['held'])->not->toHaveKey('lookingAt')
        ->and($answer['held'])->not->toHaveKey('editorState')
        ->and($answer['held']['note'])->toBe('I fell out of the world');
});

it('spells nested fields the way PHP reassembles them', function (): void {
    // NOT as JSON under one key, which is the obvious thing and is wrong: the
    // rules say `at` is an array, and a JSON string is not one. It would pass
    // any test written against my own assumption and fail against the server.
    $answer = ticketAnswer(<<<'JS'
        const fields = ticketFromSpot(aSpot(), 'wrong');
        const held = contents(reportForm(fields, {}));

        process.stdout.write(JSON.stringify({ held }));
        JS);

    $held = $answer['held'];

    // The player's own yaw, not a level's spawn angle, which is its negative.
    expect($held['at[yaw]'])->toBe('135')
        ->and($held['standingIn[slug]'])->toBe('hall')
        ->and($held['nearby[0][rooms][0]'])->toBe('hall')
        // A boolean has to arrive as something the server reads as false —
        // not the string "false", which is truthy.
        ->and($held['running'])->toBe('0')
        ->and($held['standingIn[isSky]'])->toBe('0')
        // And nothing is left as one big JSON blob.
        ->and($held)->not->toHaveKey('at')
        ->and($held)->not->toHaveKey('standingIn');
});

it('names each picture by the view it is of', function (): void {
    // The server files them by name and the admin panel reads them back the
    // same way, so a numbered upload would arrive as a kind it does not know.
    $answer = ticketAnswer(<<<'JS'
        const fields = ticketFromSpot(aSpot(), 'wrong');
        const shots = {
            normal: new Blob(['x'], { type: 'image/png' }),
            walls: new Blob(['y'], { type: 'image/png' }),
        };

        process.stdout.write(JSON.stringify({
            keys: Object.keys(contents(reportForm(fields, shots))),
        }));
        JS);

    expect($answer['keys'])->toContain('shots[normal]')
        ->and($answer['keys'])->toContain('shots[walls]');
});

it('still sends a report when there were no pictures to take', function (): void {
    // A readback can fail. The spot, the room and its textures are most of what
    // diagnoses a fault, so losing the whole report over the pictures would be
    // much the worse outcome.
    $answer = ticketAnswer(<<<'JS'
        const fields = ticketFromSpot(aSpot(), 'no pictures');
        const held = contents(reportForm(fields, {}));

        process.stdout.write(JSON.stringify({
            note: held.note,
            room: held['standingIn[slug]'],
            pictures: Object.keys(held).filter((k) => k.startsWith('shots')),
        }));
        JS);

    expect($answer['note'])->toBe('no pictures')
        ->and($answer['room'])->toBe('hall')
        ->and($answer['pictures'])->toBe([]);
});

it('drops pictures rather than losing the whole report', function (): void {
    $answer = ticketAnswer(<<<'JS'
        const { withinBudget } = await import('@/lib/engine/snapshot.ts');

        /** A picture of a given weight, without making one that big. */
        const weighing = (bytes) => ({ size: bytes, type: 'image/png' });

        const all = {
            normal: weighing(900_000),
            wireframe: weighing(700_000),
            walls: weighing(300_000),
        };

        process.stdout.write(JSON.stringify({
            underBudget: Object.keys(withinBudget({
                normal: weighing(400_000),
                wireframe: weighing(200_000),
                walls: weighing(100_000),
            })),
            over: Object.keys(withinBudget(all)),
            wayOver: Object.keys(withinBudget({
                normal: weighing(3_000_000),
                wireframe: weighing(3_000_000),
                walls: weighing(3_000_000),
            })),
        }));
        JS);

    // Nothing is dropped when everything fits.
    expect($answer['underBudget'])->toBe(['normal', 'wireframe', 'walls']);

    // The wireframe goes first: it shows geometry, which is the half that can
    // be rebuilt from the position the report already carries.
    expect($answer['over'])->toBe(['normal', 'walls']);

    // And when even the photograph will not fit on its own, it goes too.
    //
    // That looks like giving up and is the opposite. A picture over the limit
    // does not arrive — it takes the spot, the room and the words down with it
    // as a 413, and the thing being reported may not come back. Sending none of
    // them delivers everything else, which is most of what diagnoses a fault.
    expect($answer['wayOver'])->toBe([]);
});
