<?php

namespace App\Enums;

/**
 * How the lines coming into a thing combine.
 *
 * A thing may have several lines drawn into it, and something has to say what
 * *its input is on* means when they disagree. This is that, and it is also how
 * gates are things rather than a kind of their own: an AND gate is an invisible
 * thing set to `all`, an OR gate is one set to `any`, and a NOT gate is one set
 * to `none`.
 *
 * Paul wanted gates authorable as things even though drawn lines already give
 * the logic. They are — and they needed one column rather than a thing kind,
 * because a gate is not a different sort of object, it is an ordinary one with
 * an opinion about its inputs.
 */
enum LineLogic: string
{
    /** On while any line into it is on. What a wire does, and the default. */
    case Any = 'any';

    /** On while every line into it is on, and there is at least one. An AND. */
    case All = 'all';

    /**
     * On while no line into it is on. A NOT.
     *
     * With nothing drawn into it at all this is **on**, which is not a mistake:
     * a NOT of nothing is a thing that is always on, and redstone calls that a
     * torch. Somebody will find it before anybody documents it.
     */
    case None = 'none';
}
