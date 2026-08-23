#!/usr/bin/env python3
"""Put cut poses onto one sheet, the same height, necks aligned, feet on the ground.

    python3 docs/tools/assemble_sheet.py public/sprites/people/v4
    python3 docs/tools/assemble_sheet.py public/sprites/people/v4 --height 300 --cell 320x360

Reads `landmarks.json` beside the cut cells and writes `sheet.png` and
`sheet.json` in the same folder.

Paul's spec, which is the whole of what this does:

  * A figure's height is from the neck line's centre down to the lowest foot
    marker. Every pose is scaled so that distance is the same.
  * All neck centres land on the same point in their cell.
  * The lowest foot marker is where the sprite touches the ground, so it is
    placed at the cell's baseline.

The two conditions are the same statement twice: if every neck is at one
point and every figure is one height, every lowest foot is at one point too.
That is what makes a walk cycle not bob, and it is what the landmarks were
collected to make possible.

Rows are directions, columns are poses, so the engine's existing convention —
one row per facing, cells along it — holds. The JSON says where each cell is
and where its neck is, so a head can be put on at runtime.

Standard library only. Nearest-neighbour scaling, because the game draws
sprites with NearestFilter and a pre-smoothed sprite looks worse than a
pre-sharp one through that. The PNG writer is normalise_hand's.
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

#: Sheet layout. A row per direction in this order, a column per pose.
DIRECTIONS = ('000', '045', '090', '135', '180')
POSES = ('stand', 'stride-a', 'stride-b')


def parse_name(name):
    """'debug-090-stride-a' -> ('debug', '090', 'stride-a'), or None."""
    m = re.match(r'^(.+?)-(\d{3})-(stand|stride-a|stride-b)$', name)
    return m.groups() if m else None


def figure_of(marks):
    """Neck centre and lowest foot, in the cell's own pixels."""
    nl, nr = marks['neck-left'], marks['neck-right']
    neck = ((nl[0] + nr[0]) / 2, (nl[1] + nr[1]) / 2)
    foot_y = max(marks['foot-left'][1], marks['foot-right'][1])
    return neck, foot_y


def resample(rows, width, height, scale):
    """Nearest-neighbour. Keeps edges hard for NearestFilter."""
    out_w = max(1, round(width * scale))
    out_h = max(1, round(height * scale))
    out = []
    for y in range(out_h):
        sy = min(height - 1, int(y / scale))
        src = rows[sy]
        out.append([src[min(width - 1, int(x / scale))] for x in range(out_w)])
    return out, out_w, out_h


def blit(sheet, sheet_w, sheet_h, rows, w, h, at_x, at_y):
    """Copy rows onto the sheet at (at_x, at_y), skipping transparent pixels."""
    for y in range(h):
        ty = at_y + y
        if ty < 0 or ty >= sheet_h:
            continue
        src = rows[y]
        dst = sheet[ty]
        for x in range(w):
            tx = at_x + x
            if 0 <= tx < sheet_w and src[x][3] != 0:
                dst[tx] = src[x]


def main(argv):
    ap = argparse.ArgumentParser(description=__doc__.split('\n')[0])
    ap.add_argument('folder')
    ap.add_argument('--height', type=int, default=None,
                    help='neck-to-foot height in px for every pose (default: the median of the set)')
    ap.add_argument('--cell', default=None, help='cell size WxH (default: fitted to the scaled figures)')
    ap.add_argument('--out', default='sheet', help='output basename (default: sheet)')
    args = ap.parse_args(argv[1:])

    lm_path = os.path.join(args.folder, 'landmarks.json')
    with open(lm_path) as handle:
        landmarks = json.load(handle)

    # Load, and work out each figure's scale from its own neck-to-foot.
    figures = {}
    for name, m in landmarks.items():
        parsed = parse_name(name)
        if not parsed:
            print(f'skip  {name}: not person-direction-pose', file=sys.stderr)
            continue
        person, direction, pose = parsed
        w, h, colour, rows = decode(os.path.join(args.folder, m['file']))
        if colour != 6:
            raise SystemExit(f'{m["file"]}: colour type {colour}, needs RGBA')
        neck, foot_y = figure_of(m)
        figures[(direction, pose)] = dict(person=person, rows=rows, w=w, h=h,
                                          neck=neck, foot_y=foot_y, tall=foot_y - neck[1])

    if not figures:
        print('nothing to assemble', file=sys.stderr)
        return 1

    talls = sorted(f['tall'] for f in figures.values())
    target = args.height or talls[len(talls) // 2]

    # Scale each figure so neck-to-foot == target, then measure how much
    # room they need above the neck, below the foot, and either side of it.
    above = below = left = right = 0
    for f in figures.values():
        s = target / f['tall']
        f['scale'] = s
        f['rows'], f['w'], f['h'] = resample(f['rows'], f['w'], f['h'], s)
        f['neck'] = (f['neck'][0] * s, f['neck'][1] * s)
        f['foot_y'] = f['foot_y'] * s
        above = max(above, f['neck'][1])
        below = max(below, f['h'] - f['foot_y'])
        left = max(left, f['neck'][0])
        right = max(right, f['w'] - f['neck'][0])

    if args.cell:
        cell_w, cell_h = (int(v) for v in args.cell.lower().split('x'))
    else:
        cell_w = int(left + right) + 8
        cell_h = int(above + target + below) + 8

    # Where every neck lands, and where every lowest foot lands, in the cell.
    neck_x = cell_w // 2
    neck_y = int(above) + 4
    ground_y = neck_y + target

    sheet_w = cell_w * len(POSES)
    sheet_h = cell_h * len(DIRECTIONS)
    sheet = [[CLEAR] * sheet_w for _ in range(sheet_h)]

    placed = {}
    missing = []
    for r, direction in enumerate(DIRECTIONS):
        for c, pose in enumerate(POSES):
            f = figures.get((direction, pose))
            cell_x, cell_y = c * cell_w, r * cell_h
            if f is None:
                missing.append(f'{direction}-{pose}')
                continue
            # Put the neck at the anchor; the foot then lands on the ground
            # by construction, because neck-to-foot is exactly `target`.
            at_x = cell_x + neck_x - round(f['neck'][0])
            at_y = cell_y + neck_y - round(f['neck'][1])
            blit(sheet, sheet_w, sheet_h, f['rows'], f['w'], f['h'], at_x, at_y)
            placed[f'{direction}-{pose}'] = dict(
                row=r, col=c, x=cell_x, y=cell_y, w=cell_w, h=cell_h,
                neck=[cell_x + neck_x, cell_y + neck_y],
                ground=cell_y + ground_y,
                scale=round(f['scale'], 4),
            )

    out_png = os.path.join(args.folder, f'{args.out}.png')
    out_json = os.path.join(args.folder, f'{args.out}.json')
    write_png(out_png, sheet, sheet_w, sheet_h)
    with open(out_json, 'w') as handle:
        json.dump(dict(
            cell=[cell_w, cell_h], height=target,
            neck=[neck_x, neck_y], ground=ground_y,
            directions=list(DIRECTIONS), poses=list(POSES),
            cells=placed, missing=missing,
        ), handle, indent=1)
        handle.write('\n')

    print(f'{len(placed)} placed, height {target}px, cell {cell_w}x{cell_h}, sheet {sheet_w}x{sheet_h}')
    print(f'neck at ({neck_x}, {neck_y}) in every cell, ground at y={ground_y}')
    for m in missing:
        print(f'MISSING  {m}', file=sys.stderr)
    print(f'-> {out_png}\n-> {out_json}')
    return 0


if __name__ == '__main__':
    sys.exit(main(sys.argv))
