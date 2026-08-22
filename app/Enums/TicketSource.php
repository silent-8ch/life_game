<?php

namespace App\Enums;

/**
 * Where in the app a ticket was raised.
 *
 * Not the same fact as "this ticket has no position". A ticket from the editor
 * has no position because the editor has no positions — it draws a floor plan
 * and a section, not a scene — whereas a ticket from play with nothing in its
 * spot columns would mean something had gone wrong. Recording which it is keeps
 * an empty column from reading as a fault.
 */
enum TicketSource: string
{
    /** Raised while walking around, so it has a spot and pictures of it. */
    case Play = 'play';

    /** Raised in the map editor, which has a level but nowhere to stand. */
    case Editor = 'editor';
}
