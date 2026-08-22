<?php

use App\Models\Level;
use App\Models\User;
use App\Services\LevelAssets;
use App\Services\LevelPayload;
use Database\Seeders\LifeSeeder;
use Illuminate\Support\Facades\File;
use Inertia\Testing\AssertableInertia;

/**
 * The loop a room plays under everything else.
 *
 * It rides on a nullable column beside the textures and is found the same way
 * they are — a file in a folder under public — so this covers the same ground
 * the textures already have covered: the column, the payload both sides of the
 * wire, and a name that is not in the folder being turned away.
 *
 * No audio file is committed to the repository, so the one this writes is an
 * empty file with the right name. Nothing here plays it, and nothing could:
 * whether a browser makes a noise is not a thing a test suite can find out.
 */
beforeEach(function (): void {
    $this->seed(LifeSeeder::class);
    $this->level = Level::query()->where('slug', 'tech-demo')->sole();
    $this->editor = User::factory()->create();

    // A file with the right name, so the folder has something in it to pick.
    $this->ambienceFile = public_path('audio/ambience/test-room-tone.mp3');
    File::ensureDirectoryExists(dirname($this->ambienceFile));
    File::put($this->ambienceFile, '');
});

afterEach(function (): void {
    if (isset($this->ambienceFile)) {
        File::delete($this->ambienceFile);
    }
});

/**
 * The smallest map the editor could send: one square room, nobody in it.
 *
 * @return array<string, mixed>
 */
function mapWithAmbience(?string $ambience): array
{
    $corner = fn (float $x, float $z): array => [
        'x' => $x,
        'z' => $z,
        'wallTexture' => null,
        'blocks' => true,
        'isMirror' => false,
    ];

    return [
        'name' => 'Drawn',
        'description' => 'One room.',
        'playerSprite' => 'paul',
        'spawn' => ['x' => 1.0, 'z' => 1.0, 'angle' => 0],
        'ceilingHeight' => 3.0,
        'sky' => null,
        'things' => [],
        'sectors' => [
            [
                'slug' => 'yard',
                'name' => 'Yard',
                'floorHeight' => 0.0,
                'ceilingHeight' => 3.0,
                'floorTexture' => 'spring-grass',
                'ceilingTexture' => null,
                'wallTexture' => null,
                'ambience' => $ambience,
                'isSky' => true,
                'isWater' => false,
                'points' => [
                    $corner(0, 0),
                    $corner(4, 0),
                    $corner(4, 4),
                    $corner(0, 4),
                ],
            ],
        ],
    ];
}

it('finds the loops sitting in the folder, and nothing else', function (): void {
    $ambiences = app(LevelAssets::class)->ambiences();

    expect($ambiences)->toContain('test-room-tone');

    foreach ($ambiences as $name) {
        expect(public_path("audio/ambience/{$name}.mp3"))->toBeFile();
    }
});

it('stores a room’s loop beside its textures', function (): void {
    $this->actingAs($this->editor)
        ->put(route('levels.editor.update', $this->level), mapWithAmbience('test-room-tone'))
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    $sector = $this->level->fresh(['sectors'])->sectors->sole();

    expect($sector->ambience)->toBe('test-room-tone')
        ->and($sector->floor_texture)->toBe('spring-grass');
});

it('lets a room make no sound of its own', function (): void {
    $this->actingAs($this->editor)
        ->put(route('levels.editor.update', $this->level), mapWithAmbience(null))
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    expect($this->level->fresh(['sectors'])->sectors->sole()->ambience)->toBeNull();
});

it('turns away a loop that is not in the folder', function (): void {
    $this->actingAs($this->editor)
        ->put(route('levels.editor.update', $this->level), mapWithAmbience('rain-nobody-recorded'))
        ->assertSessionHasErrors('sectors.0.ambience');

    expect($this->level->fresh()->sectors)->toHaveCount(3, 'The stored map is untouched.');
});

it('sends the loop to the engine and to the editor alike', function (): void {
    $this->actingAs($this->editor)
        ->put(route('levels.editor.update', $this->level), mapWithAmbience('test-room-tone'));

    $level = $this->level->fresh();
    $payload = app(LevelPayload::class);

    // The player needs it to hear it; the author needs it to change it.
    expect($payload->forEngine($level)['sectors'][0]['ambience'])->toBe('test-room-tone')
        ->and($payload->forEditor($level)['sectors'][0]['ambience'])->toBe('test-room-tone');
});

it('offers the folder of loops to the map editor', function (): void {
    $this->actingAs($this->editor)
        ->get(route('levels.editor', $this->level))
        ->assertOk()
        ->assertInertia(function (AssertableInertia $page): void {
            /** @var list<string> $ambiences */
            $ambiences = $page->toArray()['props']['assets']['ambiences'];

            expect($ambiences)->toContain('test-room-tone');
        });
});
