#!/usr/bin/env python3
"""Accept or reject the prop sprite folder against the brief. Standard library only.

The companion to verify_hands.py, for props rather than hand cards. A prop is
done when this exits 0 for it, not when it looks right.

    python3 docs/tools/verify_props.py public/sprites/props

The specification is docs/handoff-prop-sprites.md itself — the table of every
prop, its real size in metres and the pixel size that follows from 128 px per
metre is read straight out of the file. There is deliberately no second copy of
those numbers here to drift away from it.

What is checked, and why each one rather than left to the eye:

    colour type 6     A prop with no alpha channel cannot be cut out at all.
    exact size        The image is stretched onto its box exactly once, so a
                      wrong aspect ratio is a squashed door and nothing warns.
    hard alpha        Props draw with alphaTest, which keeps a pixel only if it
                      is more than half opaque. A feathered edge does not soften,
                      it tears; a drop shadow does not fade, it vanishes.
    not a rectangle   Cross-plane props only. Foliage whose silhouette fills its
                      frame reads as two crossed boards from every angle.
    on the axis       Cross-plane props only. The planes intersect on the
                      vertical centre line, so off-centre ink misses itself.

Exit status is the number of failures, so it drops straight into CI or a loop.
"""

import os
import re
import sys

sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))
from pnginspect import decode

HERE = os.path.dirname(os.path.abspath(__file__))
BRIEF = os.path.join(HERE, '..', 'handoff-prop-sprites.md')

FLOOR = 24            # alpha at or below this is nothing drawn at all
SOLID = 247           # alpha at or above this is unambiguously kept

HALO_FRACTION = 0.06  # part-transparent pixels, as a share of what is drawn
CROSS_COVERAGE = 0.80 # a silhouette filling more of its frame than this is a board
CROSS_CENTRE = 0.04   # ink may sit this far off the axis, as a share of width

ROW = re.compile(
    r'^\|\s*`([^`]+\.png)`\s*\|\s*([\d.]+)\s*[x×]\s*([\d.]+)\s*\|'
    r'\s*(\d+)\s*[x×]\s*(\d+)\s*\|(.*)$'
)


def wanted(path):
    """The brief's table, read as the specification it is."""
    specs = {}
    with open(path) as handle:
        for line in handle:
            found = ROW.match(line.strip())
            if not found:
                continue
            name, metres_w, metres_h, px_w, px_h, notes = found.groups()
            specs[name] = {
                'metres': (float(metres_w), float(metres_h)),
                'pixels': (int(px_w), int(px_h)),
                # The table's own mode column says so. Do not guess from the
                # name: plant-succulent is a billboard and plant-pot is a box,
                # and holding either to the cross rules would fail good art.
                'cross': 'cross' in notes.lower(),
            }
    return specs


def faults(path, spec):
    """Every way this one file can be wrong. Measured, never eyeballed."""
    found = []
    width, height, colour, rows = decode(path)

    if colour != 6:
        found.append(f'colour type {colour}, needs 6 (RGBA)')

    want_w, want_h = spec['pixels']
    if (width, height) != (want_w, want_h):
        real_w, real_h = spec['metres']
        found.append(
            f'{width}x{height}, the table says {want_w}x{want_h} '
            f'({real_w}x{real_h} m at 128 px/m)'
        )

    alpha = [rows[y][x][3] for y in range(height) for x in range(width)]
    drawn = sum(1 for a in alpha if a > FLOOR)
    if not drawn:
        found.append('nothing drawn — the whole canvas is transparent')
        return found

    halo = sum(1 for a in alpha if FLOOR < a < SOLID)
    if halo / drawn > HALO_FRACTION:
        found.append(
            f'{halo / drawn:.0%} of drawn pixels are part-transparent '
            f'(limit {HALO_FRACTION:.0%}) — feathered edge, glow or drop shadow'
        )

    if not spec['cross']:
        return found

    columns = [x for y in range(height) for x in range(width)
               if rows[y][x][3] >= SOLID]
    if not columns:
        found.append('cross-plane prop with no solid silhouette at all')
        return found

    left, right = min(columns), max(columns)
    solid_rows = [y for y in range(height) for x in range(width)
                  if rows[y][x][3] >= SOLID]
    top, bottom = min(solid_rows), max(solid_rows)

    coverage = ((right - left + 1) * (bottom - top + 1)) / (width * height)
    if coverage > CROSS_COVERAGE:
        found.append(
            f'silhouette fills {coverage:.0%} of the frame '
            f'(limit {CROSS_COVERAGE:.0%}) — it will read as two crossed boards'
        )

    middle = (left + right) / 2
    drift = abs(middle - width / 2) / width
    if drift > CROSS_CENTRE:
        found.append(
            f'ink centred at x={middle:.0f} against a canvas centre of '
            f'{width / 2:.0f} — {drift:.0%} off the axis, the planes will miss'
        )

    return found


def main(argv):
    folder = argv[1] if len(argv) > 1 else 'public/sprites/props'
    specs = wanted(BRIEF)
    if not specs:
        print(f'no prop table found in {BRIEF}', file=sys.stderr)
        return 2

    print(f'{len(specs)} props in the brief, checking {folder}\n')

    missing, failed, passed = [], [], []
    for name in sorted(specs):
        path = os.path.join(folder, name)
        if not os.path.exists(path):
            missing.append(name)
            continue
        found = faults(path, specs[name])
        (failed if found else passed).append((name, found))

    for name, found in failed:
        print(f'FAIL {name}')
        for fault in found:
            print(f'       {fault}')

    if os.path.isdir(folder):
        for name in sorted(os.listdir(folder)):
            if not name.endswith('.png') or name in specs:
                continue
            stem = re.sub(r'-(\d+|[a-z]+)\.png$', '.png', name)
            if stem not in specs:
                print(f'NOTE {name} is not in the brief\'s table')

    print(f'\n{len(passed)} pass, {len(failed)} fail, {len(missing)} not yet drawn')
    if missing and len(missing) < len(specs):
        print('missing: ' + ', '.join(missing))

    return len(failed)


if __name__ == '__main__':
    sys.exit(main(sys.argv))
