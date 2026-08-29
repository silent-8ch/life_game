<?php

use App\Filament\Resources\Levels\Pages\CreateLevel;
use App\Filament\Resources\Levels\Pages\EditLevel;
use App\Models\Level;
use App\Models\User;
use App\Services\LevelAssets;
use Livewire\Livewire;

/**
 * A sky is one choice, not two.
 *
 * The art is packed four panoramas to a strip, so `sky_image` and `sky_variant`
 * are two columns. Which strip a panorama landed in is a fact about the file
 * and not a decision anybody makes, and asking for it as a second question made
 * a twelve-item list read as three. `Level::$sky` is the one choice over the
 * two columns; the columns stay because the engine and every level already
 * drawn read them.
 */
beforeEach(function (): void {
    $this->actingAs(User::factory()->create());
});

it('reads the two columns as one choice', function (): void {
    $level = Level::factory()->create(['sky_image' => 'sky-night', 'sky_variant' => 3]);

    expect($level->sky)->toBe('sky-night:3');
});

it('writes one choice back into the two columns', function (): void {
    $level = Level::factory()->create();

    $level->update(['sky' => 'sky-sunset:1']);

    expect($level->fresh()->sky_image)->toBe('sky-sunset')
        ->and($level->fresh()->sky_variant)->toBe(1);
});

it('reads and writes no sky as nothing at all', function (): void {
    $level = Level::factory()->create(['sky_image' => 'sky-day', 'sky_variant' => 2]);

    expect($level->sky)->toBe('sky-day:2');

    $level->update(['sky' => null]);

    expect($level->fresh()->sky)->toBeNull()
        ->and($level->fresh()->sky_image)->toBeNull()
        // Back to the first cell rather than left pointing at the third of a
        // strip that is no longer named.
        ->and($level->fresh()->sky_variant)->toBe(0);
});

it('opens the edit form on the sky the level already has', function (): void {
    $level = Level::factory()->create(['sky_image' => 'sky-sunset', 'sky_variant' => 2]);

    Livewire::test(EditLevel::class, ['record' => $level->getRouteKey()])
        ->assertSchemaStateSet(['sky' => 'sky-sunset:2']);
});

it('changes the sky from the edit form', function (): void {
    $level = Level::factory()->create(['sky_image' => 'sky-day', 'sky_variant' => 0]);

    Livewire::test(EditLevel::class, ['record' => $level->getRouteKey()])
        ->fillForm(['sky' => 'sky-night:1'])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($level->fresh()->sky_image)->toBe('sky-night')
        ->and($level->fresh()->sky_variant)->toBe(1);
});

it('refuses a sky that is not one of the twelve', function (): void {
    $level = Level::factory()->create();

    Livewire::test(EditLevel::class, ['record' => $level->getRouteKey()])
        ->fillForm(['sky' => 'sky-eclipse:9'])
        ->call('save')
        ->assertHasFormErrors(['sky']);
});

it('offers every cell of every strip, and nothing that is not on disk', function (): void {
    $assets = app(LevelAssets::class);
    $choices = $assets->skyChoices();

    expect($choices)->toHaveCount(count($assets->skies()) * LevelAssets::SKY_VARIANTS)
        ->and(array_column($choices, 'value'))
        ->toBe(array_unique(array_column($choices, 'value')));

    foreach ($choices as $choice) {
        expect($choice['value'])->toBe($choice['image'].':'.$choice['variant'])
            ->and($choice['variant'])->toBeLessThan(LevelAssets::SKY_VARIANTS)
            ->and(public_path("sprites/bg/{$choice['image']}.png"))->toBeFile();
    }
});

it('names a panorama the way a person would say it', function (): void {
    expect(array_column(app(LevelAssets::class)->skyChoices(), 'label'))
        ->toBe([
            'Day 1', 'Day 2', 'Day 3', 'Day 4',
            'Night 1', 'Night 2', 'Night 3', 'Night 4',
            'Sunset 1', 'Sunset 2', 'Sunset 3', 'Sunset 4',
        ]);
});

it('shows the chosen panorama, and follows the picker to another one', function (): void {
    // The preview is the point of the whole change: nobody can tell Day 2 from
    // Day 3 by name. The cell is cut out of the strip by sliding a background
    // four times too wide, so what is asserted is the file and the offset.
    $level = Level::factory()->create(['sky_image' => 'sky-day', 'sky_variant' => 0]);

    Livewire::test(EditLevel::class, ['record' => $level->getRouteKey()])
        ->assertSee('sprites/bg/sky-day.png', escape: false)
        ->assertSee('background-position:0% 50%', escape: false)
        ->fillForm(['sky' => 'sky-night:3'])
        ->assertSee('sprites/bg/sky-night.png', escape: false)
        // The last of four cells sits at 100%, because a percentage lines the
        // image's right edge up with the box's right edge.
        ->assertSee('background-position:100% 50%', escape: false)
        ->assertDontSee('sprites/bg/sky-day.png', escape: false);
});

it('shows no preview at all when the level has no sky', function (): void {
    Livewire::test(CreateLevel::class)
        ->fillForm(['sky' => null])
        ->assertDontSee('sprites/bg/sky-', escape: false);
});
