<?php

use Symfony\Component\Process\Process;

/**
 * People walking through portals.
 *
 * `NavigationTest` already pins that the graph counts a portal as a way
 * through, which is Paul's ruling and has been true since A-18. What was never
 * built is the other half: **nothing but `player.ts` could cross a mouth.**
 *
 * So a wanderer whose route said "through the stairs portal" walked to the
 * mouth and stopped there. It did not even stop against anything — a mouth
 * carries no collider, and its waypoint is aimed a stride *past* the plane, so
 * the spot it was pressing at had no floor under it and arriving there was not
 * something that could happen. It pushed, made no progress, and gave up after
 * STUCK_SECONDS to pick somewhere else: exactly the behaviour pathfinding was
 * built to end, reproduced by pathfinding. Paul: *"characters are not going
 * through portals for me."*
 */

/**
 * @return array<string, mixed>
 */
function actorAnswer(string $body): array
{
    $script = <<<JS
        const blank = () => ({
            width: 0,
            height: 0,
            style: {},
            addEventListener() {},
            removeEventListener() {},
            getContext: () => null,
        });

        globalThis.document = { createElementNS: blank, createElement: blank };

        const { createActors } = await import('@/lib/engine/actors.ts');
        const { sectorAt } = await import('@/lib/engine/sectors.ts');

        const corner = (x, z, extra = {}) => ({
            x, z, blocks: false, wallTexture: null, isMirror: false,
            isSky: false, portalLink: null, ...extra,
        });

        const room = (slug, points) => ({
            slug, name: slug, floorHeight: 0, ceilingHeight: 3,
            floorTexture: null, ceilingTexture: null, wallTexture: null,
            isSky: false, isWater: false, points,
        });

        const person = (x, z) => ({
            slug: 'walker', name: 'Walker', description: '', kind: 'actor',
            sprite: 'krystal', behaviour: 'wander', stats: null, speed: 2,
            texture: null, render: 'billboard', planeCount: 2, uvMode: 'fit',
            textureAlt: null, altFlag: null, animationFrames: 1,
            animationFps: 1, x, z, elevation: 0, width: 0.6, depth: 0.6,
            height: 1.7, angle: 0, isSolid: false, isDoor: false,
            swing: 'swing', openAngle: 90, openSeconds: 0.35, isOpen: false,
            opensFlag: null, verbs: [],
        });

        // Two rooms nowhere near each other, joined by nothing but a portal.
        // Every wall is solid, so there is no other way out of 'here' and a
        // route to 'there' has to be the portal or nothing.
        const level = {
            slug: 'test', name: 'Test', description: '',
            spawn: { x: 2, z: 2, angle: 0 }, ceilingHeight: 3,
            spriteStyle: 'realistic', playerSprite: 'paul',
            wallColor: '#ffffff', floorColor: '#888888', accentColor: '#ffcc00',
            sky: null, playerStats: null,
            things: [person(2, 2)],
            sectors: [
                room('here', [
                    corner(0, 0, { blocks: true }),
                    corner(4, 0, { blocks: true }),
                    corner(4, 4, { blocks: true, portalLink: 'hop' }),
                    corner(0, 4, { blocks: true }),
                ]),
                room('there', [
                    corner(50, 0, { blocks: true }),
                    corner(54, 0, { blocks: true }),
                    corner(54, 4, { blocks: true }),
                    corner(50, 4, { blocks: true, portalLink: 'hop' }),
                ]),
            ],
        };

        /**
         * `aimSomewhere` picks a room with Math.random. Pinned so the walk is
         * the same every run: 0.99 lands on the last room reachable, which is
         * the far side of the portal, since the near one is always first.
         */
        Math.random = () => 0.99;

        /** Walks everybody for a while and says where the walker ended up. */
        const walkFor = (seconds) => {
            const actors = createActors(level);
            const step = 1 / 60;
            const rooms = [];

            for (let frame = 0; frame < seconds * 60; frame++) {
                actors.update(step, []);

                const at = actors.positionOf('walker');
                const room = sectorAt(level.sectors, at.x, at.z);

                rooms.push(room === null ? 'nowhere' : room.slug);
            }

            const at = actors.positionOf('walker');

            return {
                rooms,
                visited: [...new Set(rooms)],
                endedAt: { x: Number(at.x.toFixed(2)), z: Number(at.z.toFixed(2)) },
            };
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

it('walks a person through a portal and leaves them on the far side', function (): void {
    $answer = actorAnswer(<<<'JS'
        process.stdout.write(JSON.stringify(walkFor(8)));
        JS);

    // Four seconds of walking at two metres a second is more than enough to
    // cross a four-metre room and go through. Before this, the walker spent
    // every one of those frames in 'here', shuffling at a waypoint on the far
    // side of a wall it could not pass and re-picking a destination it could
    // not reach.
    expect($answer['visited'])->toContain('there')
        ->and($answer['endedAt']['x'])->toBeGreaterThan(49.0);
});

it('does not turn round and come straight back through', function (): void {
    $answer = actorAnswer(<<<'JS'
        const walk = walkFor(8);
        const marks = [];

        for (let at = 1; at < walk.rooms.length; at++) {
            if (walk.rooms[at] !== walk.rooms[at - 1]) {
                marks.push(at);
            }
        }

        // Frames between one crossing and the next. Wandering back and forth
        // is what a wanderer does — with the random pinned it is *all* it
        // does here, since the only other room is always the one it has not
        // just been in. What must never happen is a crossing that undoes
        // itself in the frames right after it.
        const gaps = marks.map((at, index) =>
            index === 0 ? at : at - marks[index - 1],
        );

        process.stdout.write(JSON.stringify({
            marks,
            shortestGap: Math.min(...gaps),
            endedAt: walk.endedAt,
            visited: walk.visited,
        }));
        JS);

    // The trap a crossing sets for itself. A mouth's waypoint sits a stride
    // *past* the plane, which is to say in the room being left, once you are
    // through it — so carrying a walker over without also taking that waypoint
    // off their list turns them round on the spot and sends them straight
    // back. It looks like a portal firing twice a second.
    //
    // At sixty frames a second, a second of walking between crossings is the
    // shortest a real change of mind can be: it takes a re-aim, a route, and a
    // walk back to the mouth.
    expect($answer['marks'])->not->toBeEmpty()
        ->and($answer['shortestGap'])->toBeGreaterThanOrEqual(60);

    // And never outside the floor plan on the way. The step that crosses a
    // mouth lands in no sector at all until something carries it through, so
    // if the ordering were wrong — floor plan asked before the portal — this
    // would read 'nowhere' rather than crossing.
    expect($answer['visited'])->not->toContain('nowhere');
});
