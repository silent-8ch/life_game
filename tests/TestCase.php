<?php

namespace Tests;

use Illuminate\Foundation\Http\Middleware\PreventRequestsDuringMaintenance;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    /**
     * The suite does not care whether this machine's app is up.
     *
     * `php artisan down` writes a file under `storage/framework`, and the
     * middleware that reads it does not know or care that it is being read by a
     * test. So a checkout left in maintenance mode answers 503 to every route a
     * feature test asks for, and **165 of them fail at once** with a message
     * that says nothing about what they were testing. Measured, twice, on this
     * machine, because taking the site down while somebody else demonstrates is
     * an ordinary thing to do.
     *
     * It is the same shape as the Vite manifest that made the ownership tests
     * green or red depending on whether anybody had run a build lately: a check
     * whose answer depends on unrelated local state is a check nobody should
     * believe. Turning the middleware off here is not skipping a rule — no test
     * in this suite is about maintenance mode, and one that ever is can put it
     * back for itself.
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutMiddleware(PreventRequestsDuringMaintenance::class);
    }
}
