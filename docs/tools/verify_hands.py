#!/usr/bin/env python3
"""Accept or reject the hand card folder against the spec. Standard library only.

This is the gate. A card is done when this exits 0 for it — not when it looks
right. Run it after normalise_hand.py, never instead of it.

    python3 docs/tools/verify_hands.py public/sprites/hands

Exit status is the number of failures, so it drops straight into CI or a loop.
"""

import os
import sys

sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))
from pnginspect import decode

CANVAS = 887
FLOOR = 24
WRIST_W = 140.0
WRIST_Y = 850

WRIST_W_TOL = 6.0     # px
WRIST_CX_TOL = 6.0    # px from canvas centre
WRIST_Y_TOL = 4       # px

PEOPLE = ['krystal', 'luke', 'luna', 'paul', 'wade', 'william']
POSES = ['edge-open', 'edge', 'back', 'back-fist', 'palm', 'palm-fist']


def measure(path):
    width, height, colour, rows = decode(path)
    facts = {'width': width, 'height': height, 'colour': colour}

    spans = []
    for y in range(height):
        xs = [x for x in range(width) if rows[y][x][3] > FLOOR]
        spans.append((min(xs), max(xs)) if xs else None)

    ys = [y for y, v in enumerate(spans) if v]
    if not ys:
        facts['empty'] = True
        return facts

    y0, y1 = ys[0], ys[-1]
    band = [v for v in spans[max(y0, y1 - int((y1 - y0) * 0.10)):y1 + 1] if v]
    facts['wrist_w'] = sum(b[1] - b[0] + 1 for b in band) / len(band)
    facts['wrist_cx'] = sum((b[0] + b[1]) / 2 for b in band) / len(band)
    facts['bottom'] = y1
    facts['top'] = y0
    facts['left'] = min(v[0] for v in spans if v)
    facts['right'] = max(v[1] for v in spans if v)
    facts['halo'] = sum(1 for y in range(height) for x in range(width)
                        if 0 < rows[y][x][3] <= FLOOR)
    facts['opaque'] = sum(1 for y in range(height) for x in range(width)
                          if rows[y][x][3] > FLOOR)
    facts['colours'] = len({rows[y][x] for y in range(height) for x in range(width)
                            if rows[y][x][3] > FLOOR})
    return facts


def check(path):
    """Return a list of complaints. Empty means the card is good."""
    bad = []
    f = measure(path)

    if f['colour'] != 6:
        bad.append(f'colour type {f["colour"]}, must be 6 (RGBA) — a white '
                   f'background is not transparency')
    if (f['width'], f['height']) != (CANVAS, CANVAS):
        bad.append(f'{f["width"]}x{f["height"]}, must be {CANVAS}x{CANVAS}')
    if f.get('empty'):
        bad.append('nothing drawn')
        return bad

    if f['left'] == 0 or f['right'] == f['width'] - 1:
        bad.append('drawing touches a side edge — the hand is cut off, '
                   'REGENERATE with it smaller in frame (normalising cannot '
                   'restore missing pixels)')
    if f['top'] == 0:
        bad.append('drawing touches the top edge — fingers are cut off, REGENERATE')

    if abs(f['wrist_w'] - WRIST_W) > WRIST_W_TOL:
        bad.append(f'wrist {f["wrist_w"]:.0f}px, must be {WRIST_W:.0f}+-{WRIST_W_TOL:.0f} '
                   f'— run normalise_hand.py')
    if abs(f['wrist_cx'] - CANVAS / 2) > WRIST_CX_TOL:
        bad.append(f'wrist centre x {f["wrist_cx"]:.0f}, must be {CANVAS / 2:.0f}'
                   f'+-{WRIST_CX_TOL:.0f} — run normalise_hand.py')
    if abs(f['bottom'] - WRIST_Y) > WRIST_Y_TOL:
        bad.append(f'lowest row y {f["bottom"]}, must be {WRIST_Y}+-{WRIST_Y_TOL} '
                   f'— run normalise_hand.py')
    if f['halo']:
        bad.append(f'{f["halo"]} near-invisible pixels (0 < alpha <= {FLOOR}) — '
                   f'run normalise_hand.py')
    if f['colours'] < 200:
        bad.append(f'only {f["colours"]} colours — this looks like flat indexed '
                   f'pixel art, the house style is a smooth render')
    if f['opaque'] < CANVAS * CANVAS * 0.04:
        bad.append(f'drawing covers {f["opaque"] * 100 // (CANVAS * CANVAS)}% of the '
                   f'canvas, too small — REGENERATE larger')
    return bad


def main(folder):
    failures = 0
    missing = []

    for who in PEOPLE:
        for pose in POSES:
            path = os.path.join(folder, f'{who}-{pose}.png')
            if not os.path.exists(path):
                missing.append(f'{who}-{pose}.png')
                continue
            bad = check(path)
            if bad:
                failures += 1
                print(f'FAIL {who}-{pose}.png')
                for line in bad:
                    print(f'       {line}')
            else:
                print(f'ok   {who}-{pose}.png')

    if missing:
        print(f'\nMISSING {len(missing)} of {len(PEOPLE) * len(POSES)}:')
        for name in missing:
            print(f'       {name}')

    print(f'\n{failures} failing, {len(missing)} missing, '
          f'{len(PEOPLE) * len(POSES) - failures - len(missing)} good')
    return failures + len(missing)


if __name__ == '__main__':
    sys.exit(main(sys.argv[1] if len(sys.argv) > 1 else 'public/sprites/hands'))
