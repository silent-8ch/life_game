#!/usr/bin/env python3
"""Cut approved head cells out of option sheets, keeping the top-and-chin line.

    python3 docs/tools/cut_heads_v2.py public/sprites/people/v4/approved-head-picks.json

The sheet picker was used on head sheets with a two-point landmark set —
`head-top` and `chin` — rather than the six-point body set, because a head has
no hands or feet and what matters for placing it is the vertical line from
crown to jaw. `cut_cells.py` requires all six body marks and refuses these, so
this is its two-point sibling.

For each pick it writes `{name}.png` beside the sheet and records, in
`heads.json`:

  * `top` and `chin` in the cut cell's own pixels — the crown and the jaw.
  * `height` — chin minus top, the face's own length, so a head can be scaled
    to a body the way bodies were scaled to each other.
  * `anchorX` — the midpoint of the top-chin line, the vertical the head hangs
    on. It lands on the body's neck-centre at runtime.

The chin is the join. A body's neck-centre is where its head sits; the head's
chin, placed there, puts the face on the neck. That is the whole registration,
and it is why the picker was told to mark those two points and no others.

Standard library only. PNG writer is normalise_hand's.
"""

import argparse
import json
import os
import sys

sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))
from normalise_hand import write_png
from pnginspect import decode

MARKS = ('head-top', 'chin')


def crop(sheet_path, box):
    width, height, colour, rows = decode(sheet_path)
    if colour != 6:
        raise SystemExit(f'{sheet_path}: colour type {colour}, needs RGBA')
    left, top, across, down = (int(round(v)) for v in box)
    left = max(0, min(left, width - 1))
    top = max(0, min(top, height - 1))
    right = max(left + 1, min(left + across, width))
    bottom = max(top + 1, min(top + down, height))
    cell = [[rows[y][x] for x in range(left, right)] for y in range(top, bottom)]
    return cell, right - left, bottom - top


def main(argv):
    ap = argparse.ArgumentParser(description=__doc__.split('\n')[0])
    ap.add_argument('picks')
    ap.add_argument('--sheets', default=None, help='folder the sheets are in (default: the picks folder)')
    ap.add_argument('--out', default=None, help='where the cells go (default: the picks folder)')
    args = ap.parse_args(argv[1:])

    with open(args.picks) as handle:
        picks = json.load(handle)

    base = os.path.dirname(os.path.abspath(args.picks))
    sheets = args.sheets or base
    out = args.out or base
    os.makedirs(out, exist_ok=True)

    heads = {}
    missing, incomplete = [], []

    for pick in picks:
        name = pick.get('name', '').strip()
        sheet = os.path.join(sheets, pick['sheet'])

        if not name:
            incomplete.append(f'{pick["sheet"]}: no name')
            continue
        lacking = [k for k in MARKS if k not in pick.get('marks', {})]
        if lacking:
            incomplete.append(f'{name}: missing {", ".join(lacking)}')
            continue
        if not os.path.exists(sheet):
            missing.append(sheet)
            continue

        cell, w, h = crop(sheet, pick['box'])
        write_png(os.path.join(out, f'{name}.png'), cell, w, h)

        top = pick['marks']['head-top']
        chin = pick['marks']['chin']
        heads[name] = {
            'file': f'{name}.png',
            'w': w, 'h': h,
            'top': top,
            'chin': chin,
            'height': chin[1] - top[1],
            'anchorX': round((top[0] + chin[0]) / 2),
            'sheet': pick['sheet'],
            'box': pick['box'],
        }
        print(f'cut  {name}.png  {w}x{h}  face {chin[1] - top[1]}px')

    if heads:
        path = os.path.join(out, 'heads.json')
        with open(path, 'w') as handle:
            json.dump(heads, handle, indent=1, sort_keys=True)
            handle.write('\n')
        print(f'\n{len(heads)} cut, -> {path}')

    for line in incomplete:
        print(f'SKIPPED  {line}', file=sys.stderr)
    for path in missing:
        print(f'MISSING  {path}', file=sys.stderr)

    return 1 if (missing or incomplete) else 0


if __name__ == '__main__':
    sys.exit(main(sys.argv))
