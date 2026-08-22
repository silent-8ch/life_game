<?php

namespace App\Filament\Resources\Users\Pages;

use App\Filament\Resources\Users\UserResource;
use App\Models\User;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Support\Facades\Hash;

class CreateUser extends CreateRecord
{
    protected static string $resource = UserResource::class;

    /**
     * Nobody is asked for a password, so one is set for them.
     *
     * The login page compares against this same constant whatever is typed, so
     * an account saved without it is an account that cannot sign in — which is
     * exactly the fault that locked everybody out of this panel once already.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $data['password'] = Hash::make(User::NO_PASSWORD);

        return $data;
    }
}
