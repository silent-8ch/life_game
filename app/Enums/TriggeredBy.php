<?php

namespace App\Enums;

/**
 * Who can work an emitter.
 *
 * A property of the emitter rather than a rule for the whole game, because a
 * plate only the people can work is a thing somebody wants within a day of
 * having plates.
 *
 * Paul's ruling on the other half: **actors trigger plates and never levers.**
 * Flipping a switch is a deliberate act and feels wrong for a wanderer;
 * standing on something is physical. So this says who may stand on a plate, and
 * a lever is the player's whatever it says.
 */
enum TriggeredBy: string
{
    case Player = 'player';
    case Actors = 'actors';
    case Anyone = 'anyone';
}
