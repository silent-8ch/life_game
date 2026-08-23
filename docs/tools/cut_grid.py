#!/usr/bin/env python3
"""Cut every cell a grid carves out of a sheet into its own tight figure PNG.

    python3 docs/tools/cut_grid.py public/sprites/people/v4/v4-grid.json

Reads the grid JSON the v4 checker produces — a list of
`{sheet, cols, rows, cells:[{row, col, box:[x,y,w,h]}]}` — and, for each sheet,
takes the region the grid divides and then **trims to the figure inside it**.

The grid cells are not the same size and each one carries blank padding around
the drawing — a wide last column is mostly empty, a walk pose fills less of its
cell than a stand. So cutting the raw rectangle gives ragged, differently sized
images that do not line up. Instead this finds the relevant pixels — the alpha
bounding box of the drawing within the divided cell — and crops to exactly that.

Each tight cell is written to a `cut/` folder as `{sheet-stem}-r{row}c{col}.png`,
and `index.json` records, per cell, its size and the anchor to line it up by:
the figure's feet (the bottom-centre of the trimmed image) and its crown (the
top). Placing every figure's feet on one baseline and its centre on one line is
what makes them line up in the final sheet.

Standard library only. decode/write_png are the project's own.
"""

import argparse
import json
import os
import sys

sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))
from normalise_hand import write_png
from pnginspect import decode


#: A pixel counts as part of the figure at or above this alpha. Above the
#: keyer's hard-alpha floor, so a stray half-keyed speck does not widen the box.
OPAQUE = 40


def crop(rows, width, height, box):
    left, top, across, down = (int(round(v)) for v in box)
    left = max(0, min(left, width - 1))
    top = max(0, min(top, height - 1))
    right = max(left + 1, min(left + across, width))
    bottom = max(top + 1, min(top + down, height))
    cell = [[rows[y][x] for x in range(left, right)] for y in range(top, bottom)]
    return cell, right - left, bottom - top


def trim(cell, width, height):
    """Crop a cell to the alpha bounding box of the drawing inside it.

    Returns the tight pixels, its width and height. A cell with nothing opaque
    in it comes back unchanged, so an empty cell is visible rather than crashing.
    """
    min_x, min_y, max_x, max_y = width, height, -1, -1
    for y in range(height):
        row = cell[y]
        for x in range(width):
            if row[x][3] >= OPAQUE:
                if x < min_x:
                    min_x = x
                if x > max_x:
                    max_x = x
                if y < min_y:
                    min_y = y
                if y > max_y:
                    max_y = y
    if max_x < 0:
        return cell, width, height
    tight = [[cell[y][x] for x in range(min_x, max_x + 1)] for y in range(min_y, max_y + 1)]
    return tight, max_x - min_x + 1, max_y - min_y + 1


def main(argv):
    ap = argparse.ArgumentParser(description=__doc__.split('\n')[0])
    ap.add_argument('grid', help='the grid JSON from the v4 checker')
    ap.add_argument('--sheets', default=None, help='folder the sheets are in (default: the grid JSON folder)')
    ap.add_argument('--out', default=None, help='where cells go (default: a cut/ folder beside the sheets)')
    args = ap.parse_args(argv[1:])

    with open(args.grid) as handle:
        grids = json.load(handle)

    base = os.path.dirname(os.path.abspath(args.grid))
    sheets = args.sheets or base
    out = args.out or os.path.join(base, 'cut')
    os.makedirs(out, exist_ok=True)

    index = []
    missing = []

    for grid in grids:
        sheet = grid['sheet']
        path = os.path.join(sheets, sheet)
        if not os.path.exists(path):
            missing.append(path)
            continue

        width, height, colour, rows = decode(path)
        if colour == 2:
            rows = [[(px[0], px[1], px[2], 255) for px in row] for row in rows]

        stem = os.path.splitext(sheet)[0].replace('-options', '')
        for cell in grid['cells']:
            r, c = cell['row'], cell['col']
            pixels, w, h = crop(rows, width, height, cell['box'])
            pixels, w, h = trim(pixels, w, h)
            name = f'{stem}-r{r}c{c}.png'
            write_png(os.path.join(out, name), pixels, w, h)
            index.append({
                'file': name,
                'url': f'/sprites/people/v4/cut/{name}',
                'sheet': sheet,
                'row': r, 'col': c,
                'w': w, 'h': h,
                # Line-up anchor, in the tight image's own pixels: feet is the
                # bottom-centre, crown the top. Feet on one baseline, centre on
                # one line, and the figures line up.
                'feet': [w // 2, h],
                'crown': [w // 2, 0],
            })
        print(f'cut {len(grid["cells"])} cells from {sheet}')

    with open(os.path.join(out, 'index.json'), 'w') as handle:
        json.dump(index, handle, indent=1)
        handle.write('\n')
    print(f'\n{len(index)} cells -> {out}/  (index.json written)')

    for path in missing:
        print(f'MISSING  {path}', file=sys.stderr)
    return 1 if missing else 0


if __name__ == '__main__':
    sys.exit(main(sys.argv))
