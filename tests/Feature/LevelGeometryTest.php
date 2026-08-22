<?php

use App\Enums\ThingRender;
use App\Enums\ThingUvMode;
use App\Models\Level;
use App\Models\LevelSector;
use App\Models\LevelThing;
use Database\Seeders\ImportedLevelsSeeder;
use Database\Seeders\LevelEightSeeder;
use Database\Seeders\LifeSeeder;
use Database\Seeders\TheHouseSeeder;

/**
 * Rules every level has to keep, whoever authored it: rooms you can get into,
 * doorways both rooms agree about, and nothing the player can fall out of.
 *
 * These run over each seeded level in turn — but only over the levels seeded
 * below, which is a weaker promise than it looks and was not true of level 8
 * until the last test in this file. Adding a level to the game does not add it
 * here.
 */
beforeEach(function (): void {
    $this->seed(LifeSeeder::class);
    $this->seed(TheHouseSeeder::class);
});

/**
 * @return list<Level>
 */
function playableLevels(): array
{
    return Level::query()
        ->with('sectors.edges.vertex')
        ->get()
        ->filter(fn (Level $level): bool => $level->sectors->isNotEmpty())
        ->values()
        ->all();
}

it('has levels to check', function (): void {
    expect(playableLevels())->not->toBeEmpty();
});

it('starts the player inside a room', function (): void {
    foreach (playableLevels() as $level) {
        expect(sectorAtPoint($level, $level->spawn_x, $level->spawn_z))
            ->not->toBeNull("{$level->slug}: the player starts outside every room.");
    }
});

it('has both rooms agree about every wall they share', function (): void {
    foreach (playableLevels() as $level) {
        $owners = [];

        foreach ($level->sectors as $sector) {
            foreach (sectorEdges($sector) as $edge => $open) {
                $owners[$edge][] = [$sector->slug, $open];
            }
        }

        foreach ($owners as $edge => $sharers) {
            if (count($sharers) !== 2) {
                continue;
            }

            [[$first, $openFirst], [$second, $openSecond]] = $sharers;

            expect($openFirst)->toBe(
                $openSecond,
                "{$level->slug}: {$first} and {$second} disagree about the wall at {$edge}."
            );
        }
    }
});

it('leaves every doorway low enough and tall enough to use', function (): void {
    foreach (playableLevels() as $level) {
        $owners = [];

        foreach ($level->sectors as $sector) {
            foreach (sectorEdges($sector) as $edge => $open) {
                $owners[$edge][] = [$sector, $open];
            }
        }

        foreach ($owners as $sharers) {
            if (count($sharers) !== 2) {
                continue;
            }

            [[$first, $openFirst], [$second, $openSecond]] = $sharers;

            if (! $openFirst || ! $openSecond) {
                continue;
            }

            $climb = abs($first->floor_height - $second->floor_height);
            $headroom = min($first->ceiling_height, $second->ceiling_height)
                - max($first->floor_height, $second->floor_height);

            expect($climb)->toBeLessThanOrEqual(
                MAX_STEP,
                "{$level->slug}: the step from {$first->slug} to {$second->slug} is {$climb}m."
            )->and($headroom)->toBeGreaterThanOrEqual(
                MIN_HEADROOM,
                "{$level->slug}: only {$headroom}m of headroom between {$first->slug} and {$second->slug}."
            );
        }
    }
});

/**
 * Why level 8 and the levels people drew are not in the `beforeEach`, written
 * here because this is the invariant that fails hardest and the reason is not
 * the one it looks like.
 *
 * Seeding level 8 strands eighteen of its seventy-three sectors. Thirteen of
 * those are 0.25m thick — they are not rooms, they are wall thickness with a
 * sector around it, and they are sealed on the side facing the player *on
 * purpose*. Level 8 uses sectors for two different things, rooms you stand in
 * and slivers that make a wall solid, and every invariant in this file assumes
 * the first. That, not portals, is the mismatch: `walkableLinks` already
 * understands portals and always has, and level 8's one portal links room-13
 * to room-48 correctly.
 *
 * The same goes for `has both rooms agree about every wall they share`. Level 8
 * has twenty-one boundaries where one side blocks and the other does not, and
 * exactly one of them touches a portal. They are not faults: the engine takes a
 * boundary as solid if *either* side blocks it — `build/boundaries.ts:82` — so
 * a sliver sealing its own side is how a wall gets made when the neighbour is
 * open. Asserting both sides agree is a stricter convention than the engine's,
 * and level 8 does not follow it.
 *
 * The five stranded sectors that are *not* slivers — room-46, room-47-2,
 * room-52, room-60, room-75, between 2 and 7.25 square metres — are the ones
 * worth a look, and are unresolved.
 */
it('lets the player walk to every room from where they start', function (): void {
    foreach (playableLevels() as $level) {
        $start = sectorAtPoint($level, $level->spawn_x, $level->spawn_z);
        $links = walkableLinks($level);

        $seen = [];
        $queue = $start === null ? [] : [$start->slug];

        while ($queue !== []) {
            $at = array_shift($queue);

            if (isset($seen[$at])) {
                continue;
            }

            $seen[$at] = true;

            foreach ($links[$at] ?? [] as $next) {
                $queue[] = $next;
            }
        }

        $stranded = $level->sectors
            ->pluck('slug')
            ->reject(fn (string $slug): bool => isset($seen[$slug]))
            ->values()
            ->all();

        expect($stranded)->toBe(
            [],
            "{$level->slug}: cannot walk to ".implode(', ', $stranded).'.'
        );
    }
});

it('gives every room a floor below its ceiling', function (): void {
    foreach (playableLevels() as $level) {
        $level->sectors->each(function (LevelSector $sector) use ($level): void {
            expect($sector->headroom())->toBeGreaterThanOrEqual(
                MIN_HEADROOM,
                "{$level->slug}: {$sector->slug} is only {$sector->headroom()}m tall."
            );
        });
    }
});

it('keeps every thing standing inside a room', function (): void {
    foreach (playableLevels() as $level) {
        $level->load('things');

        foreach ($level->things as $thing) {
            expect(sectorAtPoint($level, $thing->x, $thing->z))
                ->not->toBeNull("{$level->slug}: {$thing->slug} stands outside every room.");
        }
    }
});

it('names only textures that are in the folder', function (): void {
    foreach (playableLevels() as $level) {
        $surfaces = $level->sectors->flatMap(fn (LevelSector $sector): array => [
            $sector->floor_texture,
            $sector->ceiling_texture,
            $sector->wall_texture,
        ])->merge(
            $level->sectors->flatMap(
                fn (LevelSector $sector): iterable => $sector->edges->pluck('wall_texture')
            )
        );

        // Which folder a thing's picture comes from is not a property of the
        // thing but of how it is drawn, and `build/things.ts` decides it with
        // exactly this test: a box that tiles is wearing a surface, and
        // anything else is wearing a prop, which carries a silhouette. Asking
        // one folder for both was right until the first door, which is the
        // first thing in any level to fit its picture rather than tile it.
        $things = $level->load('things')->things
            ->groupBy(fn (LevelThing $thing): string => $thing->render === ThingRender::Box
                && $thing->uv_mode === ThingUvMode::Tile ? 'textures' : 'props');

        $folders = [
            'textures' => $surfaces->merge($things->get('textures', collect())->pluck('texture')),
            'props' => $things->get('props', collect())->pluck('texture'),
        ];

        foreach ($folders as $folder => $named) {
            $named->filter()->unique()->each(
                function (string $texture) use ($level, $folder): void {
                    expect(public_path("sprites/{$folder}/{$texture}.png"))
                        ->toBeFile("{$level->slug}: no {$folder} called {$texture}.");
                }
            );
        }
    }
});

/**
 * Rooms whose ceiling sits exactly on their floor, that we have decided to
 * leave where they are.
 *
 * Both are in levels people drew rather than levels we authored, and Paul's
 * call was to leave what has been drawn alone and stop new ones arriving. They
 * are listed by name rather than skipped by a rule so that a third one fails
 * this test rather than joining them silently.
 *
 * `wade-wade-wade/room` is a different case from the other and should not be
 * ruled on as if it were the same: it is that level's *only* sector, so
 * removing the bad room removes the level. It is a sketch — its description is
 * "A level waiting to be drawn", and all four of its things stand outside its
 * one room.
 *
 * @var list<string>
 */
const DRAWN_ROOMS_WITH_NO_HEIGHT = [
    'new-level/room-11',
    'wade-wade-wade/room',
];

it('lets no new room have its ceiling at its floor', function (): void {
    // The invariant that would have caught level 8's room-11 and room-12 — two
    // coincident flats, and the z-fight somebody spent an evening chasing.
    // `gives every room a floor below its ceiling` above says the same thing
    // and has never run over either level, because neither is in the
    // `beforeEach` and adding a level to the game does not add it here.
    //
    // Seeded in the test rather than in the `beforeEach` because these two
    // levels fail other invariants above for reasons that are *not* faults —
    // see the note on the reachability test. This one they can both keep.
    $this->seed(LevelEightSeeder::class);
    $this->seed(ImportedLevelsSeeder::class);

    $flat = [];

    foreach (playableLevels() as $level) {
        foreach ($level->sectors as $sector) {
            if ($sector->ceiling_height - $sector->floor_height <= 0.0) {
                $flat[] = "{$level->slug}/{$sector->slug}";
            }
        }
    }

    expect(array_values(array_diff($flat, DRAWN_ROOMS_WITH_NO_HEIGHT)))->toBe(
        [],
        'These rooms have their ceiling at their floor: '.implode(', ', $flat).'.'
    );

    // And the exceptions are still real, so the list shrinks when somebody
    // fixes one rather than sitting there naming rooms that no longer exist.
    expect(array_values(array_diff(DRAWN_ROOMS_WITH_NO_HEIGHT, $flat)))->toBe(
        [],
        'These are excused but no longer flat, so drop them from the list.'
    );
});
