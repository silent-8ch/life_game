<?php

namespace App\Enums;

/**
 * When a thing puts its line on.
 *
 * Paul: *all lines are either on or off and things interact with it being on or
 * off.* This is the half that decides when a line goes on, and the two here are
 * the two redstone starts with.
 */
enum EmitWhen: string
{
    /**
     * Toggled by a `Use`, and stays. A lever.
     *
     * **Latching**, which is what makes it persist: a lever you threw is still
     * thrown next session, so its line is written to the save as a flag.
     */
    case Used = 'used';

    /**
     * On while somebody is standing inside its footprint. A pressure plate.
     *
     * **Momentary**, and deliberately not persisted. You are not standing on
     * the plate next session, and restoring it would open a door in an empty
     * room.
     */
    case StoodOn = 'stood_on';
}
