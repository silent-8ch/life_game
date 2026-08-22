<?php

namespace App\Enums;

enum ConditionType: string
{
    /** The player carries the item named by `subject`. */
    case HasItem = 'has_item';

    /** The player does not carry the item named by `subject`. */
    case MissingItem = 'missing_item';

    /** The flag named by `subject` equals `value`. */
    case FlagIs = 'flag_is';

    /** The flag named by `subject` does not equal `value`. */
    case FlagIsNot = 'flag_is_not';
}
