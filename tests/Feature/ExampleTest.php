<?php

use Database\Seeders\TheStudySeeder;

test('returns a successful response', function () {
    $this->seed(TheStudySeeder::class);

    $response = $this->get(route('games.index'));

    $response->assertOk();
});
