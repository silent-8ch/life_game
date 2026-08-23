<?php

use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\File;

/**
 * "This spot looks wrong" — written down while playing, so it can be stood on
 * again exactly rather than guessed at.
 *
 * Files rather than rows, under storage/app/debug, so the whole folder can go
 * when the fault it was chasing is gone.
 */
beforeEach(function (): void {
    // Never the real folder. These examples clear it before and after, and the
    // real one holds notes somebody took while playing that no test may throw
    // away — that happened, repeatedly, before this was pointed somewhere safe.
    $this->where = storage_path('framework/testing/debug-snapshots');

    config(['debug-snapshots.path' => $this->where]);

    File::deleteDirectory($this->where);

    // It writes files and asks nobody to log in, so it only exists on the
    // machine the game is being built on. Tests run as 'testing', so they say
    // so themselves — and saying so also turns the forgery check back on, which
    // is not what any of this is about.
    app()['env'] = 'local';
    $this->withoutMiddleware(PreventRequestForgery::class);
});

afterEach(function (): void {
    File::deleteDirectory($this->where);
});

/**
 * @return array<string, mixed>
 */
function aSnapshot(array $changes = []): array
{
    return [
        'takenAt' => '2026-08-21T13:45:00.000Z',
        'level' => ['slug' => 'new-level-2', 'name' => 'New Level 2'],
        'at' => ['x' => 66.0, 'z' => -18.0, 'eye' => 6.42, 'yaw' => 180.0, 'pitch' => -2.5],
        'standingIn' => ['slug' => 'room-28', 'floorHeight' => 4.8],
        'edgesNearby' => [
            ['distance' => 0.004, 'rooms' => ['room-28', 'room-30'], 'open' => true],
        ],
        'lookingAt' => null,
        'holding' => null,
        'running' => false,
        'screen' => ['width' => 1568, 'height' => 581, 'pixelRatio' => 2, 'touch' => false],
        'note' => '',
        ...$changes,
    ];
}

it('writes the spot down where it can be read', function (): void {
    $this->post(route('debug.snapshot'), aSnapshot())
        ->assertOk()
        ->assertJsonStructure(['saved']);

    $files = File::files($this->where);

    expect($files)->toHaveCount(1);

    $written = json_decode(File::get($files[0]->getPathname()), true, flags: JSON_THROW_ON_ERROR);

    expect($written['level']['slug'])->toBe('new-level-2')
        ->and($written['at']['x'])->toEqual(66)
        // The boundary the eye was standing on is the whole point of taking one.
        ->and($written['edgesNearby'][0]['rooms'])->toBe(['room-28', 'room-30'])
        ->and($written['edgesNearby'][0]['distance'])->toEqual(0.004);
});

it('names each one by when it was taken and which level', function (): void {
    $this->post(route('debug.snapshot'), aSnapshot())->assertOk();

    expect(File::files($this->where)[0]->getFilename())
        ->toEndWith('-new-level-2.json')
        ->toMatch('/^\d{4}-\d{2}-\d{2}-\d{6}-/');
});

it('keeps every one rather than writing over the last', function (): void {
    $this->post(route('debug.snapshot'), aSnapshot())->assertOk();
    $this->travel(1)->second();
    $this->post(route('debug.snapshot'), aSnapshot(['at' => [
        'x' => 3.0, 'z' => -18.0, 'eye' => 6.42, 'yaw' => 0.0, 'pitch' => 0.0,
    ]]))->assertOk();

    expect(File::files($this->where))->toHaveCount(2);
});

it('will not take one without somewhere to put it', function (): void {
    $this->post(route('debug.snapshot'), ['level' => ['slug' => 'x']])
        ->assertSessionHasErrors(['takenAt', 'at', 'running', 'screen']);

    expect(File::exists($this->where))->toBeFalse();
});

it('is not there anywhere but the machine the game is built on', function (): void {
    app()['env'] = 'production';

    $this->post(route('debug.snapshot'), aSnapshot())->assertNotFound();

    expect(File::exists($this->where))->toBeFalse();
});

it('puts the pictures down beside the note', function (): void {
    $answer = $this->post(route('debug.snapshot'), [
        ...aSnapshot(['edgesNearby' => []]),
        'shots' => [
            'normal' => UploadedFile::fake()->image('n.png'),
            'wireframe' => UploadedFile::fake()->image('w.png'),
            'walls' => UploadedFile::fake()->image('p.png'),
        ],
        'legend' => [['wall' => 'north', 'css' => '0,255,102']],
    ]);

    $answer->assertOk();

    $saved = $answer->json('saved');
    $stem = substr($saved, 0, -strlen('.json'));

    // Named for the note rather than filed under a folder of their own, so one
    // `ls` shows the notes in the order they were taken with their pictures
    // beside them.
    expect($answer->json('shots'))->toBe([
        "{$stem}-normal.png",
        "{$stem}-wireframe.png",
        "{$stem}-walls.png",
    ]);

    foreach ($answer->json('shots') as $shot) {
        expect(File::exists("{$this->where}/{$shot}"))->toBeTrue();
    }

    // The legend goes in the note itself. Without it the walls view decodes to
    // nothing — the colours belong to that build of that level and cannot be
    // worked out from the pixels — so the two are written together or not at
    // all, which is the rule SpotCapture already keeps for a ticket.
    $note = json_decode(File::get("{$this->where}/{$saved}"), true);

    expect($note['legend'])->toBe([['wall' => 'north', 'css' => '0,255,102']])
        ->and($note)->not->toHaveKey('shots');
});

it('takes a snapshot from the middle of a room, where no edge is near', function (): void {
    // A multipart form cannot carry an empty array: the flattener writes one
    // entry per item, so nought items writes nothing at all and the key arrives
    // missing. When `edgesNearby` was `present` that refused every snapshot
    // taken away from a wall, which is most of any room — and it would have
    // failed only once pictures were attached, which is the worst way to find
    // out.
    $spot = aSnapshot();
    unset($spot['edgesNearby']);

    $answer = $this->post(route('debug.snapshot'), $spot);

    $answer->assertOk();

    $note = json_decode(File::get("{$this->where}/{$answer->json('saved')}"), true);

    // Put back, so that whoever reads it is not reading a transport detail.
    expect($note['edgesNearby'])->toBe([]);
});
