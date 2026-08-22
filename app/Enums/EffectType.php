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
}
