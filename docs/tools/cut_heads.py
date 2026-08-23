#!/usr/bin/env python3
"""Split whole-figure sprites into a headless body and a head, from boxes drawn by hand.

    python3 docs/tools/cut_heads.py cuts.json

The generator draws whole people, because a headless figure is an unnatural
thing to ask for and comes back wrong. So it keeps drawing whole people and the
head is taken off here instead, by somebody who can see where the neck is.

`public/head-cutter.html` is where the boxes are drawn. It emits JSON of the
shape below and this reads it — the page asks the human, the script does the
work, which is the same split the sprite checker uses.

    {
      "public/sprites/people/v1/paul-000-stand.png": [220, 40, 180, 210],
      ...
    }

Each box is [x, y, width, height] in image pixels around the head.

Writes, beside each source:

    {name}-body.png   the original with the box cleared to transparent
    {name}-head.png   the box contents, cropped
    heads.json        the neck anchor for every file that was cut

**The anchor is the point of this, not the two images.** A head has to land
back on the body in the same place across all fifteen poses of a person, or
heads jump about as they walk. The anchor is the bottom centre of the box —
where the neck meets the shoulders — recorded in the body's coordinates so
whatever composites them has one number to trust rather than a guess.

Standard library only, and the PNG writer is normalise_hand's rather than a
second one.
"""

import json
import os
import sys

sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))
from normalise_hand import write_png
from pnginspect import decode

CLEAR = (0, 0, 0, 0)


def cut(path, box):
    """One figure into a body and a head. Returns the neck anchor."""
    width, height, colour, rows = decode(path)

    if colour != 6:
        raise SystemExit(f'{path}: colour type {colour}, needs 6 (RGBA)')

    left, top, across, down = (int(round(v)) for v in box)

    # A box dragged past an edge is a slip of the mouse, not an instruction to
    # read outside the image.
    left = max(0, min(left, width - 1))
    top = max(0, min(top, height - 1))
    right = max(left + 1, min(left + across, width))
    bottom = max(top + 1, min(top + down, height))

    body = [list(row) for row in rows]
    head = []

    for y in range(top, bottom):
        head.append([rows[y][x] for x in range(left, right)])

        for x in range(left, right):
            body[y][x] = CLEAR

    stem = path[:-4]
    write_png(f'{stem}-body.png', body, width, height)
    write_png(f'{stem}-head.png', head, right - left, bottom - top)

    # Bottom centre of the box: where the neck meets the shoulders.
    return {
        'head': f'{os.path.basename(stem)}-head.png',
        'body': f'{os.path.basename(stem)}-body.png',
        'anchorX': (left + right) // 2,
        'anchorY': bottom,
        'headW': right - left,
        'headH': bottom - top,
    }


def main(argv):
    if len(argv) < 2:
        print(__doc__.strip().splitlines()[0], file=sys.stderr)
        print('usage: cut_heads.py cuts.json', file=sys.stderr)
        return 2

    with open(argv[1]) as handle:
        cuts = json.load(handle)

    if not cuts:
        print('nothing to cut', file=sys.stderr)
        return 1

    anchors = {}
    where = None
    missing = []

    for path in sorted(cuts):
        if not os.path.exists(path):
            missing.append(path)
            continue

        anchors[os.path.basename(path)] = cut(path, cuts[path])
        where = os.path.dirname(path)
        print(f'cut  {path}')

    for path in missing:
        print(f'MISSING  {path}', file=sys.stderr)

    if anchors and where is not None:
        # Beside the art rather than beside the script, because it describes
        # this set of files and travels with them.
        out = os.path.join(where, 'heads.json')

        with open(out, 'w') as handle:
            json.dump(anchors, handle, indent=1, sort_keys=True)
            handle.write('\n')

        print(f'\n{len(anchors)} cut, anchors -> {out}')

    if missing:
        print(f'{len(missing)} named in the cuts file and not on disk', file=sys.stderr)
        return 1

    return 0


if __name__ == '__main__':
    sys.exit(main(sys.argv))
