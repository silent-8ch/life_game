<?php

use Symfony\Component\Process\Process;

/**
 * What a snapshot says about where somebody was standing.
 *
 * The part that earns its keep is the list of boundaries within arm's reach and
 * how far the eye was from each: a flicker at a doorway is almost always
 * something deciding differently from one frame to the next about which side of
 * a line the eye is on, and standing a hair off a line is what shows in that
 * list.
 */

/**
 * @return array<string, mixed>
 */
function describeAnswer(string $body): array
{
    $script = <<<JS
        const { describeSpot } = await import('@/lib/engine/snapshot.ts');

        const corner = (x, z, extra = {}) => ({
            x,
            z,
            blocks: false,
            wallTexture: null,
            isMirror: false,
            isSky: false,
            portalLink: null,
            ...extra,
        });

        const room = (slug, points, floorHeight = 0) => ({
            slug,
            name: slug,
            floorHeight,
            ceilingHeight: 3,
            floorTexture: null,
            ceilingTexture: null,
            wallTexture: null,
            isSky: false,
            isWater: false,
            points,
        });

        const level = {
            slug: 'test',
            name: 'Test',
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
            sectors: [
                room('south', [corner(0, 0), corner(10, 0), corner(10, 10), corner(0, 10)], 0),
                room('north', [corner(0, 10), corner(10, 10), corner(10, 20), corner(0, 20)], 0.4),
            ],
        };

        const spot = (x, z, extra = {}) => describeSpot({
            level,
            x,
            z,
            eye: 1.62,
            yaw: Math.PI,
            pitch: 0,
            lookingAt: null,
            holding: null,
            running: false,
            screen: { width: 800, height: 400, pixelRatio: 2, touch: false },
            takenAt: '2026-08-21T13:45:00.000Z',
            ...extra,
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

it('says which room the eye was in and how high the floor was', function (): void {
    $answer = describeAnswer(<<<'JS'
        process.stdout.write(JSON.stringify({
            south: spot(5, 5).standingIn,
            north: spot(5, 15).standingIn,
            nowhere: spot(50, 50).standingIn,
        }));
        JS);

    expect($answer['south']['slug'])->toBe('south')
        ->and($answer['south']['floorHeight'])->toEqual(0)
        ->and($answer['north']['slug'])->toBe('north')
        ->and($answer['north']['floorHeight'])->toEqual(0.4)
        ->and($answer['nowhere'])->toBeNull();
});

it('gives the angles in degrees, which is how a person reads them', function (): void {
    $answer = describeAnswer(<<<'JS'
        process.stdout.write(JSON.stringify({ at: spot(5, 5).at }));
        JS);

    // The engine keeps yaw in radians; nobody wants to read 3.1416 in a file.
    expect($answer['at']['yaw'])->toEqual(180)
        ->and($answer['at']['pitch'])->toEqual(0)
        ->and($answer['at']['eye'])->toEqual(1.62);
});

it('lists the boundary the eye is standing on, and how far off it is', function (): void {
    $answer = describeAnswer(<<<'JS'
        // A few millimetres north of the line between the two rooms.
        const nearest = spot(5, 10.004).edgesNearby[0];

        process.stdout.write(JSON.stringify({
            nearest,
            howMany: spot(5, 10.004).edgesNearby.length,
        }));
        JS);

    // Nearest first, named once however many rooms share it, and with both
    // rooms on it so the reader knows what is either side.
    expect(abs($answer['nearest']['distance']))->toEqual(0.004)
        ->and($answer['nearest']['rooms'])->toEqualCanonicalizing(['south', 'north'])
        ->and($answer['nearest']['open'])->toBeTrue();
});

it('leaves out walls the eye is nowhere near', function (): void {
    $answer = describeAnswer(<<<'JS'
        process.stdout.write(JSON.stringify({
            middle: spot(5, 5).edgesNearby.map((edge) => edge.rooms.join('|')),
        }));
        JS);

    // Five metres from every wall of a ten metre room: nothing worth reporting.
    expect($answer['middle'])->toBe([]);
});

it('does not count a wall whose line the eye is past the end of', function (): void {
    $answer = describeAnswer(<<<'JS'
        // A metre east of the two rooms, level with the line between them. That
        // line runs right past, but the eye is off the end of it.
        process.stdout.write(JSON.stringify({
            outside: spot(11, 10).edgesNearby.map((edge) => edge.rooms.join('|')),
        }));
        JS);

    // The east walls are beside the eye and are worth reporting; the boundary
    // between the two rooms is not, or a corner would drag in every wall whose
    // line happened to run nearby.
    expect($answer['outside'])->not->toContain('south|north')
        ->and($answer['outside'])->not->toContain('north|south')
        ->and($answer['outside'])->toEqualCanonicalizing(['south', 'north']);
});
