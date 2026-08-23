<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * A seeder tried to author a level that is already there.
 *
 * Thrown rather than quietly returning the existing row, because a seeder does
 * not stop at the level: it goes on to add rooms, walls and things to whatever
 * it was handed. Handed a level somebody has been playing, it would draw a
 * second copy of every room straight through the first.
 *
 * `levels:install` is what this is for. It runs each seeder in a transaction
 * and treats this as *nothing to do* — which is what lets new levels reach a
 * database that must never be reseeded, without any judgement about which rows
 * are safe to touch. The judgement is made by the unique index on
 * `(game_id, slug)`, which is not capable of being talked round.
 */
class LevelAlreadyDrawn extends RuntimeException
{
    public function __construct(public readonly string $slug)
    {
        parent::__construct("A level called {$slug} is already drawn in this game.");
    }
}
