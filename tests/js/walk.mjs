/**
 * Walks the player through a level, headless, and prints where they went.
 *
 *     node --experimental-strip-types --import ./tests/js/typescript-imports.mjs \
 *          tests/js/walk.mjs <level.json> <x> <z> <yaw> [frames] [run]
 *
 * A level.json is what `LevelPayload::forEngine` hands the browser. Get one
 * with:
 *
 *     php artisan tinker --execute '$l = App\Models\Level::where("slug","level-8")->first();
 *       file_put_contents("/tmp/level8.json", json_encode(app(App\Services\LevelPayload::class)->forEngine($l)));'
 *
 * This exists because a portal crossing could not be driven any other way. A
 * synthetic click does not grant pointer lock, so nothing automated can press
 * W in a real browser, and every question about what happens *while* crossing —
 * which frame it fires on, where the player lands, which way they end up facing
 * — was argued rather than measured. It runs the real `walkPlayer` against the
 * real colliders at a fixed timestep, so what it prints is what the game does.
 *
 * It settled one: the landing sits inside `PANE_CLEARANCE` of the mouth just
 * stepped out of, exactly as feared, but facing away from it — so there is no
 * flash on arrival. The flash is on the approach.
 *
 * `hug()` used to be what made that true, by testing which way the player was
 * looking and refusing to move the pane when they were looking away. It no
 * longer tests anything of the kind: a hugged pane stays in its own plane now,
 * so a mouth behind you is behind you. The measurement above still holds and
 * still matters — it is why nobody has to think about the arrival case — but it
 * is the geometry that keeps it, not a special case in the code.
 */

import { readFileSync } from 'node:fs';
import process from 'node:process';

const blank = () => ({
    width: 0,
    height: 0,
    style: {},
    addEventListener() {},
    removeEventListener() {},
    getContext: () => null,
});

globalThis.document = { createElementNS: blank, createElement: blank };

const { buildLevel } = await import('@/lib/engine/build-level.ts');
const { createTextureLibrary } = await import('@/lib/engine/textures.ts');
const { createPortals } = await import('@/lib/engine/portals.ts');
const { spawnPlayer, walkPlayer } = await import('@/lib/engine/player.ts');
const { floorAt, sectorAt } = await import('@/lib/engine/sectors.ts');

const [, , path, x, z, yaw, frames = '120', pace = 'walk'] = process.argv;

if (path === undefined) {
    console.error(
        'usage: walk.mjs <level.json> <x> <z> <yaw> [frames] [walk|run]',
    );
    process.exit(2);
}

const level = JSON.parse(readFileSync(path, 'utf8'));
const built = buildLevel(level, createTextureLibrary());
const world = {
    sectors: level.sectors,
    colliders: built.colliders,
    portals: createPortals(level.sectors),
};

const player = spawnPlayer(level, {
    x: Number(x),
    z: Number(z),
    yaw: Number(yaw),
    pitch: 0,
});

const round = (value) => Number(value.toFixed(4));
const trail = [];

for (let frame = 0; frame < Number(frames); frame++) {
    const wasX = player.x;
    const wasZ = player.z;

    walkPlayer(
        player,
        { forward: 1, strafe: 0, running: pace === 'run' },
        world,
        1 / 60,
    );

    const room = sectorAt(level.sectors, player.x, player.z);

    // A step longer than any one frame can cover is a portal, not a walk.
    const carried = Math.hypot(player.x - wasX, player.z - wasZ) > 0.5;

    trail.push({
        frame,
        x: round(player.x),
        z: round(player.z),
        yaw: round((player.yaw * 180) / Math.PI),
        room: room?.slug ?? null,
        floor: room === null ? null : round(floorAt(room, player.x, player.z)),
        ...(carried ? { carried: true } : {}),
    });
}

console.log(JSON.stringify(trail, null, 1));
