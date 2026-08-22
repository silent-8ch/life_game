<?php

use Symfony\Component\Process\Process;

/**
 * The edits the editor makes to a room without redrawing it: nudging its floor
 * and ceiling by the keyboard, and copying it.
 *
 * Heights are the fiddly half. They snap to a tenth of a metre, which floating
 * point will not add up on its own, and a floor pushed up far enough has to
 * stop before it reaches its own ceiling — the same limit the section's drag
 * holds to, because the two are the one gesture done differently.
 *
 * A copy has to come away with a slug no other room is using, or the level
 * saves two rooms under one name and one of them wins.
 */

/**
 * Edits a level in the editor and answers a question about the result.
 *
 * @param  string  $sectors  A JavaScript array of rooms.
 * @return array<string, mixed>
 */
function editedLevel(string $sectors, string $body): array
{
    $script = <<<JS
        const { duplicateRooms, duplicateSector, nudgeHeights } = await import('@/lib/editor/map.ts');

        const corner = (x, z, extra = {}) => ({
            x,
            z,
            wallTexture: null,
            blocks: false,
            isMirror: false,
            isSky: false,
            portalLink: null,
            ...extra,
        });

        const room = (slug, points, extra = {}) => ({
            slug,
            name: slug,
            floorHeight: 0,
            ceilingHeight: 3,
            floorTexture: null,
            ceilingTexture: null,
            wallTexture: null,
            isSky: false,
            isWater: false,
            points,
            ...extra,
        });

        /** A rectangular room, by the two corners a drag would give it. */
        const box = (slug, minX, minZ, maxX, maxZ, extra = {}) =>
            room(
                slug,
                [
                    corner(minX, minZ),
                    corner(maxX, minZ),
                    corner(maxX, maxZ),
                    corner(minX, maxZ),
                ],
                extra,
            );

        const level = {
            slug: 'test',
            name: 'test',
            description: '',
            spawn: { x: 1, z: 1, angle: 0 },
            ceilingHeight: 3,
            spriteStyle: 'realistic',
            playerSprite: 'paul',
            wallColor: '#ffffff',
            floorColor: '#888888',
            accentColor: '#ffcc00',
            sky: null,
            things: [],
            sectors: {$sectors},
        };

        /** A room's floor and ceiling, which is all the nudge touches. */
        const heightsOf = (edited) =>
            edited.sectors.map((sector) => [sector.floorHeight, sector.ceilingHeight]);

        /** A room's corners, in the order it holds them. */
        const shapeOf = (sector) => sector.points.map(({ x, z }) => [x, z]);

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

it('moves a floor by a tenth of a metre a press, either way', function (): void {
    $answer = editedLevel("[box('hall', 0, 0, 4, 4)]", <<<'JS'
        const up = nudgeHeights(level, [0], 'floor', 1);
        const down = nudgeHeights(level, [0], 'floor', -1);

        process.stdout.write(JSON.stringify({
            up: heightsOf(up),
            down: heightsOf(down),
            // Three presses running: 0.1 + 0.1 + 0.1 is famously not 0.3.
            thrice: heightsOf(
                [1, 2, 3].reduce((current) => nudgeHeights(current, [0], 'floor', 1), level),
            ),
        }));
        JS);

    expect($answer['up'])->toBe([[0.1, 3]])
        ->and($answer['down'])->toBe([[-0.1, 3]])
        ->and($answer['thrice'])->toBe([[0.3, 3]]);
});

it('takes the ceiling instead when the shift key is down', function (): void {
    $answer = editedLevel("[box('hall', 0, 0, 4, 4)]", <<<'JS'
        process.stdout.write(JSON.stringify({
            raised: heightsOf(nudgeHeights(level, [0], 'ceiling', 2)),
        }));
        JS);

    expect($answer['raised'])->toBe([[0, 3.2]]);
});

it('nudges every picked room and leaves the rest alone', function (): void {
    $answer = editedLevel(
        "[box('hall', 0, 0, 4, 4), box('step', 4, 0, 6, 4), box('yard', 6, 0, 10, 4)]",
        <<<'JS'
        process.stdout.write(JSON.stringify({
            two: heightsOf(nudgeHeights(level, [0, 2], 'floor', 5)),
        }));
        JS
    );

    expect($answer['two'])->toBe([[0.5, 3], [0, 3], [0.5, 3]]);
});

it('stops a floor before it reaches its own ceiling, and a ceiling before its floor', function (): void {
    $answer = editedLevel("[box('hall', 0, 0, 4, 4)]", <<<'JS'
        process.stdout.write(JSON.stringify({
            // Forty presses up on a three metre room.
            floor: heightsOf(nudgeHeights(level, [0], 'floor', 40)),
            ceiling: heightsOf(nudgeHeights(level, [0], 'ceiling', -40)),
        }));
        JS);

    // A fifth of a metre left between them, the same gap the section's drag
    // leaves, so a room never closes up on itself.
    expect($answer['floor'])->toBe([[2.8, 3]])
        ->and($answer['ceiling'])->toBe([[0, 0.2]]);
});

it('copies a room a metre off, with a slug nothing else is using', function (): void {
    $answer = editedLevel("[box('hall', 0, 0, 4, 4)]", <<<'JS'
        const copied = duplicateRooms(level, [0]);

        process.stdout.write(JSON.stringify({
            slugs: copied.sectors.map((sector) => sector.slug),
            names: copied.sectors.map((sector) => sector.name),
            original: shapeOf(copied.sectors[0]),
            copy: shapeOf(copied.sectors[1]),
            heights: heightsOf(copied),
        }));
        JS);

    expect($answer['slugs'])->toBe(['hall', 'hall-2'])
        ->and($answer['names'])->toBe(['hall', 'hall copy'])
        // Off the original so both can be seen, and the same shape and size.
        ->and($answer['original'])->toBe([[0, 0], [4, 0], [4, 4], [0, 4]])
        ->and($answer['copy'])->toBe([[1, 1], [5, 1], [5, 5], [1, 5]])
        ->and($answer['heights'])->toBe([[0, 3], [0, 3]]);
});

it('keeps giving out free slugs when the same room is copied again', function (): void {
    $answer = editedLevel("[box('hall', 0, 0, 4, 4)]", <<<'JS'
        const twice = duplicateRooms(duplicateRooms(level, [0]), [0]);

        process.stdout.write(JSON.stringify({
            slugs: twice.sectors.map((sector) => sector.slug),
            // Copying several at once judges each against the ones just made.
            atOnce: duplicateRooms(level, [0, 0, 0]).sectors.map((sector) => sector.slug),
        }));
        JS);

    expect($answer['slugs'])->toBe(['hall', 'hall-2', 'hall-3'])
        ->and($answer['atOnce'])->toBe(['hall', 'hall-2', 'hall-3', 'hall-4']);
});

it('copies each picked room once, in the order they were picked', function (): void {
    $answer = editedLevel(
        "[box('hall', 0, 0, 4, 4), box('step', 4, 0, 6, 4), box('yard', 6, 0, 10, 4)]",
        <<<'JS'
        process.stdout.write(JSON.stringify({
            slugs: duplicateRooms(level, [2, 0]).sectors.map((sector) => sector.slug),
        }));
        JS
    );

    expect($answer['slugs'])->toBe(['hall', 'step', 'yard', 'yard-2', 'hall-2']);
});

it('brings the walls across but not their portal links', function (): void {
    $answer = editedLevel(
        "[room('hall', [
            corner(0, 0, { wallTexture: 'brick', blocks: true }),
            corner(4, 0, { isMirror: true, portalLink: 'front-door' }),
            corner(4, 4),
            corner(0, 4),
        ])]",
        <<<'JS'
        const copy = duplicateSector(level, 0);

        process.stdout.write(JSON.stringify({
            walls: copy.points.map(({ wallTexture, blocks, isMirror, portalLink }) => ({
                wallTexture,
                blocks,
                isMirror,
                portalLink,
            })),
        }));
        JS
    );

    // A portal pairs two mouths by name, so a third wall calling itself the
    // same portal would leave the pair ambiguous.
    expect($answer['walls'][0])->toBe([
        'wallTexture' => 'brick',
        'blocks' => true,
        'isMirror' => false,
        'portalLink' => null,
    ]);

    expect($answer['walls'][1])->toBe([
        'wallTexture' => null,
        'blocks' => false,
        'isMirror' => true,
        'portalLink' => null,
    ]);
});
