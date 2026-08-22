<?php

namespace App\Enums;

enum ActorBehaviour: string
{
    /** Stands where it was put. */
    case Still = 'still';

    /** Wanders the level, picking somewhere new whenever it arrives or gets stuck. */
    case Wander = 'wander';
}
