<?php

use App\Filament\Resources\Levels\Pages\CreateLevel;
use App\Filament\Resources\Levels\Pages\EditLevel;
use App\Models\Level;
use App\Models\User;
use App\Services\LevelAssets;
use Illuminate\Support\Str;
use Livewire\Livewire;

/**
 * One file is one sky.
 *
 * The panoramas used to be packed four to a 4096x512 strip and picked as a file
 * plus a cell number. That quietly assumed every sky file held exactly four of
 * them — so `sky-city.png`, a single image dropped into the folder, was sliced
 * into quarters and each quarter stretched around the whole dome. Paul: *looks
 * like you are inferring there are 4 cities, but it is one image?*
 *
 * So the strips are cut up, `sky_variant` is gone, and what makes a file a sky
 * is the file's own shape rather than a naming convention it cannot break.
 */
beforeEach(function (): void {
    $this->actingAs(User::factory()->create());
});

it('takes a sky to be a whole file, named after it', function (): void {
    $level = Level::factory()->create(['sky_image' => 'sky-night-4']);

    expect($level->sky_image)->toBe('sky-night-4')
        ->and(public_path('sprites/bg/sky-night-4.png'))->toBeFile();
});

it('only counts a file as a sky if it is shaped like one', function (): void {
    // Equirectangular means 360 across against 180 up and down, so 2:1. The
    // check is the file's own shape rather than a list of names, so a rejected
    // file re-exported at the right shape appears with nothing else to do.
    //
    // `sky-city.png` is the case that taught us this: 4096x512, one flat
    // photograph that does not even join up with itself. Under the old scheme
    // it was offered as City 1 to City 4 — four quarters of one picture, each
    // stretched around the whole sky.
    $assets = app(LevelAssets::class);

    expect($assets->skies())->not->toContain('sky-city');

    foreach ($assets->skies() as $image) {
        $size = getimagesize(public_path("sprites/bg/{$image}.png"));

        expect($size)->not->toBeFalse()
            ->and($size[0])->toBe($size[1] * 2, "{$image} is not 2:1");
    }
});

it('does not offer the retired strips or horizon layers', function (): void {
    // `File::files()` does not recurse, so moving art into `retired` is all it
    // takes to take it out of the editor without deleting it.
    $skies = app(LevelAssets::class)->skies();

    expect($skies)->not->toContain('sky-day', 'sky-night', 'sky-sunset')
        ->and($skies)->toContain('sky-day-1', 'sky-day-4')
        ->and(public_path('sprites/bg/retired/strips/sky-day.png'))->toBeFile()
        ->and(public_path('sprites/bg/retired/layers/hills_1.png'))->toBeFile();
});

it('opens the edit form on the sky the level already has', function (): void {
    $level = Level::factory()->create(['sky_image' => 'sky-sunset-3']);

    Livewire::test(EditLevel::class, ['record' => $level->getRouteKey()])
        ->assertSchemaStateSet(['sky_image' => 'sky-sunset-3']);
});

it('changes the sky from the edit form', function (): void {
    $level = Level::factory()->create(['sky_image' => 'sky-day-1']);

    Livewire::test(EditLevel::class, ['record' => $level->getRouteKey()])
        ->fillForm(['sky_image' => 'sky-night-2'])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($level->fresh()->sky_image)->toBe('sky-night-2');
});

it('lets a level have no sky at all', function (): void {
    $level = Level::factory()->create(['sky_image' => 'sky-day-1']);

    Livewire::test(EditLevel::class, ['record' => $level->getRouteKey()])
        ->fillForm(['sky_image' => null])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($level->fresh()->sky_image)->toBeNull();
});

it('refuses a sky that is not a file on disk', function (): void {
    $level = Level::factory()->create();

    Livewire::test(EditLevel::class, ['record' => $level->getRouteKey()])
        ->fillForm(['sky_image' => 'sky-eclipse'])
        ->call('save')
        ->assertHasFormErrors(['sky_image']);
});

it('refuses the city, which is offered nowhere', function (): void {
    $level = Level::factory()->create();

    Livewire::test(EditLevel::class, ['record' => $level->getRouteKey()])
        ->fillForm(['sky_image' => 'sky-city'])
        ->call('save')
        ->assertHasFormErrors(['sky_image']);
});

it('offers one line per file, and nothing that is not on disk', function (): void {
    $assets = app(LevelAssets::class);
    $choices = $assets->skyChoices();

    expect($choices)->toHaveCount(count($assets->skies()))
        ->and(array_column($choices, 'image'))->toBe($assets->skies());

    foreach ($choices as $choice) {
        expect(public_path("sprites/bg/{$choice['image']}.png"))->toBeFile();
    }
});

it('names a panorama the way a person would say it', function (): void {
    // Derived from the folder rather than pinned to a list, because dropping a
    // file in and having it turn up is the whole design of LevelAssets, and a
    // test that has to be edited to add a sky is a test that punishes that.
    // `sky-city` arrived while this was being written and needed no change.
    $assets = app(LevelAssets::class);

    $expected = [];

    foreach ($assets->skies() as $image) {
        $expected[] = Str::headline(Str::after($image, 'sky-'));
    }

    expect(array_column($assets->skyChoices(), 'label'))->toBe($expected)
        // Not just any list: the strips on disk today, named as a person would.
        ->and($expected)->toContain('Day 1', 'Night 4', 'Sunset 2');
});

it('shows the chosen panorama, and follows the picker to another one', function (): void {
    // The preview is the point of the whole change: nobody can tell Day 2 from
    // Day 3 by name.
    $level = Level::factory()->create(['sky_image' => 'sky-day-1']);

    Livewire::test(EditLevel::class, ['record' => $level->getRouteKey()])
        ->assertSee('sprites/bg/sky-day-1.png', escape: false)
        ->fillForm(['sky_image' => 'sky-night-4'])
        ->assertSee('sprites/bg/sky-night-4.png', escape: false)
        ->assertDontSee('sprites/bg/sky-day-1.png', escape: false);
});

it('shows no preview at all when the level has no sky', function (): void {
    Livewire::test(CreateLevel::class)
        ->fillForm(['sky_image' => null])
        ->assertDontSee('sprites/bg/sky-', escape: false);
});
