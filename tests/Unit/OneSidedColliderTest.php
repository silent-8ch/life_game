<?php

use Symfony\Component\Process\Process;

/**
 * A wall that is solid from one side only.
 *
 * Collision is solved on the floor plan, where a wall is a line segment — and a
 * line has no sides, so an ordinary collider pushes back whichever direction it
 * is approached from. That is right for a wall and wrong for the far face of a
 * portal, which has to be a wall to the room behind it and nothing at all to the
 * room walking into the mouth.
 */

/**
 * @return array<string, mixed>
 */
function colliderAnswer(string $body): array
{
    $script = <<<JS
        const { moveWithCollisions, resolveCollisions } =
            await import('@/lib/engine/collision.ts');

        const RADIUS = 0.34;

        /** A wall along z = 0, running from x = 0 to x = 10. */
        const wall = (facing) => ({
            kind: 'segment',
            x1: 0,
            z1: 0,
            x2: 10,
            z2: 0,
            ...(facing === undefined ? {} : { facing }),
        });

        const round = (value) => Number(value.toFixed(3));
        const at = (point) => [round(point.x), round(point.z)];

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

it('pushes back from the side it is solid from', function (): void {
    $answer = colliderAnswer(<<<'JS'
        // Solid from the positive z side, which is where this walker is.
        const walls = [wall({ x: 0, z: 1 })];

        process.stdout.write(JSON.stringify({
            approaching: at(moveWithCollisions({ x: 5, z: 0.4 }, 0, -0.3, walls, RADIUS)),
            wellClear: at(moveWithCollisions({ x: 5, z: 2 }, 0, -0.3, walls, RADIUS)),
        }));
        JS);

    // Stopped a whole radius short, and untouched while still well clear.
    expect($answer['approaching'])->toEqual([5, 0.34])
        ->and($answer['wellClear'])->toEqual([5, 1.7]);
});

it('is not there at all from the other side', function (): void {
    $answer = colliderAnswer(<<<'JS'
        const walls = [wall({ x: 0, z: 1 })];

        process.stdout.write(JSON.stringify({
            // Walking towards it from the open side, and over it.
            nearly: at(moveWithCollisions({ x: 5, z: -0.4 }, 0, 0.3, walls, RADIUS)),
            over: at(moveWithCollisions({ x: 5, z: -0.05 }, 0, 0.1, walls, RADIUS)),
        }));
        JS);

    // Walked right up to and onto the line, where an ordinary wall would have
    // stopped them a third of a metre out.
    expect($answer['nearly'])->toEqual([5, -0.1]);

    // The step that carries them over lands them clear on the far side rather
    // than half inside the wall. That is the step a portal crosses on: the far
    // face of a mouth is the same segment as the mouth, so the crossing that
    // triggers this is the crossing that carries the player through.
    expect($answer['over'])->toEqual([5, 0.34]);
});

it('counts standing exactly on it as being on the open side', function (): void {
    $answer = colliderAnswer(<<<'JS'
        const walls = [wall({ x: 0, z: 1 })];

        process.stdout.write(JSON.stringify({ onIt: at(resolveCollisions({ x: 5, z: 0 }, walls, RADIUS)) }));
        JS);

    // From the solid side the player is stopped a whole radius short and can
    // never reach the line, so anybody standing on it walked in from the front
    // and is on their way through. Shoving them back would bounce them off a
    // portal mouth they had already entered.
    expect($answer['onIt'])->toEqual([5, 0]);
});

it('stops a wall with no side to it from either direction', function (): void {
    $answer = colliderAnswer(<<<'JS'
        const walls = [wall()];

        process.stdout.write(JSON.stringify({
            fromAbove: at(moveWithCollisions({ x: 5, z: 0.4 }, 0, -0.3, walls, RADIUS)),
            fromBelow: at(moveWithCollisions({ x: 5, z: -0.4 }, 0, 0.3, walls, RADIUS)),
        }));
        JS);

    // Every other wall in a level is this one: solid whichever way you meet it.
    expect($answer['fromAbove'])->toEqual([5, 0.34])
        ->and($answer['fromBelow'])->toEqual([5, -0.34]);
});

it('slides along a one-sided wall the way it slides along any other', function (): void {
    $answer = colliderAnswer(<<<'JS'
        const walls = [wall({ x: 0, z: 1 })];

        // Pushing diagonally into it from the solid side.
        let point = { x: 5, z: 0.5 };

        for (let i = 0; i < 20; i++) {
            point = moveWithCollisions(point, 0.05, -0.05, walls, RADIUS);
        }

        process.stdout.write(JSON.stringify({ ended: at(point) }));
        JS);

    // The move into the wall is undone and the move along it is not, so the
    // walker ends up a radius clear and a good way further along.
    expect($answer['ended'][1])->toEqual(0.34)
        ->and($answer['ended'][0])->toBeGreaterThan(5.9);
});
