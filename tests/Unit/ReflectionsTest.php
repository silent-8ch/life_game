<?php

use Symfony\Component\Process\Process;

/**
 * The order a frame draws its panes in.
 *
 * `prepareReflections` is the least visible and most load-bearing code in the
 * engine: every rule it keeps was got wrong at least once, and each of those
 * failures reads on screen as "the portal shows the sky" rather than as an
 * error. It lived unexported inside level-viewport.tsx, where the node harness
 * could not reach it. It is `lib/engine/reflections.ts` now, and these are the
 * rules it keeps, asserted on the order of calls rather than on pixels.
 *
 * The panes here are stubs that write down what was asked of them. Only the
 * cameras and the bounding spheres are real, since the frustum test is doing
 * actual work.
 */

/**
 * Runs one frame of pane preparation over a stub level and returns the log.
 *
 * @return array<string, mixed>
 */
function reflectionFrame(string $scene, string $body): array
{
    $script = <<<JS
        const THREE = await import('three');
        const { prepareReflections } = await import('@/lib/engine/reflections.ts');

        const log = [];

        /**
         * A stub pane. It records what it was asked to do and hands back a real
         * camera when aimed, because the frustum test that decides recursion is
         * the one part of this that is not stubbed.
         */
        const pane = (name, home, onto, at) => ({
            name,
            home,
            onto,
            bounds: new THREE.Sphere(new THREE.Vector3(...at), 1),
            mesh: { visible: true, name },
            partner: null,
            viewerAt: () => ({ x: name.length, z: -name.length, yaw: 0 }),
            aim: () => {
                const inner = new THREE.PerspectiveCamera(75, 1, 0.1, 100);
                inner.updateMatrixWorld(true);
                log.push('aim ' + name);

                return inner;
            },
            render: (renderer, target, from, depth) => {
                log.push(
                    'render ' + name + '@' + depth +
                    ' body=' + playerSprite.object.visible +
                    ' hidden=' + panes
                        .filter((other) => !other.mesh.visible)
                        .map((other) => other.name)
                        .join('+'),
                );
            },
            show: (depth, shrink) =>
                log.push(
                    'show ' + name + ' ' + depth +
                    (shrink === undefined ? '' : ' shrunk'),
                ),
            release: () => log.push('release ' + name),
            hug: () => log.push('hug ' + name),
        });

        const playerSprite = {
            object: { visible: false },
            faceViewer: (x, z) => log.push('player at ' + x + ',' + z),
        };

        const actors = {
            faceViewer: (x, z) => log.push('actors at ' + x + ',' + z),
        };

        const sky = {
            object: { visible: true },
            follow: (x, y, z) => log.push('sky at ' + x + ',' + z),
        };

        const camera = new THREE.PerspectiveCamera(75, 1, 0.1, 100);
        camera.updateMatrixWorld(true);

        {$scene}

        const panes = [...portals, ...mirrors];

        prepareReflections(mirrors, portals, playerSprite, actors, camera, sky)(
            { name: 'renderer' },
            { name: 'scene' },
        );

        const only = (prefix) => log.filter((line) => line.startsWith(prefix));
        const firstOf = (prefix) => log.findIndex((line) => line.startsWith(prefix));
        const lastOf = (prefix) =>
            log.length - 1 - [...log].reverse().findIndex((line) => line.startsWith(prefix));

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

/**
 * A pair of portals with a mirror in the room one of them looks into, and a
 * second mirror in a room neither can see. Everything is in front of the
 * player unless a test moves it.
 */
const REFLECTION_SCENE = <<<'JS'
    const near = pane('near', 'hall', ['vault'], [0, 0, -5]);
    const far = pane('far', 'vault', ['hall'], [0, 0, -5]);
    const glass = pane('glass', 'vault', [], [0, 0, -5]);
    const elsewhere = pane('elsewhere', 'attic', [], [0, 0, -5]);

    // Each mouth is drawn by a camera standing behind the other one.
    near.partner = far.mesh;
    far.partner = near.mesh;
    // A mirror cannot see itself.
    glass.partner = glass.mesh;
    elsewhere.partner = elsewhere.mesh;

    const portals = [near, far];
    const mirrors = [glass, elsewhere];
    JS;

it('puts every hugged pane back before it draws anything', function (): void {
    $answer = reflectionFrame(REFLECTION_SCENE, <<<'JS'
        process.stdout.write(JSON.stringify({
            releases: only('release'),
            lastRelease: lastOf('release'),
            firstRender: firstOf('render'),
        }));
        JS);

    // Without this the pane hugged at the player's face last frame is still
    // parked there while every other pane's camera renders, and a wall-sized
    // sheet in the middle of the room is what "the portal shows the sky" is.
    expect($answer['releases'])->toBe(['release near', 'release far'])
        ->and($answer['lastRelease'])->toBeLessThan($answer['firstRender']);
});

it('only follows a pane into a room the pane can see into', function (): void {
    $answer = reflectionFrame(REFLECTION_SCENE, <<<'JS'
        process.stdout.write(JSON.stringify({ renders: only('render') }));
        JS);

    $renders = array_map(
        fn (string $line): string => explode(' ', $line)[1],
        $answer['renders'],
    );

    // The mirror in the vault is drawn one level in for the near mouth's view,
    // and that pass comes first — deepest drawn first, so by the time the mouth
    // itself is drawn the mirror is already showing the view from in there.
    //
    // 'elsewhere' is in the attic, which no pane's onto list names, so it is
    // never recursed into however squarely it sits in the cone. A frustum knows
    // nothing of walls; without the filter the depth budget goes on rooms that
    // are not through the portal at all.
    expect($renders)->toBe([
        'glass@1',
        'near@0',
        'far@0',
        'glass@0',
        'elsewhere@0',
    ]);
});

it('never draws the far mouth inside its own partner', function (): void {
    $answer = reflectionFrame(REFLECTION_SCENE, <<<'JS'
        process.stdout.write(JSON.stringify({ renders: only('render') }));
        JS);

    // The far mouth is taken out of the near one's view, so drawing what it
    // holds is work for nobody — and for an ordinary pair it is the only pane
    // in the room beyond, so that whole branch disappears.
    expect($answer['renders'])->not->toContain('render far@1')
        ->and($answer['renders'])->not->toContain('render near@1');
});

it('shows the player their own view last, and hugs only for their camera', function (): void {
    $answer = reflectionFrame(REFLECTION_SCENE, <<<'JS'
        process.stdout.write(JSON.stringify({
            hugs: only('hug'),
            lastShownAtZero: lastOf('show near 0'),
            firstHug: firstOf('hug'),
            lastRender: lastOf('render'),
            tail: log.slice(-8),
        }));
        JS);

    // A pane held in front of the player's face has no business turning up in
    // another pane's view, so hugging is left until every pass has run. Mirrors
    // never hug: they are solid walls with colliders.
    expect($answer['hugs'])->toBe(['hug near', 'hug far'])
        ->and($answer['lastRender'])->toBeLessThan($answer['firstHug'])
        ->and($answer['lastShownAtZero'])->toBeLessThan($answer['firstHug']);
});

it('draws the sky around whoever is looking, and puts it back', function (): void {
    $answer = reflectionFrame(REFLECTION_SCENE, <<<'JS'
        process.stdout.write(JSON.stringify({ sky: only('sky') }));
        JS);

    // Each pane's pass is looked at from somewhere else entirely, so the dome
    // goes there for the length of it — left at the player it hangs in the far
    // room as slabs of hillside a few metres across, in front of everything.
    // The last one puts it back around the player for the main render.
    expect($answer['sky'])->toBe([
        'sky at 5,-5',
        'sky at 4,-4',
        'sky at 3,-3',
        'sky at 5,-5',
        'sky at 9,-9',
        'sky at 0,0',
    ]);
});

it('shows the player inside a pane and nowhere else', function (): void {
    $answer = reflectionFrame(REFLECTION_SCENE, <<<'JS'
        process.stdout.write(JSON.stringify({
            renders: only('render'),
            afterwards: playerSprite.object.visible,
        }));
        JS);

    foreach ($answer['renders'] as $line) {
        expect($line)->toContain('body=true');
    }

    // You cannot see yourself: the body goes away again for the main pass.
    expect($answer['afterwards'])->toBeFalse();
});

it('takes the panes out of the deepest pass rather than reading what it writes', function (): void {
    $answer = reflectionFrame(
        // Nothing is in front of the player, so no pane is allowed any depth
        // and every pass is its own deepest.
        str_replace('[0, 0, -5]', '[0, 0, 40]', REFLECTION_SCENE),
        <<<'JS'
        process.stdout.write(JSON.stringify({ renders: only('render') }));
        JS
    );

    // At depth 0 there is no level further out to borrow a view from, so the
    // panes go out of the pass entirely — a texture cannot be read and written
    // at once. Everything is drawn flat, one pass each, no recursion.
    expect($answer['renders'])->toBe([
        'render near@0 body=true hidden=near+far+glass+elsewhere',
        'render far@0 body=true hidden=near+far+glass+elsewhere',
        'render glass@0 body=true hidden=near+far+glass+elsewhere',
        'render elsewhere@0 body=true hidden=near+far+glass+elsewhere',
    ]);
});
