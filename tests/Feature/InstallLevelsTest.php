<?php

use App\Exceptions\LevelAlreadyDrawn;
use App\Models\Level;
use App\Models\LevelThing;
use Database\Seeders\LifeSeeder;
use Database\Seeders\TheHouseSeeder;

/**
 * Putting authored levels into a database that must never be reseeded.
 *
 * The demo holds four levels that exist nowhere else — drawn by children, in no
 * repo and on no other machine — so it migrates on every deploy and seeds on
 * none of them. Which meant a level written in a seeder could never reach the
 * people playing. The doors were the visible case: they worked, and the House
 * on the demo had thirty-three things and no door to open, because the House on
 * the demo is not the House in `TheHouseSeeder`.
 *
 * Every test here is really the same test asked from a different side: **it
 * adds what is missing and does not touch what is there.** That is the only
 * property that makes it safe to point at that database, and it is the one
 * nobody can check by reading the output afterwards.
 */
beforeEach(function (): void {
    $this->seed(LifeSeeder::class);
});

it('adds a level that is not there yet', function (): void {
    expect(Level::query()->where('slug', 'house')->exists())->toBeFalse();

    $this->artisan('levels:install')->assertSuccessful();

    $house = Level::query()->where('slug', 'house')->first();

    expect($house)->not->toBeNull()
        ->and($house->sectors()->count())->toBeGreaterThan(10)
        ->and($house->things()->count())->toBeGreaterThan(10);
});

it('leaves a level that is already there completely alone', function (): void {
    $this->seed(TheHouseSeeder::class);

    $house = Level::query()->where('slug', 'house')->sole();

    // Somebody has been playing on this copy and has moved things about. This
    // is the demo in miniature: a level with the same slug as an authored one
    // and a history the seeder knows nothing about.
    $house->update(['name' => 'The House Will Redrew']);
    $house->things()->first()?->update(['x' => 99.0, 'name' => 'Moved']);

    $rooms = $house->sectors()->count();
    $things = $house->things()->count();
    $moved = LevelThing::query()->where('name', 'Moved')->sole();

    $this->artisan('levels:install')->assertSuccessful();

    $after = $house->fresh();

    // Not renamed back, not redrawn, and — the one that would hurt most — not
    // given a second copy of every room straight through the first.
    expect($after->name)->toBe('The House Will Redrew')
        ->and($after->sectors()->count())->toBe($rooms)
        ->and($after->things()->count())->toBe($things)
        ->and($moved->fresh()->x)->toEqual(99.0);
});

it('adds the missing ones and skips the rest in the same run', function (): void {
    $this->seed(TheHouseSeeder::class);

    $before = Level::query()->pluck('slug')->sort()->values()->all();

    $this->artisan('levels:install')
        ->expectsOutputToContain('already there')
        ->expectsOutputToContain('hall-of-mirrors')
        ->assertSuccessful();

    $after = Level::query()->pluck('slug')->sort()->values()->all();

    // The house was there and stayed one house; the others arrived.
    expect(array_diff($before, $after))->toBe([])
        ->and(count($after))->toBeGreaterThan(count($before));
});

it('writes nothing at all when it is only pretending', function (): void {
    $before = Level::query()->pluck('slug')->sort()->values()->all();

    $this->artisan('levels:install', ['--pretend' => true])
        ->expectsOutputToContain('nothing was written')
        ->assertSuccessful();

    expect(Level::query()->pluck('slug')->sort()->values()->all())->toBe($before);
});

it('says what a pretend run would add, having really added it and put it back', function (): void {
    $this->artisan('levels:install', ['--pretend' => true])
        ->expectsOutputToContain('house')
        ->assertSuccessful();

    // The point of the pretend being a real run: it can only name `house` if a
    // house was really drawn. A command that guessed would have to know what
    // the seeder was going to do, and knowing that is the same as doing it.
    expect(Level::query()->where('slug', 'house')->exists())->toBeFalse();
});

it('refuses to draw a level over one that is already there', function (): void {
    $this->seed(TheHouseSeeder::class);

    // Straight at the seeder, with none of the command's care around it. The
    // guard is in `AuthorsGames::level` rather than in the command, so a seeder
    // written next year gets it without anybody remembering to ask for it.
    expect(fn () => $this->seed(TheHouseSeeder::class))
        ->toThrow(LevelAlreadyDrawn::class);
});
