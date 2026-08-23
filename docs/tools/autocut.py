#!/usr/bin/env python3
"""Cut a sheet of figures into one tight PNG each, found by their gutters.

    python3 docs/tools/autocut.py public/sprites/people/v4/paul-stylized-fullbody-options.png \
                                   public/sprites/people/v4/krystal-stylized-fullbody-options.png

No grid to place. The sheet is keyed to transparency, so the figures are
separated by fully transparent gutters — rows and columns with nothing opaque
in them. This finds those gutters and takes what is between them.

It works in two passes, the way a grid of figures is actually laid out:

  1. Split the sheet into horizontal bands — runs of rows that hold something,
     separated by empty rows. Each band is one row of figures.
  2. Within each band, split into columns the same way — runs of columns that
     hold something, separated by empty columns. Each is one figure.

Every cell is then the exact alpha bounding box of its figure, so the cuts are
tight and one figure each regardless of how the figures are spaced. Two figures
with no gap between them have no gutter to find and come out as one wide cell —
reported, so it is visible rather than silent.

Cells go to a `cut/` folder beside the sheets as `{stem}-r{row}c{col}.png`, and
`index.json` lists them with size and the line-up anchor (feet = bottom-centre,
crown = top), the same shape the gap-finder reads.

Standard library only. decode/write_png are the project's own.
"""

import argparse
import json
import os
import sys

sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))
from normalise_hand import write_png
from pnginspect import decode

#: Alpha at or above which a pixel is part of a figure.
OPAQUE = 40
#: A row/column with this many opaque pixels or fewer is treated as empty, so a
#: stray keyed speck does not glue two figures together or invent a cell.
NOISE = 2
#: Bands smaller than this in either axis are dropped as specks, not figures.
MIN_SPAN = 12


def alpha(px):
    return px[3] if len(px) > 3 else 255


def bands(is_full, length):
    """Runs of `is_full(i)` truthy indices, as (start, end_exclusive) pairs."""
    out = []
    start = None
    for i in range(length):
        if is_full(i):
            if start is None:
                start = i
        elif start is not None:
            out.append((start, i))
            start = None
    if start is not None:
        out.append((start, length))
    return [(a, b) for (a, b) in out if b - a >= MIN_SPAN]


def opaque_in_row(rows, y, x0, x1):
    return sum(1 for x in range(x0, x1) if alpha(rows[y][x]) >= OPAQUE)


def opaque_in_col(rows, x, y0, y1):
    return sum(1 for y in range(y0, y1) if alpha(rows[y][x]) >= OPAQUE)


def bbox(rows, x0, x1, y0, y1):
    """Tight alpha bounding box within a window, or None if it is empty."""
    min_x, min_y, max_x, max_y = x1, y1, -1, -1
    for y in range(y0, y1):
        for x in range(x0, x1):
            if alpha(rows[y][x]) >= OPAQUE:
                min_x = min(min_x, x); max_x = max(max_x, x)
                min_y = min(min_y, y); max_y = max(max_y, y)
    if max_x < 0:
        return None
    return min_x, min_y, max_x + 1, max_y + 1


def main(argv):
    ap = argparse.ArgumentParser(description=__doc__.split('\n')[0])
    ap.add_argument('sheets', nargs='+', help='one or more keyed sheet PNGs')
    ap.add_argument('--out', default=None, help='where cells go (default: a cut/ folder beside the first sheet)')
    args = ap.parse_args(argv[1:])

    out = args.out or os.path.join(os.path.dirname(os.path.abspath(args.sheets[0])), 'cut')
    os.makedirs(out, exist_ok=True)

    index = []
    wide = []

    for path in args.sheets:
        width, height, colour, rows = decode(path)
        if colour == 2:
            rows = [[(px[0], px[1], px[2], 255) for px in row] for row in rows]

        stem = os.path.splitext(os.path.basename(path))[0].replace('-options', '')

        row_bands = bands(lambda y: opaque_in_row(rows, y, 0, width) > NOISE, height)
        total = 0
        for ri, (y0, y1) in enumerate(row_bands):
            col_bands = bands(lambda x: opaque_in_col(rows, x, y0, y1) > NOISE, width)
            for ci, (x0, x1) in enumerate(col_bands):
                box = bbox(rows, x0, x1, y0, y1)
                if box is None:
                    continue
                bx0, by0, bx1, by1 = box
                w, h = bx1 - bx0, by1 - by0
                cell = [[rows[y][x] for x in range(bx0, bx1)] for y in range(by0, by1)]
                name = f'{stem}-r{ri}c{ci}.png'
                write_png(os.path.join(out, name), cell, w, h)
                index.append({
                    'file': name,
                    'url': f'/sprites/people/v4/cut/{name}',
                    'sheet': os.path.basename(path),
                    'row': ri, 'col': ci,
                    'w': w, 'h': h,
                    'feet': [w // 2, h],
                    'crown': [w // 2, 0],
                })
                total += 1
                # A cell far wider than it is tall is almost certainly two
                # figures with no gutter between them — worth flagging.
                if w > h:
                    wide.append(f'{name} ({w}x{h})')
            print(f'  band {ri}: {len(col_bands)} figures', flush=True)
        print(f'{os.path.basename(path)}: {total} figures')

    with open(os.path.join(out, 'index.json'), 'w') as handle:
        json.dump(index, handle, indent=1)
        handle.write('\n')
    print(f'\n{len(index)} cells -> {out}/  (index.json written)')

    for w in wide:
        print(f'WIDE (two figures touching?)  {w}', file=sys.stderr)
    return 0


if __name__ == '__main__':
    sys.exit(main(sys.argv))
