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
    /**
     * Slide it, as `x,z,up` in metres **from where it was drawn**.
     *
     * Relative rather than absolute so that moving the thing on the plan does
     * not silently break the binding: a portcullis is `0,0,3` however far
     * across the map its doorway ends up. Off is normally `0,0,0`.
     */
    case Move = 'move';
    /**
     * Show the thing's alternate picture, `1` for on and `0` for off.
     *
     * Deliberately not a texture name. A thing already carries `texture_alt`
     * for exactly this — it is what a light switch uses — and one alternate is
     * the whole of what an on/off system can mean. A binding that named its own
     * texture would be a second way to say the same thing.
     */
    case Texture = 'texture';
    /** Whether the thing is drawn at all, `1` or `0`. Collision is separate. */
    case Visible = 'visible';
}
