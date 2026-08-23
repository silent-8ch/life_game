#!/usr/bin/env python3
"""Knock a chroma-key background out to transparency.

    python3 docs/tools/unkey.py in.png [out.png]
    python3 docs/tools/unkey.py --key ff00ff --tolerance 60 in.png
    python3 docs/tools/unkey.py --in-place public/sprites/people/v1/*.png

The people brief asks the generator for a perfectly uniform #00FF00 background,
and the generator never quite delivers one: the green varies a little across
the field, and at the figure's edge it blends into the body over a pixel or
two. So this does three things rather than one.

1. Keys by distance in colour, not by equality. A pixel within `tolerance`
   of the key colour is background. The default is loose enough to take a
   slightly uneven field and tight enough not to eat the figure.

2. Unspills the edge. A pixel that is part body, part key has green mixed
   into it, and dropping only the fully-keyed pixels leaves a green fringe
   round the whole figure — the classic chroma fault. Pixels near the key
   get their green channel pulled back to the larger of red and blue, which
   removes the cast. A genuinely green shirt loses a little saturation too —
   that is the cost of not being able to tell spill from cloth by colour alone.

3. Makes the edge soft, then hard. The blend zone is turned into partial
   alpha so the outline is not jagged, and then anything below half alpha is
   dropped and anything above is made solid — because the engine draws
   sprites with alphaTest and a half-transparent pixel does not fade, it
   tears. Hard alpha is the spec in every art brief in this project.

Standard library only. The PNG writer is normalise_hand's rather than a second
one.
"""

import argparse
import glob
import os
import sys

sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))
from normalise_hand import write_png
from pnginspect import decode

CLEAR = (0, 0, 0, 0)


def parse_key(text):
    text = text.lstrip('#')
    if len(text) != 6:
        raise SystemExit(f'key must be six hex digits, got {text!r}')
    return tuple(int(text[i:i + 2], 16) for i in (0, 2, 4))


def distance(a, b):
    """Straight-line distance in RGB. Good enough for a flat key field."""
    return ((a[0] - b[0]) ** 2 + (a[1] - b[1]) ** 2 + (a[2] - b[2]) ** 2) ** 0.5


def unkey(rows, width, height, key, tolerance, spill):
    """Background out, spill off, hard edge. Returns the new rows."""
    out = []
    keyed = 0
    edge = 0

    # The blend band: inside `tolerance` is background outright; between
    # tolerance and tolerance + spill the pixel is partly key and partly body,
    # and alpha ramps across it.
    soft = tolerance + spill

    for y in range(height):
        row = []
        for x in range(width):
            r, g, b, a = rows[y][x]

            if a == 0:
                row.append(CLEAR)
                continue

            d = distance((r, g, b), key)

            if d <= tolerance:
                row.append(CLEAR)
                keyed += 1
                continue

            if d < soft:
                # Partly background. Ramp alpha across the band, and pull the
                # key's dominant channel back so the fringe loses its cast.
                t = (d - tolerance) / spill
                edge += 1
                r, g, b = despill((r, g, b), key)
                alpha = round(t * 255)
                # Hard alpha: alphaTest makes a soft edge tear, so the ramp
                # only decides which side of the line a pixel falls.
                if alpha < 128:
                    row.append(CLEAR)
                else:
                    row.append((r, g, b, 255))
                continue

            # Past the blend band but still green-tinged. Spill reaches
            # further than the alpha ramp does: a pixel can be solidly body
            # and still carry a cast from the field next to it.
            if d < soft * 2 and is_cast(r, g, b, key):
                r, g, b = despill((r, g, b), key)

            row.append((r, g, b, 255 if a else 0))

        out.append(row)

    return out, keyed, edge


def is_cast(r, g, b, key):
    """Whether the key's channel is the largest — a tell for spill on skin
    or cloth that was never that colour. A genuinely green shirt is green
    in all three senses and gets capped too, which is the acceptable cost."""
    dominant = max(range(3), key=lambda i: key[i])
    return (r, g, b)[dominant] > max(v for i, v in enumerate((r, g, b)) if i != dominant)


def despill(pixel, key):
    """Pull the key's strongest channel back to the next-strongest.

    For a green key that means green can be no higher than max(red, blue).
    A pixel that was genuinely green stays green-ish; a pixel that was skin
    with green bled into it loses the bleed. Works for any key by finding
    which channel the key is made of.
    """
    dominant = max(range(3), key=lambda i: key[i])
    others = [pixel[i] for i in range(3) if i != dominant]
    cap = max(others)
    fixed = list(pixel)
    if fixed[dominant] > cap:
        fixed[dominant] = cap
    return tuple(fixed)


def process(src, dst, key, tolerance, spill):
    width, height, colour, rows = decode(src)

    if colour not in (2, 6):
        raise SystemExit(f'{src}: colour type {colour}, needs RGB or RGBA')

    # decode may hand back RGB or RGBA tuples regardless of the declared colour
    # type, so normalise on the pixel's own length rather than on `colour`.
    rows = [[(px[0], px[1], px[2], px[3] if len(px) > 3 else 255) for px in row] for row in rows]

    out, keyed, edge = unkey(rows, width, height, key, tolerance, spill)
    write_png(dst, out, width, height)

    total = width * height
    print(f'{os.path.basename(src)}  {keyed * 100 // total}% keyed, {edge} edge pixels  -> {os.path.basename(dst)}')


def main(argv):
    parser = argparse.ArgumentParser(description='Knock a chroma-key background out to transparency.')
    parser.add_argument('paths', nargs='+', help='PNG in, or in and out; globs allowed with --in-place')
    parser.add_argument('--key', default='00ff00', help='background colour as hex (default 00ff00)')
    parser.add_argument('--tolerance', type=float, default=48,
                        help='how far from the key still counts as background (default 48)')
    parser.add_argument('--spill', type=float, default=40,
                        help='width of the blend band past tolerance, in colour distance (default 40)')
    parser.add_argument('--in-place', action='store_true', help='overwrite each input')
    args = parser.parse_args(argv[1:])

    key = parse_key(args.key)

    if args.in_place:
        files = [p for pattern in args.paths for p in sorted(glob.glob(pattern))]
        if not files:
            print('no files matched', file=sys.stderr)
            return 1
        for path in files:
            process(path, path, key, args.tolerance, args.spill)
        return 0

    if len(args.paths) == 1:
        src = args.paths[0]
        stem, ext = os.path.splitext(src)
        dst = f'{stem}-unkeyed{ext}'
    elif len(args.paths) == 2:
        src, dst = args.paths
    else:
        print('give one path, two paths, or --in-place with globs', file=sys.stderr)
        return 2

    process(src, dst, key, args.tolerance, args.spill)
    return 0


if __name__ == '__main__':
    sys.exit(main(sys.argv))
