<?php

use App\Enums\TicketSource;
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
        'source' => 'play',
        'level' => 'tech-demo',
        'note' => 'The floor here has a hole in it.',
        'at' => [
            'x' => 2.5,
            'z' => -4.25,
            'eye' => 1.62,
            'yaw' => 135.0,
            'pitch' => -8.5,
        ],
        // The room as `describeSpot()` describes it, which is what the engine
        // already assembles for a snapshot. The textures are the point: a
        // ticket that records the room's name and not what it is made of
        // cannot diagnose a wrong or missing surface, which is the class of
        // fault that cost three sessions in one evening.
        'standingIn' => [
            'slug' => 'hall',
            'name' => 'Hall',
            'floorHeight' => 0.0,
            'ceilingHeight' => 3.0,
            'isSky' => false,
            'isWater' => false,
            'wallTexture' => 'cream-plaster-wall',
            'floorTexture' => 'oak-floor',
            'ceilingTexture' => null,
        ],
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
            // for 20000 x 20000 and exhausted the test runner's own memory
            // making it, which is a fair demonstration of the risk and a poor
            // test. 2400 is past the cap and costs nothing to build.
            'normal' => UploadedFile::fake()->image('normal.png', 2400, 8),
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

it('takes a ticket from the editor, which has nowhere to have been standing', function (): void {
    // The editor draws a floor plan and a section, not a scene, so it has no
    // position, no eye height and none of the three pictures play sends. A
    // schema that assumed those would have been wrong the first time somebody
    // filed one.
    Storage::fake('local');

    $this->postJson(route('games.tickets.store', $this->game), [
        'source' => 'editor',
        'level' => 'tech-demo',
        'note' => 'This room will not carve.',
        'shots' => [
            'map' => UploadedFile::fake()->image('map.png', 600, 400),
            'section' => UploadedFile::fake()->image('section.png', 600, 200),
        ],
    ])->assertCreated();

    $ticket = SupportTicket::sole();

    expect($ticket->source)->toBe(TicketSource::Editor)
        ->and($ticket->hasSpot())->toBeFalse()
        ->and($ticket->standingAt())->toBeNull()
        ->and($ticket->level_slug)->toBe('tech-demo')
        ->and($ticket->shots->pluck('kind')->sort()->values()->all())
        ->toBe(['map', 'section']);
});

it('refuses half a position, which looks like somewhere and is nowhere', function (): void {
    // All of it or none. A ticket carrying an x and no z would put a marker on
    // a map at a place nobody stood.
    Storage::fake('local');

    $this->postJson(route('games.tickets.store', $this->game), aTicket([
        'at' => ['x' => 2.5],
    ]))->assertJsonValidationErrors(['at.z', 'at.eye', 'at.yaw', 'at.pitch']);
});

it('refuses a source it does not know', function (): void {
    Storage::fake('local');

    $this->postJson(route('games.tickets.store', $this->game), aTicket(['source' => 'telepathy']))
        ->assertJsonValidationErrors(['source']);
});

it('takes a ticket about no level at all', function (): void {
    // Somebody reporting the game rather than a room in it.
    Storage::fake('local');

    $this->postJson(route('games.tickets.store', $this->game), [
        'source' => 'play',
        'note' => 'The music stops when I open the menu.',
        'shots' => ['normal' => UploadedFile::fake()->image('normal.png', 200, 200)],
    ])->assertCreated();

    expect(SupportTicket::sole()->level_slug)->toBeNull();
});

it('can say how much disk the pictures are holding', function (): void {
    // Nothing prunes tickets — that is the decision, with the alternatives in
    // front of whoever made it — so the plan is to watch the disk. A plan to
    // watch something needs a number somebody can look at, or "keep an eye on
    // it" is a thing nobody does until it is already a problem.
    Storage::fake('local');

    expect(SupportTicket::bytesHeld())->toBe(0);

    $this->postJson(route('games.tickets.store', $this->game), aTicket());

    expect(SupportTicket::bytesHeld())->toBeGreaterThan(0);

    SupportTicket::sole()->delete();

    expect(SupportTicket::bytesHeld())->toBe(0);
});

it('keeps what the room is made of, not only what it is called', function (): void {
    // The whole reason `standingIn` widened from a slug to the room. A green
    // grid was chased across three sessions and four hours; `floorTexture`
    // being null in the room the reporter stood in would have answered it in
    // one line, and the endpoint was throwing that away to fit a string column.
    Storage::fake('local');

    $this->postJson(route('games.tickets.store', $this->game), aTicket());

    $room = SupportTicket::sole()->standing_in;

    expect($room['floorTexture'])->toBe('oak-floor')
        ->and($room['wallTexture'])->toBe('cream-plaster-wall')
        // Null is a real answer here rather than a missing one — it is exactly
        // the reading that would have ended that hunt.
        ->and($room['ceilingTexture'])->toBeNull()
        // Under the spot, not the room's base heights: on a sloped room those
        // agree only along the hinge wall.
        ->and($room['floorHeight'])->toEqual(0.0)
        ->and($room['ceilingHeight'])->toEqual(3.0)
        ->and($room['isSky'])->toBeFalse();
});

it('still keeps the room slug where it can be sorted and filtered', function (): void {
    // Widening must not cost the one thing the old column could do. The admin
    // table shows tickets by room, and a JSON column cannot be indexed for
    // that here.
    Storage::fake('local');

    $this->postJson(route('games.tickets.store', $this->game), aTicket());

    expect(SupportTicket::sole()->standing_in_slug)->toBe('hall')
        ->and(SupportTicket::query()->where('standing_in_slug', 'hall')->count())
        ->toBe(1);
});

it('takes a ticket from somebody standing outside every room', function (): void {
    // Reachable: rooms do not tile the plane, and falling out of one is itself
    // worth reporting. It must not read as a malformed ticket.
    Storage::fake('local');

    $this->postJson(
        route('games.tickets.store', $this->game),
        aTicket(['standingIn' => null])
    )->assertCreated();

    $ticket = SupportTicket::sole();

    expect($ticket->standing_in)->toBeNull()
        ->and($ticket->standing_in_slug)->toBeNull();
});

it('turns down a room with no name to call it by', function (): void {
    // The slug is what the admin table hangs on and what a person searches
    // for. A room object without one is half a reading.
    Storage::fake('local');

    $this->postJson(
        route('games.tickets.store', $this->game),
        aTicket(['standingIn' => ['name' => 'Hall', 'floorTexture' => 'oak-floor']])
    )->assertJsonValidationErrors(['standingIn.slug']);
});

it('records what was being edited when the report came from the editor', function (): void {
    // Every other context column is a play concept — standing in, looking at,
    // holding, running — so without this an editor ticket is a note, a level
    // and two pictures: the "where but not what" problem moved out of playing
    // and into the editor rather than solved.
    Storage::fake('local');

    $this->postJson(route('games.tickets.store', $this->game), aTicket([
        'source' => 'editor',
        'standingIn' => null,
        'at' => null,
        'editorState' => [
            'tool' => 'select',
            'selection' => 'hall',
            'rooms' => 12,
            'history' => 4,
            'unsaved' => true,
            'grid' => 0.25,
        ],
        'shots' => [
            'ui' => UploadedFile::fake()->image('ui.png', 900, 600),
        ],
    ]))->assertCreated();

    $state = SupportTicket::sole()->editor_state;

    // `unsaved` is the one that earns its place: the same complaint means a
    // different thing against unsaved work than against what the server holds.
    expect($state['unsaved'])->toBeTrue()
        ->and($state['tool'])->toBe('select')
        ->and($state['selection'])->toBe('hall')
        ->and($state['history'])->toBe(4);
});

it('takes a picture of the interface, which is what a UI fault looks like', function (): void {
    // The editor button exists for UI issues, and `map` and `section` both
    // draw the *level*. Without a kind for the interface, a child reporting
    // "this panel is broken" had to send two pictures of the floor plan and
    // was forbidden from sending a picture of the panel.
    Storage::fake('local');

    $this->postJson(route('games.tickets.store', $this->game), aTicket([
        'source' => 'editor',
        'standingIn' => null,
        'at' => null,
        'shots' => [
            'ui' => UploadedFile::fake()->image('ui.png', 1200, 800),
        ],
    ]))->assertCreated();

    expect(SupportTicket::sole()->shots()->where('kind', 'ui')->exists())
        ->toBeTrue();
});
