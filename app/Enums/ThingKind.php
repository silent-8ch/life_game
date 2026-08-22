<?php

namespace App\Enums;

enum ThingKind: string
{
    /** Furniture and clutter. Part of the room's shape, not its plot. */
    case Prop = 'prop';

    /** A way out of the level, whether or not it opens yet. */
    case Door = 'door';

    /** A hole in a wall you can see through but not walk through. */
    case Window = 'window';

    /** Something the player is meant to notice and act on. */
    case Fixture = 'fixture';

    /** A person, drawn from sprite sheets rather than built as a box. */
    case Actor = 'actor';
}
