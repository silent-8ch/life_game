#!/usr/bin/env python3
"""Build the game's two aligned sheets for a character from a direction map.

    python3 docs/tools/assemble_style_sheet.py \
        public/sprites/people/v4/v4-directions.json \
        --cut public/sprites/people/v4/cut \
        --out public/sprites/stylized --style stylized

The engine draws a person from two 1024x1024 atlases — `{sprite}-{style}-cardinal-
aligned-4step.png` and `-diagonal-aligned-4step.png` — each four walk frames
across by four viewing angles down, 256px cells. It scales and lifts each cell
by a fixed band, `FIGURE_IN_CELL = {top: 10/256, feet: 245/256}`, so every
figure must have its crown at y=10 and its feet at y=245 of its cell to stand at
the right height with its feet on the floor.

Which compass angle lives in which row is decided by the per-person table in
`resources/js/lib/engine/sprite-direction.ts`. This tool lays the rows out to
match that table exactly, so the existing table works unchanged — and it bakes
any mirror the direction map calls for into the pixels, so the table needs no
flips of its own.

Input is the gap-finder's direction map: per sprite, each of the 8 angles is a
cut cell, a mirror of another angle, or a copy of another. Each resolved figure
is scaled to the 235px band, centred, and written into all four frame columns
(one standing pose for now; walk frames are a later pass).

Standard library only. decode/write_png are the project's own.
"""

import argparse
import json
import os
import re
import sys

sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))
from normalise_hand import write_png
from pnginspect import decode

CLEAR = (0, 0, 0, 0)

CELL = 256
COLUMNS = 4
ROWS = 4
TOP = 10          # crown lands here, per FIGURE_IN_CELL
FEET = 245        # feet land here
BAND = FEET - TOP  # 235: the height every figure is scaled to

#: The angle order, copied from sprite-direction.ts. `c` is a cardinal row, `d`
#: a diagonal row, listed for 0,45,90,135,180,225,270,315. Kept in step with
#: that file by hand; if a person's row order changes there, change it here.
ORDERS = {
    'paul': 'c0 d3 c1 d2 c2 d1 c3 d0',
    'krystal': 'c0 d0 c3 d2 c2 d1 c1 d3',
    # The stylised newcomers reuse the base person's row order, because their
    # sheets are built to that layout here.
    'paul-toon': 'c0 d3 c1 d2 c2 d1 c3 d0',
    'krystal-toon': 'c0 d0 c3 d2 c2 d1 c1 d3',
}

ANGLES = ['0', '45', '90', '135', '180', '225', '270', '315']


def row_maps(order):
    """From an order string, {cardinal_row: angle} and {diagonal_row: angle}."""
    cardinal, diagonal = {}, {}
    for angle, token in zip(ANGLES, order.split(' ')):
        row = int(token[1:])
        (cardinal if token[0] == 'c' else diagonal)[row] = angle
    return cardinal, diagonal


def resolve(directions, angle, seen=None):
    """Follow copy/mirror to a concrete (file, flip), or None for a gap."""
    seen = seen or set()
    node = directions.get(angle)
    if not node or angle in seen:
        return None
    seen.add(angle)
    if node['kind'] == 'cell':
        return node['file'], False
    base = resolve(directions, node['from'], seen)
    if base is None:
        return None
    file, flip = base
    return file, (not flip) if node['kind'] == 'mirror' else flip


def resample(rows, w, h, scale):
    out_w = max(1, round(w * scale))
    out_h = max(1, round(h * scale))
    out = []
    for y in range(out_h):
        sy = min(h - 1, int(y / scale))
        src = rows[sy]
        out.append([src[min(w - 1, int(x / scale))] for x in range(out_w)])
    return out, out_w, out_h


def place(sheet, cell_x, cell_y, fig, fw, fh, flip):
    """Blit a figure into a cell: crown at TOP, feet at FEET, centred."""
    at_x = cell_x + (CELL - fw) // 2
    at_y = cell_y + TOP
    for y in range(fh):
        ty = at_y + y
        if ty < cell_y or ty >= cell_y + CELL:
            continue
        row = fig[y]
        for x in range(fw):
            px = row[fw - 1 - x] if flip else row[x]
            if px[3] == 0:
                continue
            tx = at_x + x
            if cell_x <= tx < cell_x + CELL:
                sheet[ty][tx] = px


def build_sheet(sprite, kind, rowmap, directions, cut_dir):
    sheet = [[CLEAR] * (CELL * COLUMNS) for _ in range(CELL * ROWS)]
    filled = []
    for row in range(ROWS):
        angle = rowmap.get(row)
        if angle is None:
            continue
        resolved = resolve(directions, angle)
        if resolved is None:
            continue
        file, flip = resolved
        w, h, colour, pix = decode(os.path.join(cut_dir, file))
        if colour == 2:
            pix = [[(p[0], p[1], p[2], 255) for p in r] for r in pix]
        scale = BAND / h
        fig, fw, fh = resample(pix, w, h, scale)
        for col in range(COLUMNS):
            place(sheet, col * CELL, row * CELL, fig, fw, fh, flip)
        filled.append(f'row{row}={angle}°{"~" if flip else ""}')
    return sheet, filled


def main(argv):
    ap = argparse.ArgumentParser(description=__doc__.split('\n')[0])
    ap.add_argument('directions')
    ap.add_argument('--cut', required=True, help='folder with the cut cells + index.json')
    ap.add_argument('--out', required=True, help='style folder to write the sheets into')
    ap.add_argument('--style', required=True, help='style name in the filenames, e.g. stylized')
    args = ap.parse_args(argv[1:])

    with open(args.directions) as handle:
        maps = json.load(handle)
    os.makedirs(args.out, exist_ok=True)

    for entry in maps:
        sprite = entry['sprite']
        directions = entry['directions']
        if sprite not in ORDERS:
            print(f'skip {sprite}: no order in sprite-direction.ts table', file=sys.stderr)
            continue
        cardinal_rows, diagonal_rows = row_maps(ORDERS[sprite])
        for kind, rowmap in (('cardinal', cardinal_rows), ('diagonal', diagonal_rows)):
            sheet, filled = build_sheet(sprite, kind, rowmap, directions, args.cut)
            name = f'{sprite}-{args.style}-{kind}-aligned-4step.png'
            write_png(os.path.join(args.out, name), sheet, CELL * COLUMNS, CELL * ROWS)
            print(f'{name}: {", ".join(filled)}')

    return 0


if __name__ == '__main__':
    sys.exit(main(sys.argv))
