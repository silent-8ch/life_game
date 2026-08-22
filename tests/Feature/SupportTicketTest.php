<?php

use App\Enums\TicketStatus;
use App\Models\Game;
use App\Models\SupportTicket;
use App\Models\User;
use Database\Seeders\LifeSeeder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

/**
 * "This is wrong", raised by somebody playing.
 *
 * Very nearly the payload a debug snapshot already carries, and deliberately a
 * second thing beside it: a snapshot is scaffolding that gets deleted when the
 * fault it was chasing is gone, and a ticket has to persist and be found again.
 *
 * **This is the first endpoint in the project that takes bytes from the
 * public.** Playing a published game needs no account, so anybody who can reach
 * the site can post here — which is why most of what follows is about what it
 * refuses rather than what it stores.
 */
beforeEach(function (): void {
    $this->seed(LifeSeeder::class);
    $this->game = Game::query()->where('slug', 'life')->sole();
});

/**
 * @param  array<string, mixed>  $changes
 * @return array<string, mixed>
 */
function aTicket(array $changes = []): array
{
    return array_merge([
        'level' => 'tech-demo',
        'note' => 'The floor here has a hole in it.',
        'at' => [
            'x' => 2.5,
            'z' => -4.25,
            'eye' => 1.62,
            'yaw' => 135.0,
            'pitch' => -8.5,
        ],
        'standingIn' => 'hall',
        'lookingAt' => 'crate',
        'holding' => null,
        'running' => false,
        'screen' => [
            'width' => 1512,
            'height' => 893,
            'pixelRatio' => 2,
            'touch' => false,
        ],
        'nearby' => [
            ['distance' => -0.041, 'rooms' => ['hall', 'kitchen'], 'open' => true],
        ],
        'legend' => [
            ['css' => 'rgb(255, 204, 102)', 'sector' => 'hall', 'index' => 3],
            ['css' => 'rgb(51, 255, 0)', 'sector' => 'kitchen', 'index' => 0],
        ],
        'shots' => [
            'normal' => UploadedFile::fake()->image('normal.png', 440, 250),
            'wireframe' => UploadedFile::fake()->image('wireframe.png', 440, 250),
            'walls' => UploadedFile::fake()->image('walls.png', 440, 250),
        ],
    ], $changes);
}

it('takes a ticket from somebody who is not signed in', function (): void {
    // The premise the whole design rests on. Only the editor and the admin
    // panel sit behind a login; playing does not, so most tickets will arrive
    // with nobody's name on them and that is not a fault.
    Storage::fake('local');

    $this->postJson(route('games.tickets.store', $this->game), aTicket())
        ->assertCreated();

    $ticket = SupportTicket::sole();

    expect($ticket->user_id)->toBeNull()
        ->and($ticket->note)->toBe('The floor here has a hole in it.')
        ->and($ticket->status)->toBe(TicketStatus::Open)
        ->and($ticket->level_slug)->toBe('tech-demo');
});

it('puts a name to it when somebody is signed in', function (): void {
    Storage::fake('local');

    $user = User::factory()->create();

    $this->actingAs($user)
        ->postJson(route('games.tickets.store', $this->game), aTicket())
        ->assertCreated();

    expect(SupportTicket::sole()->user_id)->toBe($user->id);
});

it('keeps the spot the same way round it was given', function (): void {
    // The same numbers `?at=` takes: the player's own yaw, not a level's spawn
    // angle, which is its negative. A sign flip here would stand somebody
    // facing the wall opposite whatever they were reporting.
    Storage::fake('local');

    $this->postJson(route('games.tickets.store', $this->game), aTicket());

    $ticket = SupportTicket::sole();

    expect($ticket->at_yaw)->toEqual(135.0)
        ->and($ticket->at_pitch)->toEqual(-8.5)
        ->and($ticket->standingAt())->toBe('2.5,-4.25,135,-8.5');
});

it('writes the pictures where nobody can fetch them directly', function (): void {
    // Bytes posted by whoever was playing, and nobody signed in to do it. They
    // go on the local disk, never public/, and the panel reads them through
    // Laravel behind its own login.
    Storage::fake('local');

    $this->postJson(route('games.tickets.store', $this->game), aTicket())
        ->assertCreated();

    $ticket = SupportTicket::sole();

    expect($ticket->shots)->toHaveCount(3);

    foreach ($ticket->shots as $shot) {
        Storage::disk('local')->assertExists($shot->path);

        expect($shot->path)->toStartWith("support-tickets/{$ticket->id}/")
            ->and($shot->bytes)->toBeGreaterThan(0);
    }
});

it('refuses a picture that is not one', function (): void {
    // `image` alone trusts the extension. A PHP file called .png is the oldest
    // trick there is, and this endpoint is reachable by anybody.
    Storage::fake('local');

    $this->postJson(route('games.tickets.store', $this->game), aTicket([
        'shots' => [
            'normal' => UploadedFile::fake()->create('normal.png', 16, 'text/x-php'),
        ],
    ]))->assertJsonValidationErrors(['shots.normal']);

    expect(SupportTicket::count())->toBe(0);
});

it('refuses a picture bigger than anybody s screen', function (): void {
    // Capped by dimensions as well as by bytes: a very large PNG of one colour
    // compresses to almost nothing and still exhausts memory the moment
    // anything decodes it.
    Storage::fake('local');

    $this->postJson(route('games.tickets.store', $this->game), aTicket([
        'shots' => [
            // Wide rather than square: the first version of this test asked
            // for 20000 x 20000 and exhausted the test runner's memory making
            // it, which is a fair demonstration of the risk and a poor test.
            'normal' => UploadedFile::fake()->image('normal.png', 9000, 8),
        ],
    ]))->assertJsonValidationErrors(['shots.normal']);
});

it('refuses a view it does not know about', function (): void {
    // Otherwise a client could invent kinds and write as many files as it liked
    // under one ticket.
    Storage::fake('local');

    $this->postJson(route('games.tickets.store', $this->game), aTicket([
        'shots' => [
            'normal' => UploadedFile::fake()->image('normal.png', 100, 100),
            'whatever' => UploadedFile::fake()->image('whatever.png', 100, 100),
        ],
    ]))->assertJsonValidationErrors(['shots.whatever']);
});

it('takes fewer pictures than three without complaint', function (): void {
    // Three is what the client sends today, not a rule. A browser that could
    // only manage the ordinary frame still has something worth reporting.
    Storage::fake('local');

    $this->postJson(route('games.tickets.store', $this->game), aTicket([
        'shots' => ['normal' => UploadedFile::fake()->image('normal.png', 100, 100)],
    ]))->assertCreated();

    expect(SupportTicket::sole()->shots)->toHaveCount(1);
});

it('refuses a ticket with nothing to look at', function (): void {
    Storage::fake('local');

    $this->postJson(route('games.tickets.store', $this->game), aTicket(['shots' => []]))
        ->assertJsonValidationErrors(['shots']);
});

it('bounds the list of boundaries, which the reporter decides the size of', function (): void {
    Storage::fake('local');

    $this->postJson(route('games.tickets.store', $this->game), aTicket([
        'nearby' => array_fill(0, 65, [
            'distance' => 0.1,
            'rooms' => ['a', 'b'],
            'open' => true,
        ]),
    ]))->assertJsonValidationErrors(['nearby']);
});

it('remembers a level that has since been deleted', function (): void {
    // A ticket outlives the level it is about. Losing the report because
    // somebody tidied up a room is worse than a dangling name.
    Storage::fake('local');

    $this->postJson(route('games.tickets.store', $this->game), aTicket());

    $ticket = SupportTicket::sole();

    expect($ticket->level_id)->not->toBeNull();

    $ticket->level->delete();

    $ticket->refresh();

    expect($ticket->level_id)->toBeNull()
        ->and($ticket->level_slug)->toBe('tech-demo');
});

it('takes a ticket about a level nobody has heard of', function (): void {
    // ?level= can name anything. Worth recording rather than refusing — a
    // report about a level that does not exist is itself a thing to look at.
    Storage::fake('local');

    $this->postJson(route('games.tickets.store', $this->game), aTicket([
        'level' => 'no-such-level',
    ]))->assertCreated();

    expect(SupportTicket::sole()->level_id)->toBeNull();
});

it('marks one done and puts it back again', function (): void {
    Storage::fake('local');

    $this->postJson(route('games.tickets.store', $this->game), aTicket());

    $ticket = SupportTicket::sole();

    $ticket->markResolved();
    expect($ticket->status)->toBe(TicketStatus::Resolved)
        ->and($ticket->resolved_at)->not->toBeNull();

    $ticket->reopen();
    expect($ticket->fresh()->status)->toBe(TicketStatus::Open)
        ->and($ticket->fresh()->resolved_at)->toBeNull();
});

it('takes the pictures with it when a ticket is deleted', function (): void {
    // The rows go by foreign key; the files do not, and nothing else would
    // ever tidy them.
    Storage::fake('local');

    $this->postJson(route('games.tickets.store', $this->game), aTicket());

    $ticket = SupportTicket::sole();
    $paths = $ticket->shots->pluck('path');

    $ticket->delete();

    foreach ($paths as $path) {
        Storage::disk('local')->assertMissing($path);
    }
});

it('will not serve a picture to somebody who is not signed in', function (): void {
    Storage::fake('local');

    $this->postJson(route('games.tickets.store', $this->game), aTicket());

    $ticket = SupportTicket::sole();

    $this->get(route('tickets.shot', ['ticket' => $ticket, 'kind' => 'normal']))
        ->assertRedirect();

    $this->actingAs(User::factory()->create())
        ->get(route('tickets.shot', ['ticket' => $ticket, 'kind' => 'normal']))
        ->assertOk();
});

it('keeps the legend with the pictures, since one is useless without the other', function (): void {
    // `paintWalls` hands out colours by walking the scene graph with a running
    // counter, so which colour is which wall belongs to *that build of that
    // level* and cannot be recovered from the pixels — `scanRow` takes the
    // legend as an argument for exactly that reason. A colour screen saved on
    // its own is a file that looks like evidence and decodes to nothing.
    Storage::fake('local');

    $this->postJson(route('games.tickets.store', $this->game), aTicket())
        ->assertCreated();

    $ticket = SupportTicket::sole();

    expect($ticket->legend)->toHaveCount(2)
        ->and($ticket->legend[0]['sector'])->toBe('hall');

    // And written beside them on disk too, so the folder is readable on its own
    // — which is what lets an agent decode a capture without a browser.
    Storage::disk('local')->assertExists($ticket->folder().'/legend.json');
});

it('takes a ticket with no legend, for a client that could not paint one', function (): void {
    Storage::fake('local');

    $this->postJson(route('games.tickets.store', $this->game), aTicket(['legend' => null]))
        ->assertCreated();

    expect(SupportTicket::sole()->legend)->toBeNull();
});
