<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * Thrown to roll a transaction back after the work inside it has been read.
 *
 * `levels:install --pretend` runs the real seeders and then refuses to keep
 * what they wrote, because the only honest answer to *what would this do to my
 * database* is to do it and look. Never leaves the command that throws it.
 */
class PretendingOnly extends RuntimeException
{
    //
}
