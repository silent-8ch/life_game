<?php

namespace App\Enums;

/**
 * Which edge of a flat thing it turns about.
 *
 * Paul, redesigning doors out of existence: *a door is just a solid sprite that
 * has a hinge with an action.* The hinge belongs to the **thing**, not to the
 * action, and that is what makes the action generic — a `rotate` effect never
 * has to know what it is rotating or which way that thing is supposed to open.
 *
 * A door hinges at a side, a drawbridge at the bottom, a hatch at the top, and
 * a window that swings out is the same object with different numbers. None of
 * those needed a kind of its own; they needed an edge and an angle.
 *
 * Named from the front, which is the face the thing's own `angle` points at and
 * the way the art is drawn. So Left is the left-hand edge as you stand looking
 * at it, and turning the thing round with `angle` moves the hinge to the other
 * jamb — the two controls together reach every arrangement.
 */
enum ThingHinge: string
{
    /** The left edge, seen from the front. */
    case Left = 'left';

    /** The right edge, seen from the front. */
    case Right = 'right';

    /** The top edge: a hatch, or a shutter that lifts. */
    case Top = 'top';

    /** The bottom edge: a drawbridge, or a ramp that falls. */
    case Bottom = 'bottom';
}
