#!/usr/bin/env python3
"""Verify one versioned person's complete 15-body trial set."""

import os
import sys

sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))
from normalise_people_bodies import FEET_Y, NECK, neck_anchor, opaque_bounds
from pnginspect import decode

DIRECTIONS = ('000', '045', '090', '135', '180')
POSES = ('stand', 'stride-a', 'stride-b')
SIZE = 512


def main(folder, person='paul'):
    expected = {f'{person}-{direction}-{pose}.png' for direction in DIRECTIONS for pose in POSES}
    present = {name for name in os.listdir(folder) if name.endswith('.png')}
    failures = 0

    for name in sorted(expected - present):
        print(f'MISSING {name}')
        failures += 1

    for name in sorted(present - expected):
        print(f'EXTRA {name}')
        failures += 1

    for name in sorted(expected & present):
        path = os.path.join(folder, name)
        width, height, colour, rows = decode(path)
        problems = []

        if (width, height) != (SIZE, SIZE):
            problems.append(f'{width}x{height}, expected {SIZE}x{SIZE}')
        if colour != 6:
            problems.append(f'colour type {colour}, expected RGBA')

        bounds = opaque_bounds(rows, width, height)
        neck_x, neck_y = neck_anchor(rows, bounds)

        if abs(neck_x - NECK[0]) > 2 or abs(neck_y - NECK[1]) > 2:
            problems.append(f'neck ({neck_x:.1f}, {neck_y}), expected {NECK}')
        if abs(bounds[3] - FEET_Y) > 2:
            problems.append(f'feet y={bounds[3]}, expected {FEET_Y}')
        if any(rows[y][x][3] for x, y in ((0, 0), (SIZE - 1, 0), (0, SIZE - 1), (SIZE - 1, SIZE - 1))):
            problems.append('opaque corner')

        if problems:
            failures += 1
            print(f'FAIL {name}: {"; ".join(problems)}')
        else:
            print(f'ok   {name}')

    print(f'\n{failures} failing, {len(expected) - failures} good')
    return failures


if __name__ == '__main__':
    sys.exit(main(sys.argv[1], sys.argv[2] if len(sys.argv) > 2 else 'paul'))
