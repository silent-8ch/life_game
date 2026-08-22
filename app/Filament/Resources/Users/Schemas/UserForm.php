<?php

namespace App\Filament\Resources\Users\Schemas;

use App\Filament\Pages\Auth\Login;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Illuminate\Support\Str;

/**
 * Adding somebody.
 *
 * A name is all anybody types here, because a name is all anybody types to
 * sign in. The address behind it is derived and shown but not asked for, and
 * the password is set to the shared non-secret so a person created here can
 * sign in immediately — a new account that cannot log in is the fault this
 * whole feature already caused once.
 */
class UserForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('name')
                    ->label('Name')
                    ->required()
                    ->maxLength(255)
                    ->live(onBlur: true)
                    ->helperText('What they type to sign in. No password is needed.')
                    ->afterStateUpdated(function (Set $set, ?string $state): void {
                        if (filled($state)) {
                            $set('email', Str::slug($state).Login::DOMAIN);
                        }
                    }),

                TextInput::make('email')
                    ->label('Signs in as')
                    ->required()
                    ->email()
                    ->unique(ignoreRecord: true)
                    ->maxLength(255)
                    ->helperText('Filled in from the name. Change it only if you mean to.'),
            ]);
    }
}
