<?php

namespace App\Enums;

/**
 * How a door gets out of the way.
 *
 * The kind is authored rather than guessed from the art, because the art does
 * not say: `door-bifold` and `door-interior` are both a rectangle with a
 * picture on it, and which way they move is the whole difference between them.
 */
enum DoorSwing: string
{
    /** Hinged at one edge and turned about it. The ordinary interior door. */
    case Swing = 'swing';

    /** Slid along its own width, the way a patio door goes. */
    case Slide = 'slide';

    /**
     * Folded in half against its hinge edge.
     *
     * Drawn as a slide of half the width until somebody wants the crease, which
     * is a rendering decision rather than a data one — the point of naming it
     * here is that a bifold is not a swing and should not be animated as one.
     */
    case Fold = 'fold';
}
