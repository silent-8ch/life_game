<?php

namespace App\Enums;

/**
 * Where a support ticket has got to.
 *
 * Two states rather than a workflow. A ticket is a player saying "this is
 * wrong" from where they were standing; either somebody has dealt with it or
 * they have not, and inventing triage states before anybody has read one would
 * be furniture nobody asked for.
 */
enum TicketStatus: string
{
    case Open = 'open';

    case Resolved = 'resolved';
}
