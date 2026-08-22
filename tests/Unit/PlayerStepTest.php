<?php

use Symfony\Component\Process\Process;

/**
 * One step of the player, and the order the three questions are asked in.
 *
 * Collide, cross a portal, refuse to leave the floor plan — and it has to be
 * that order. A portal is asked about *before* the floor plan, because walking
 * into one leaves the room by design: the step that crosses a mouth lands
 * outside every sector until it is carried through to the far one, and a floor
 * plan asked first refuses it as walking into nothing. Four rules in
 * .ai/rules/engine.md describe this ordering and until now nothing pinned any
 * of them, because it lived inside a .tsx the harness cannot load.
 */

/**
 * @return array<string, mixed>
 */
function playerStep(string $body): array
{
    $script = <<<JS
        const {
            spawnPlayer,
            turnPlayer,
            walkPlayer,
            fallPlayer,
            jumpPlayer,
            settleEye,
        } = await import('@/lib/engine/player.ts');
        const { createPortals } = await import('@/lib/engine/portals.ts');
        const { sectorAt } = await import('@/lib/engine/sectors.ts');

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

        const level = (sectors, spawn) => ({
            slug: 'test',
            name: 'test',
            spawn,
            sectors,
            things: [],
        });

        /** A wall across the middle of a room, as a collider. */
        const wall = (x1, z1, x2, z2) => ({
            kind: 'segment',
            x1,
            z1,
            x2,
            z2,
        });

        const round = (value) => Number(value.toFixed(4));
        const where = (player) => ({
            x: round(player.x),
            z: round(player.z),
            yaw: round(player.yaw),
            walked: round(player.walked),
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

it('negates the level spawn angle and takes a snapshot yaw as written', function (): void {
    $answer = playerStep(<<<'JS'
        const only = room('only', [
            corner(0, 0), corner(10, 0), corner(10, 10), corner(0, 10),
        ]);
        const built = level([only], { x: 5, z: 5, angle: 90 });

        const spawned = spawnPlayer(built, null);
        const forced = spawnPlayer(built, { x: 2, z: 3, yaw: 90, pitch: 12 });

        process.stdout.write(JSON.stringify({
            spawnedYaw: round(spawned.yaw),
            forcedYaw: round(forced.yaw),
            forcedPitch: round(forced.pitch),
            forcedAt: [forced.x, forced.z],
        }));
        JS);

    // The level's angle runs the other way to the player's yaw. That is the
    // whole reason `?at=` exists: feeding a snapshot's yaw in as a spawn angle
    // aims the camera somewhere else entirely, and quietly — the view looks
    // plausible, it is simply not the view that was reported.
    expect($answer['spawnedYaw'])->toBe(-1.5708)
        ->and($answer['forcedYaw'])->toBe(1.5708)
        ->and($answer['forcedPitch'])->toBe(0.2094)
        ->and($answer['forcedAt'])->toBe([2, 3]);
});

it('refuses a step that would leave the floor plan, and does not count it', function (): void {
    $answer = playerStep(<<<'JS'
        const only = room('only', [
            corner(0, 0), corner(10, 0), corner(10, 10), corner(0, 10),
        ]);
        const built = level([only], { x: 5, z: 5, angle: 0 });
        const world = { sectors: built.sectors, colliders: [], portals: [] };

        // Facing due north with nothing in the way but the edge of the plan,
        // walked at for long enough to be well outside it.
        const player = spawnPlayer(built, { x: 5, z: 0.2, yaw: 0, pitch: 0 });

        for (let step = 0; step < 20; step++) {
            walkPlayer(player, { forward: 1, strafe: 0, running: true }, world, 0.05);
        }

        process.stdout.write(JSON.stringify({ at: where(player) }));
        JS);

    // Collision alone does not keep the player in: this room has no colliders
    // at all. The floor plan is the backstop, and a step that lands off it is
    // dropped whole — position and tally together.
    expect($answer['at'])->toEqual(['x' => 5, 'z' => 0.2, 'yaw' => 0, 'walked' => 0]);
});

it('counts the distance asked for, not the distance covered', function (): void {
    $answer = playerStep(<<<'JS'
        const only = room('only', [
            corner(0, 0), corner(10, 0), corner(10, 10), corner(0, 10),
        ]);
        const built = level([only], { x: 5, z: 5, angle: 0 });

        // A wall right in front of them, inside the room.
        const world = {
            sectors: built.sectors,
            colliders: [wall(0, 4, 10, 4)],
            portals: [],
        };

        const player = spawnPlayer(built, { x: 5, z: 5, yaw: 0, pitch: 0 });

        for (let step = 0; step < 20; step++) {
            walkPlayer(player, { forward: 1, strafe: 0, running: false }, world, 0.05);
        }

        process.stdout.write(JSON.stringify({ at: where(player) }));
        JS);

    // Pushed into a wall for a second, they have gone nowhere much but the
    // tally has run on — which is what swings the arms and picks the walk
    // frame. Walking on the spot is meant to look like walking.
    expect($answer['at']['z'])->toBeGreaterThan(4.3)
        ->and($answer['at']['walked'])->toBeGreaterThan(2.0);
});

it('asks the portal before it asks the floor plan', function (): void {
    $answer = playerStep(<<<'JS'
        // Two rooms that do not touch, joined only by a portal. The step that
        // crosses the mouth lands in the gap between them, where there is no
        // sector at all.
        const here = room('here', [
            corner(0, 0), corner(4, 0), corner(4, 4, { portalLink: 'gap' }), corner(0, 4),
        ]);
        const there = room('there', [
            corner(20, 0), corner(24, 0), corner(24, 4), corner(20, 4, { portalLink: 'gap' }),
        ]);
        const built = level([here, there], { x: 2, z: 2, angle: 0 });

        const portals = createPortals(built.sectors);
        const world = { sectors: built.sectors, colliders: [], portals };

        // Standing just inside the mouth, walking into it.
        const player = spawnPlayer(built, { x: 2, z: 3.8, yaw: 180, pitch: 0 });

        const before = where(player);

        for (let step = 0; step < 6; step++) {
            walkPlayer(player, { forward: 1, strafe: 0, running: false }, world, 0.05);
        }

        process.stdout.write(JSON.stringify({
            mouths: portals.length,
            before,
            after: where(player),
            room: sectorAt(built.sectors, player.x, player.z)?.slug ?? null,
        }));
        JS);

    // Carried bodily to the far mouth. Ask the floor plan first and this step
    // is refused as walking into nothing, and the portal never fires at all —
    // which is the bug the ordering exists to prevent.
    expect($answer['mouths'])->toBe(2)
        ->and($answer['room'])->toBe('there')
        ->and($answer['after']['x'])->toBeGreaterThan(19.0);
});

it('brings the eye down into the water and back out of it', function (): void {
    $answer = playerStep(<<<'JS'
        const dry = room('dry', [
            corner(0, 0), corner(10, 0), corner(10, 10), corner(0, 10),
        ]);
        const wet = room('wet', [
            corner(0, 0), corner(10, 0), corner(10, 10), corner(0, 10),
        ], { isWater: true });

        const built = level([dry], { x: 5, z: 5, angle: 0 });
        const player = spawnPlayer(built, null);

        const dryEye = round(player.eye);

        // Long enough to settle: the eye catches up rather than jumping.
        for (let step = 0; step < 200; step++) {
            settleEye(player, wet, 0.05);
        }

        const wetEye = round(player.eye);

        for (let step = 0; step < 200; step++) {
            settleEye(player, dry, 0.05);
        }

        process.stdout.write(JSON.stringify({
            dryEye,
            wetEye,
            backOut: round(player.eye),
        }));
        JS);

    // Down by WADE_DEPTH and back, without ever jumping there.
    expect($answer['wetEye'])->toBeLessThan($answer['dryEye'])
        ->and($answer['backOut'])->toBe($answer['dryEye']);
});

it('will not let the neck bend further than it bends', function (): void {
    $answer = playerStep(<<<'JS'
        const only = room('only', [
            corner(0, 0), corner(10, 0), corner(10, 10), corner(0, 10),
        ]);
        const built = level([only], { x: 5, z: 5, angle: 0 });
        const player = spawnPlayer(built, null);

        for (let step = 0; step < 100; step++) {
            turnPlayer(player, { yaw: 0.1, pitch: 0.5 });
        }

        const up = round(player.pitch);

        for (let step = 0; step < 200; step++) {
            turnPlayer(player, { yaw: 0, pitch: -0.5 });
        }

        process.stdout.write(JSON.stringify({
            up,
            down: round(player.pitch),
            yaw: round(player.yaw),
        }));
        JS);

    // Yaw runs on for ever; pitch stops where a neck does, both ways.
    expect($answer['up'])->toBe(-$answer['down'])
        ->and($answer['up'])->toBeLessThan(1.6)
        ->and($answer['yaw'])->toEqual(10);
});

it('gives each person their own eye, below their own head', function (): void {
    $answer = playerStep(<<<'JS'
        const { eyeHeightOf } = await import('@/lib/engine/player.ts');
        const { HEIGHTS } = await import('@/lib/engine/sprite-actor.ts');

        const only = room('only', [
            corner(0, 0), corner(10, 0), corner(10, 10), corner(0, 10),
        ]);

        const eyes = Object.fromEntries(
            Object.keys(HEIGHTS).map((who) => [
                who,
                round(spawnPlayer(
                    { ...level([only], { x: 5, z: 5, angle: 0 }), playerSprite: who },
                    null,
                ).eye),
            ]),
        );

        process.stdout.write(JSON.stringify({
            eyes,
            heights: HEIGHTS,
            stranger: round(eyeHeightOf('nobody-by-that-name')),
        }));
        JS);

    // Every person's eye is below the top of their own head. It was one number
    // for all six — 1.62, which is exactly Luke's height — so William at 1.55
    // stood seven centimetres above his own head and looked down on his own
    // reflection. A mirror is the only place the camera and the body are both
    // on screen, which is where Paul saw it.
    foreach ($answer['heights'] as $who => $height) {
        expect($answer['eyes'][$who])->toBeLessThan($height);
    }

    // And they are in the order the people are. Shortest to tallest, this is
    // the assertion that would have failed the day HEIGHTS was written.
    expect($answer['eyes']['william'])->toBeLessThan($answer['eyes']['luke'])
        ->and($answer['eyes']['luke'])->toBeLessThan($answer['eyes']['luna'])
        ->and($answer['eyes']['luna'])->toBeLessThan($answer['eyes']['krystal'])
        ->and($answer['eyes']['krystal'])->toBeLessThan($answer['eyes']['wade'])
        ->and($answer['eyes']['wade'])->toBeLessThan($answer['eyes']['paul'])
        // Somebody nobody has measured gets the default stature, not a guess.
        ->and($answer['stranger'])->toBeGreaterThan(1.5);
});

it('lands on the floor from a fall far faster than a frame of walking', function (): void {
    $answer = playerStep(<<<'JS'
        const { MAX_FRAME_SECONDS, PLAYER_RADIUS } = await import(
            '@/lib/engine/constants.ts'
        );

        const hall = room('hall', [
            corner(0, 0), corner(10, 0), corner(10, 10), corner(0, 10),
        ], { ceilingHeight: 20 });

        const built = level([hall], { x: 5, z: 5, angle: 0 });
        const player = spawnPlayer(built, null);

        // Dropped from the ceiling of a room the size level 8 actually has.
        player.y = 15;
        player.footing = false;

        let fastestFrame = 0;
        let deepest = player.y;
        let frames = 0;

        while (!player.footing && frames < 400) {
            const was = player.y;

            fallPlayer(player, hall, MAX_FRAME_SECONDS);

            fastestFrame = Math.max(fastestFrame, was - player.y);
            deepest = Math.min(deepest, player.y);
            frames++;
        }

        process.stdout.write(JSON.stringify({
            landedAt: round(player.y),
            footing: player.footing,
            fall: round(player.fall),
            fastestFrame: round(fastestFrame),
            deepest: round(deepest),
            sidewaysLimit: round(2 * PLAYER_RADIUS),
            frames,
        }));
        JS);

    // The point of the numbers: one frame of this fall covers more ground than
    // the sideways solver is allowed to, and it still lands *on* the floor and
    // never once below it. A floor is a plane under the whole room rather than
    // an infinitely thin segment, so there is no far side of it to arrive on —
    // which is why the fall needs no sub-stepping while walking into a wall
    // would.
    expect($answer['fastestFrame'])->toBeGreaterThan($answer['sidewaysLimit'])
        ->and($answer['landedAt'])->toEqual(0)
        ->and($answer['deepest'])->toEqual(0)
        ->and($answer['footing'])->toBeTrue()
        ->and($answer['fall'])->toEqual(0);
});

it('takes a step down as a step and a drop as a fall', function (): void {
    $answer = playerStep(<<<'JS'
        const { MAX_STEP } = await import('@/lib/engine/constants.ts');

        const square = [corner(0, 0), corner(10, 0), corner(10, 10), corner(0, 10)];

        const tread = room('tread', square, { floorHeight: -MAX_STEP });
        const pit = room('pit', square, { floorHeight: -MAX_STEP - 0.01 });

        const built = level([room('top', square)], { x: 5, z: 5, angle: 0 });

        const stepped = spawnPlayer(built, null);
        fallPlayer(stepped, tread, 0.05);

        const dropped = spawnPlayer(built, null);
        fallPlayer(dropped, pit, 0.05);

        process.stdout.write(JSON.stringify({
            step: { y: round(stepped.y), footing: stepped.footing },
            drop: { y: round(dropped.y), footing: dropped.footing },
            maxStep: MAX_STEP,
        }));
        JS);

    // A centimetre apart, and on purpose. `build/boundaries.ts` has already
    // ruled that a drop of MAX_STEP is walkable, so falling down one would be
    // correct and would feel wrong — a flight of stairs would become a run of
    // little drops with the eye catching up after each. One centimetre further
    // and it is a fall, feet off the ground, still on the way down after a
    // frame.
    expect($answer['step']['y'])->toEqual(-$answer['maxStep'])
        ->and($answer['step']['footing'])->toBeTrue()
        ->and($answer['drop']['footing'])->toBeFalse()
        ->and($answer['drop']['y'])->toBeGreaterThan(-$answer['maxStep'] - 0.01);
});

it('carries vertical speed through a portal unchanged', function (): void {
    $answer = playerStep(<<<'JS'
        // The same two rooms the ordering test uses, and the same walk into the
        // mouth — so that the only thing this test is asking about is what
        // happens to the fall.
        const here = room('here', [
            corner(0, 0), corner(4, 0), corner(4, 4, { portalLink: 'gap' }), corner(0, 4),
        ]);
        const there = room('there', [
            corner(20, 0), corner(24, 0), corner(24, 4), corner(20, 4, { portalLink: 'gap' }),
        ]);
        const built = level([here, there], { x: 2, z: 2, angle: 0 });

        const portals = createPortals(built.sectors);
        const world = { sectors: built.sectors, colliders: [], portals };

        const player = spawnPlayer(built, { x: 2, z: 3.8, yaw: 180, pitch: 0 });

        // Mid-fall, on the way through. `through` is a rigid turn about the
        // mouth with no y term at all, so a mouth walked into while falling has
        // to hand the fall out the other side untouched.
        player.y = 4;
        player.fall = -7.5;
        player.footing = false;

        for (let step = 0; step < 6; step++) {
            walkPlayer(player, { forward: 1, strafe: 0, running: false }, world, 0.05);
        }

        process.stdout.write(JSON.stringify({
            room: sectorAt(built.sectors, player.x, player.z)?.slug ?? null,
            y: round(player.y),
            fall: round(player.fall),
            footing: player.footing,
        }));
        JS);

    // Through, and still falling at exactly the speed it went in at. A portal
    // is a place, not a landing.
    expect($answer['room'])->toBe('there')
        ->and($answer['y'])->toEqual(4)
        ->and($answer['fall'])->toEqual(-7.5)
        ->and($answer['footing'])->toBeFalse();
});

it('lets the eye lag a step and never lag a fall', function (): void {
    $answer = playerStep(<<<'JS'
        const square = [corner(0, 0), corner(10, 0), corner(10, 10), corner(0, 10)];
        const hall = room('hall', square, { ceilingHeight: 20 });

        const built = level([hall], { x: 5, z: 5, angle: 0 });

        // On the ground, floor pulled out from under the eye: it catches up
        // over several frames rather than arriving in one.
        const stepping = spawnPlayer(built, null);
        stepping.y = 0.5;

        settleEye(stepping, hall, 0.05);

        const afterOneStepFrame = round(stepping.eye - stepping.eyeAbove);

        // In the air: the eye is where the head is, this frame and every frame.
        const falling = spawnPlayer(built, null);
        falling.y = 15;
        falling.footing = false;

        settleEye(falling, hall, 0.05);

        const gaps = [];

        while (falling.footing === false) {
            fallPlayer(falling, hall, 0.05);
            settleEye(falling, hall, 0.05);

            if (falling.footing === false) {
                gaps.push(Math.abs(round(falling.eye - falling.eyeAbove - falling.y)));
            }
        }

        process.stdout.write(JSON.stringify({
            afterOneStepFrame,
            frames: gaps.length,
            worstGap: Math.max(...gaps),
        }));
        JS);

    // Half a metre of floor arrives over several frames — that is what makes a
    // step read as a step. Nine metres of fall arrives immediately, every
    // frame: smoothed, the camera would trail the body the whole way down and
    // the landing would be felt before it was seen.
    expect($answer['afterOneStepFrame'])->toBeGreaterThan(0.0)
        ->and($answer['afterOneStepFrame'])->toBeLessThan(0.5)
        ->and($answer['frames'])->toBeGreaterThan(20)
        ->and($answer['worstGap'])->toEqual(0);
});

it('jumps high enough to land on the ledge that was the point of it', function (): void {
    $answer = playerStep(<<<'JS'
        const { GRAVITY, JUMP_SPEED, MAX_FRAME_SECONDS } = await import(
            '@/lib/engine/constants.ts'
        );

        const hall = room('hall', [
            corner(0, 0), corner(10, 0), corner(10, 10), corner(0, 10),
        ], { ceilingHeight: 20 });

        const built = level([hall], { x: 5, z: 5, angle: 0 });
        const player = spawnPlayer(built, null);

        jumpPlayer(player);

        // A second jump, in the air, on the frame after the first. It has to be
        // refused, or holding the key climbs.
        jumpPlayer(player);

        const speedAfterTwo = round(player.fall);

        let apex = player.y;
        let frames = 0;

        while (!player.footing && frames < 400) {
            fallPlayer(player, hall, MAX_FRAME_SECONDS);
            apex = Math.max(apex, player.y);
            frames++;
        }

        process.stdout.write(JSON.stringify({
            speedAfterTwo,
            apex: round(apex),
            exactly: round((JUMP_SPEED * JUMP_SPEED) / (2 * GRAVITY)),
            landedAt: round(player.y),
            airborne: round(frames * MAX_FRAME_SECONDS),
        }));
        JS);

    // The height is the whole reason for the number: a 0.8 m ledge has to
    // become somewhere you can get to, and a jump that reached 0.8 m exactly
    // would make it barely, theoretically possible rather than possible. The
    // apex measured frame by frame comes in just under the arithmetic, which is
    // what sampling a parabola fifty times a second does and is worth seeing
    // rather than rounding away.
    expect($answer['exactly'])->toBeGreaterThan(0.89)
        ->and($answer['apex'])->toBeGreaterThan(0.85)
        ->and($answer['apex'])->toBeLessThanOrEqual($answer['exactly'])
        ->and($answer['landedAt'])->toEqual(0)
        ->and($answer['airborne'])->toBeGreaterThan(0.8);

    // Jumping again in mid-air does nothing at all — not even a little.
    expect($answer['speedAfterTwo'])->toEqual(4.2);
});

it('jumps the same height on a slow machine as on a fast one', function (): void {
    $answer = playerStep(<<<'JS'
        const { MAX_FRAME_SECONDS } = await import('@/lib/engine/constants.ts');

        const hall = room('hall', [
            corner(0, 0), corner(10, 0), corner(10, 10), corner(0, 10),
        ], { ceilingHeight: 20 });

        const built = level([hall], { x: 5, z: 5, angle: 0 });

        const apexAt = (seconds) => {
            const player = spawnPlayer(built, null);
            jumpPlayer(player);

            let apex = player.y;

            while (!player.footing) {
                fallPlayer(player, hall, seconds);
                apex = Math.max(apex, player.y);
            }

            return round(apex);
        };

        process.stdout.write(JSON.stringify({
            // A stalled tab, 144 Hz, and the two frame rates most people
            // actually have.
            slow: apexAt(MAX_FRAME_SECONDS),
            sixty: apexAt(1 / 60),
            oneTwenty: apexAt(1 / 120),
            fast: apexAt(1 / 144),
        }));
        JS);

    // Within a millimetre across a threefold change in frame rate, and the
    // spread is sampling a parabola rather than integrating it wrong.
    //
    // Stepping the position by the speed at the *end* of the frame would put
    // these at 0.797, 0.858, 0.878 and 0.882 — which is not a rounding error,
    // it is how high you can jump depending on how fast your computer is, and
    // the slow end of it is under the 0.8 m ledge the height exists to clear.
    $apexes = [$answer['slow'], $answer['sixty'], $answer['oneTwenty'], $answer['fast']];

    expect(max($apexes) - min($apexes))->toBeLessThan(0.005)
        ->and(min($apexes))->toBeGreaterThan(0.85);
});

it('stops a jump dead against the ceiling and does not stick there', function (): void {
    $answer = playerStep(<<<'JS'
        const square = [corner(0, 0), corner(10, 0), corner(10, 10), corner(0, 10)];

        // A cellar: floor to ceiling is the player's own height plus 40 cm, so
        // there is room to stand and not much room to jump.
        const cellar = room('cellar', square, { ceilingHeight: 0 });

        const built = level([room('hall', square)], { x: 5, z: 5, angle: 0 });
        const player = spawnPlayer(built, null);

        cellar.ceilingHeight = player.stature + 0.4;

        jumpPlayer(player);

        const heights = [];

        while (!player.footing && heights.length < 400) {
            fallPlayer(player, cellar, 0.05);
            heights.push(round(player.y));
        }

        const highest = Math.max(...heights);
        const peak = heights.indexOf(highest);
        const after = heights.slice(peak);

        // A room nobody can stand up in. The floor wins: pushing the feet
        // through it to make room for the head is a worse answer to bad
        // authoring than leaving somebody standing in it.
        const squashed = spawnPlayer(built, null);
        const crawlspace = room('crawlspace', square, { ceilingHeight: 0 });
        crawlspace.ceilingHeight = squashed.stature - 0.5;

        jumpPlayer(squashed);
        fallPlayer(squashed, crawlspace, 0.05);

        process.stdout.write(JSON.stringify({
            headroom: round(0.4),
            highest: round(highest),
            roseAgain: after.some((height, at) => at > 0 && height > after[at - 1]),
            landedAt: round(player.y),
            squashedAt: round(squashed.y),
            squashedFooting: squashed.footing,
        }));
        JS);

    // Stopped at the ceiling, not through it and not bounced off it: the head
    // loses the speed it had and what happens next is gravity's, exactly as at
    // the top of any other jump. Nothing goes upward again after the bump — a
    // ceiling that pushed back would have somebody climbing it.
    expect($answer['highest'])->toEqual($answer['headroom'])
        ->and($answer['roseAgain'])->toBeFalse()
        ->and($answer['landedAt'])->toEqual(0);

    // And the room too short to stand up in leaves them standing on the floor
    // rather than sunk into it.
    expect($answer['squashedAt'])->toEqual(0)
        ->and($answer['squashedFooting'])->toBeTrue();
});
