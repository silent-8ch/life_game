<?php

namespace App\Enums;

/**
 * What a thing does while an action line is on.
 *
 * The other half of Paul's sentence. These two are the responses the engine
 * already had as effects — `RotateThing` and `SetBlocking` — reached by a
 * line rather than by a verb, which is the whole of what a binding is.
 *
 * Moving, teleporting and becoming visible are the same shape and are the next
 * rows in this enum rather than the next feature.
 */
enum BindingResponse: string
{
    /** Turn to `value` degrees about the thing's hinge. */
    case Rotate = 'rotate';

    /** Whether the thing stops anybody walking through it. */
    case Blocking = 'blocking';
}
