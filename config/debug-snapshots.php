<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Where "this spot looks wrong" notes are written
    |--------------------------------------------------------------------------
    |
    | Configurable for one reason: the tests around this feature used to wipe
    | the real folder before and after every example, which quietly destroyed
    | notes somebody had taken while playing. A test points this at a temporary
    | directory of its own instead, and clears that.
    |
    */

    'path' => env('DEBUG_SNAPSHOT_PATH'),

];
