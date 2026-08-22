<?php

namespace App\Filament\Pages\Auth;

use App\Models\User;
use Filament\Auth\Pages\Login as BaseLogin;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Component;

/**
 * Signing in without a password.
 *
 * Paul's call, and the reason is the people using it: the players are children
 * on a home network, and a password a seven-year-old cannot type is a door they
 * cannot open. The name is the whole credential.
 *
 * **This is safe only because of where it runs.** Anybody who can reach the
 * server can become anybody. That is acceptable on a home LAN with three
 * accounts and no secrets behind them, and it would not be anywhere else — if
 * this application is ever exposed further, this class is the first thing that
 * has to go.
 */
class Login extends BaseLogin
{
    /**
     * A name to pick, and nothing to remember.
     */
    protected function getPasswordFormComponent(): Component
    {
        return TextInput::make('password')
            ->label('Password')
            ->password()
            ->helperText('Leave this empty — there are no passwords here.')
            ->required(false);
    }

    /**
     * The name is the credential, so only the name is checked.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function getCredentialsFromFormData(array $data): array
    {
        return ['email' => $data['email'], 'password' => User::NO_PASSWORD];
    }
}
