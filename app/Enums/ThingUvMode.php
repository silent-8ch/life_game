<?php

namespace App\Enums;

/**
 * How a thing's texture is laid over its faces.
 *
 * Tiling is right for a surface that carries on past what you can see — a crate
 * of planks, a brick pillar — and wrong for a picture of a particular object. A
 * door texture tiled at the wall scale shows the middle 45% of a door, and it
 * looks like a bug in the art rather than in the UVs.
 */
enum ThingUvMode: string
{
    /** Repeated at TEXTURE_METRES, the same scale walls and floors use. */
    case Tile = 'tile';

    /** Stretched once across each face, so the whole picture shows. */
    case Fit = 'fit';
}
