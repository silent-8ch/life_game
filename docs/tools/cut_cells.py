#!/usr/bin/env python3
"""Cut picked cells out of generator sheets, and keep their landmarks.

    python3 docs/tools/cut_cells.py picks.json [--sheets DIR] [--out DIR]

`public/sheet-picker.html` is where a person looks at a sheet of sixteen
candidates, boxes the one that got the pose right, and clicks six landmarks on
it. It emits the JSON this reads. The page asks the human, the script does the
work, the same split as the head cutter and the sprite checker.

    [
      {
        "sheet": "paul-090-stride-a-sheet.png",
        "box": [412, 88, 190, 300],
        "marks": {
          "neck-left": [70, 4], "neck-right": [118, 6],
          "hand-left": [20, 150], "hand-right": [172, 140],
          "foot-left": [60, 296], "foot-right": [130, 298]
        },
        "name": "paul-090-stride-a"
      }
    ]

For each pick, writes `{name}.png` — the box cropped out of the sheet — and
collects every pick's landmarks into `landmarks.json` beside them.

**The landmarks are the real output.** A cropped cell is just a picture; the
six points are what let it land on a real sprite sheet at the right scale and
in the right place. The neck pair is what a head registers to. The hand and
foot pairs are what a later pose is sized and aligned against, so a stride is
not a different height from a stand. They are recorded in the cropped cell's
own pixels — the page marks them that way on purpose — so cell and landmarks
agree with no arithmetic in between.

Standard library only. The PNG writer is normalise_hand's rather than a
second one.
"""

import argparse
import json
import os
import sys

sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))
from normalise_hand import write_png
from pnginspect import decode

LANDMARKS = ('neck-left', 'neck-right', 'hand-left', 'hand-right', 'foot-left', 'foot-right')


def crop(sheet_path, box):
    """The box out of the sheet, clamped to the image rather than read past it."""
    width, height, colour, rows = decode(sheet_path)

    if colour != 6:
        raise SystemExit(f'{sheet_path}: colour type {colour}, needs 6 (RGBA)')

    left, top, across, down = (int(round(v)) for v in box)
    left = max(0, min(left, width - 1))
    top = max(0, min(top, height - 1))
    right = max(left + 1, min(left + across, width))
    bottom = max(top + 1, min(top + down, height))

    cell = [[rows[y][x] for x in range(left, right)] for y in range(top, bottom)]

    return cell, right - left, bottom - top


def main(argv):
    parser = argparse.ArgumentParser(description='Cut picked cells out of generator sheets.')
    parser.add_argument('picks')
    parser.add_argument('--sheets', default='.', help='folder the sheets are in')
    parser.add_argument('--out', default='public/sprites/people/v1', help='where the cells go')
    args = parser.parse_args(argv[1:])

    with open(args.picks) as handle:
        picks = json.load(handle)

    if not picks:
        print('nothing picked', file=sys.stderr)
        return 1

    os.makedirs(args.out, exist_ok=True)

    landmarks = {}
    missing = []
    incomplete = []

    for pick in picks:
        name = pick.get('name', '').strip()
        sheet = os.path.join(args.sheets, pick['sheet'])

        if not name:
            incomplete.append(f'{pick["sheet"]}: no name')
            continue

        lacking = [k for k in LANDMARKS if k not in pick.get('marks', {})]
        if lacking:
            incomplete.append(f'{name}: missing {", ".join(lacking)}')
            continue

        if not os.path.exists(sheet):
            missing.append(sheet)
            continue

        cell, width, height = crop(sheet, pick['box'])
        out = os.path.join(args.out, f'{name}.png')
        write_png(out, cell, width, height)

        landmarks[name] = {
            'file': f'{name}.png',
            'w': width,
            'h': height,
            'sheet': pick['sheet'],
            'box': pick['box'],
            **{k: pick['marks'][k] for k in LANDMARKS},
        }
        print(f'cut  {out}  {width}x{height}')

    if landmarks:
        path = os.path.join(args.out, 'landmarks.json')
        with open(path, 'w') as handle:
            json.dump(landmarks, handle, indent=1, sort_keys=True)
            handle.write('\n')
        print(f'\n{len(landmarks)} cut, landmarks -> {path}')

    for line in incomplete:
        print(f'SKIPPED  {line}', file=sys.stderr)
    for path in missing:
        print(f'MISSING  {path}', file=sys.stderr)

    return 1 if (missing or incomplete) else 0


if __name__ == '__main__':
    sys.exit(main(sys.argv))
