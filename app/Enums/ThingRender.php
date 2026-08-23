<?php

namespace App\Enums;

/**
 * How a thing is put on the screen.
 *
 * A thing has always been a box, which is right for furniture and wrong for
 * everything with a silhouette — a pot plant drawn on the side of a cube reads
 * as a cube with a picture of a plant on it, from every angle at once.
 */
enum ThingRender: string
{
    /** Six faces, as everything has been until now. Furniture, crates, doors. */
    case Box = 'box';

    /** One quad kept turned towards whoever is looking. Small round things. */
    case Billboard = 'billboard';

    /**
     * Two or three quads standing in a star, fixed to the thing's own angle.
     *
     * Reads as volume from any direction without turning to follow the eye,
     * which is what stops a row of plants all swivelling together as you walk
     * past them.
     */
    case Cross = 'cross';

    /**
     * One quad at the thing's own angle, which never turns.
     *
     * The gap the other three left. A box is a solid, a billboard turns to face
     * whoever is looking, and a cross is two of those crossed — none of them is
     * *one quad at a fixed angle*, which is what a window, a picture, a sign or
     * a door is. Paul asked for it twice: *need an option for shape of an
     * object, something that is flat but has a locked angle, like a door or a
     * window.*
     *
     * Drawn from both sides, mirrored, so it reads the same way round from
     * either — the back being the front flipped is what a door looks like. The
     * prop brief's doors and windows were drawn as front elevations for exactly
     * this.
     */
    case Flat = 'flat';
}
