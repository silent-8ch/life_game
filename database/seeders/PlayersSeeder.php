<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

/**
 * The people who play and draw here.
 *
 * There are no passwords: the panel accepts any, because the people signing in
 * are children on a home network and a password they cannot type is a door they
 * cannot open. A column is still filled rather than left null, so nothing that
 * expects a hash finds an empty string.
 */
class PlayersSeeder extends Seeder
{
    /** @var list<string> */
    private const PLAYERS = ['paul', 'wade', 'will'];

    public function run(): void
    {
        foreach (self::PLAYERS as $name) {
            User::query()->firstOrCreate(
                ['email' => "{$name}@life.test"],
                ['name' => ucfirst($name), 'password' => Hash::make(User::NO_PASSWORD)],
            );
        }
    }
}
