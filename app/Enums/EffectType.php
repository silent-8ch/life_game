<?php

namespace App\Enums;

enum EffectType: string
{
    /** Add the item named by `subject` to the inventory. */
    case GiveItem = 'give_item';

    /** Remove the item named by `subject` from the inventory. */
    case RemoveItem = 'remove_item';

    /** Set the flag named by `subject` to `value`. */
    case SetFlag = 'set_flag';

    /** Move the player to the scene named by `subject`. */
    case MoveToScene = 'move_to_scene';

    /** Make the hotspot named by `subject` visible. */
    case RevealHotspot = 'reveal_hotspot';

    /** Make the hotspot named by `subject` hidden. */
    case HideHotspot = 'hide_hotspot';

    /**
     * Turn the thing named by `subject` to `value` degrees about its hinge.
     *
     * Generic on purpose, and it is the half of *a door is just a solid sprite
     * that has a hinge with an action* that does the work. It never has to know
     * what it is turning: the hinge is a property of the thing, so the same
     * effect opens a door, drops a drawbridge, lifts a hatch and swings a
     * window out, and the difference between those is which edge somebody
     * chose and what number they typed.
     *
     * An absolute angle rather than an amount to add, so that firing it twice
     * leaves the thing where firing it once did. A door you Use twice should be
     * open, not open twice.
     */
    case RotateThing = 'rotate_thing';

    /**
     * Whether the thing named by `subject` stops anybody walking through it.
     *
     * `value` is on or off. The other half of a door: a thing that has swung
     * out of a doorway is not in the doorway any more, and something has to say
     * so, because a collider is a rectangle on the floor plan that knows
     * nothing about which way its thing is facing.
     */
    case SetBlocking = 'set_blocking';
}
