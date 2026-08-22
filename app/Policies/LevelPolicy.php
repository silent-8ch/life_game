<?php

namespace App\Policies;

use App\Models\Level;
use App\Models\User;

/**
 * Who may change a level.
 *
 * One rule, and it is deliberately generous: you may edit your own levels and
 * any orphan. An orphan is a level drawn before there were accounts, so it
 * belongs to nobody rather than to everybody-but-you — locking those would
 * strand the work that already exists rather than look after it.
 *
 * Seeing is not editing. Everything stays visible to everyone; the filters are
 * there to keep a list short, not to hide anybody's work.
 */
class LevelPolicy
{
    public function update(User $user, Level $level): bool
    {
        return $level->owner_id === null || $level->owner_id === $user->id;
    }

    public function delete(User $user, Level $level): bool
    {
        return $this->update($user, $level);
    }
}
