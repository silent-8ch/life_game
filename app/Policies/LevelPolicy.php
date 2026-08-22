<?php

namespace App\Policies;

use App\Models\Level;
use App\Models\User;

/**
 * Who is allowed to redraw a level's map.
 *
 * Only `update` is defined here on purpose. Filament checks a model's policy
 * for every action it offers, and treats a missing method as "allowed", so
 * spelling out the rest of the CRUD abilities as `false` would quietly shut
 * the admin panel's own Levels screens.
 */
class LevelPolicy
{
    /**
     * Whether the user may open the map editor on this level and save over it.
     *
     * Nothing in the schema records who a game belongs to: `games` has no
     * owner column, and a user is a name, an email and a password. So the only
     * honest rule today is the one the `auth` middleware already implies —
     * anyone who can sign in to the admin panel may edit any level. This is a
     * placeholder for a real ownership check, and it is a policy rather than a
     * `return true` in the form request so that when a game gains an owner
     * there is one method to change and both the editor page and the save are
     * already asking it.
     */
    public function update(User $user, Level $level): bool
    {
        return true;
    }
}
