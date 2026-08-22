<?php

use App\Models\Level;
use App\Models\LevelSector;
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
        $named = $level->sectors->flatMap(fn (LevelSector $sector): array => [
            $sector->floor_texture,
            $sector->ceiling_texture,
            $sector->wall_texture,
        ])->merge(
            $level->sectors->flatMap(
                fn (LevelSector $sector): iterable => $sector->edges->pluck('wall_texture')
            )
        )->merge($level->load('things')->things->pluck('texture'))
            ->filter()
            ->unique();

        $named->each(function (string $texture) use ($level): void {
            expect(public_path("sprites/textures/{$texture}.png"))
                ->toBeFile("{$level->slug}: no texture called {$texture}.");
        });
    }
});

it('gives every room in every level a height, level 8 included', function (): void {
    // Level 8 is not in the `beforeEach` above, so none of the invariants in
    // this file have ever seen it — it arrived long after they were written and
    // nobody noticed that the docblock's promise had quietly stopped being
    // true. Two rooms in it had their ceiling *equal* to their floor: not rooms
    // at all, two coincident flats, and the z-fight somebody spent an evening
    // chasing round the portals.
    //
    // Seeded here rather than above, on purpose. Level 8 fails three of the
    // other invariants for reasons that are not faults — it is the only level
    // with a portal, and those checks predate portals, so they read a portal
    // mouth as a wall two rooms disagree about, a 4.8m stairwell as an
    // impossible step, and the eighteen rooms reached through it as
    // unreachable. Teaching those three about portals is worth doing and is
    // not this. Until then, this is the one that can honestly run everywhere.
    $this->seed(LevelEightSeeder::class);

    foreach (playableLevels() as $level) {
        $level->sectors->each(function (LevelSector $sector) use ($level): void {
            expect($sector->ceiling_height - $sector->floor_height)->toBeGreaterThan(
                0.0,
                "{$level->slug}: {$sector->slug} has its ceiling at its floor."
            );
        });
    }
});
