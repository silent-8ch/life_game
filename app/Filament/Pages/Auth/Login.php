<?php

namespace App\Filament\Pages\Auth;

use App\Models\User;
use Filament\Auth\Pages\Login as BaseLogin;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Schema;

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
     * Where everybody's name lives, since none of them has an address.
     */
    public const DOMAIN = '@life.test';

    /**
     * A name, not an address.
     *
     * The children know they are wade and will; they do not know they are
     * wade@life.test, and asking them to type an address they never chose is
     * the same barrier as a password. The stored column is still an email
     * because that is what the auth provider looks up on — the form just stops
     * insisting the person typing knows that.
     */
    protected function getEmailFormComponent(): Component
    {
        return TextInput::make('email')
            ->label('Name')
            ->required()
            ->autocomplete()
            ->autofocus();
    }

    /**
     * A name, and nothing else on the form.
     *
     * The password field is not hidden or left blank — it is not built at all,
     * so there is nothing to tab into and nothing to explain. The credential
     * the auth provider compares against is supplied below rather than typed.
     */
    public function form(Schema $schema): Schema
    {
        return $schema->components([
            $this->getEmailFormComponent(),
            $this->getRememberFormComponent(),
        ]);
    }

    /**
     * The name is the credential, so only the name is checked.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function getCredentialsFromFormData(array $data): array
    {
        $name = trim(strtolower((string) $data['email']));

        return [
            // A bare name becomes the address it is stored under. Anything
            // with an @ in it is already one, so it is left alone — the two
            // accounts that predate this still sign in as they always did.
            'email' => str_contains($name, '@') ? $name : $name.self::DOMAIN,
            'password' => User::NO_PASSWORD,
        ];
    }
}
