<?php

use App\Filament\Pages\Auth\Login;
use App\Models\User;
use Database\Seeders\PlayersSeeder;
use Livewire\Livewire;

/**
 * Signing in with a name and nothing else.
 *
 * Pinned rather than assumed, because this went wrong once: the passwordless
 * login went live before the accounts it expects existed, and the only account
 * there was could no longer sign in. A form nobody can get through locks out
 * everybody at once, so it is worth a test that actually submits it.
 */
beforeEach(function (): void {
    // These render Inertia pages, which resolve their assets through the Vite
    // manifest — and `public/build` is gitignored, so on a clean checkout there
    // is no manifest to resolve against. Without this the test is green or red
    // depending on whether anybody has run `npm run build` lately, which is a
    // check nobody should believe. Nothing here is asserting about assets.
    $this->withoutVite();

    $this->seed(PlayersSeeder::class);
});

it('signs in with just a name', function (): void {
    Livewire::test(Login::class)
        ->fillForm(['email' => 'paul'])
        ->call('authenticate')
        ->assertHasNoFormErrors();

    expect(auth()->user()?->email)->toBe('paul@life.test');
});

it('does not mind capitals or stray spaces', function (string $typed): void {
    Livewire::test(Login::class)
        ->fillForm(['email' => $typed])
        ->call('authenticate')
        ->assertHasNoFormErrors();

    expect(auth()->user()?->email)->toBe('wade@life.test');
})->with(['Wade', ' wade ', 'WADE']);

it('still takes a full address, for accounts that predate the names', function (): void {
    User::factory()->create([
        'email' => 'someone@elsewhere.test',
        'password' => Hash::make(User::NO_PASSWORD),
    ]);

    Livewire::test(Login::class)
        ->fillForm(['email' => 'someone@elsewhere.test'])
        ->call('authenticate')
        ->assertHasNoFormErrors();

    expect(auth()->user()?->email)->toBe('someone@elsewhere.test');
});

it('turns away a name nobody has', function (): void {
    Livewire::test(Login::class)
        ->fillForm(['email' => 'nobody'])
        ->call('authenticate')
        ->assertHasFormErrors();

    expect(auth()->check())->toBeFalse();
});

it('asks for no password at all', function (): void {
    Livewire::test(Login::class)->assertFormFieldDoesNotExist('password');
});
