#!/usr/bin/env python3
"""Verify the high-density seamless surface texture set."""

import os
import struct
import sys

sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))
from pnginspect import decode

SIZE = 768
# Generated albedo edges may retain fine-grain variation even when their large
# forms tile correctly. Values above one eighth of the 8-bit range are treated
# as a visible boundary.
MAX_EDGE_ERROR = 32.0
EXPECTED = {
    'asphalt-path', 'asphalt-shingles', 'blue-carpet', 'blue-rug',
    'castle-stone-wall', 'cedar-shingles', 'cedar-siding', 'checker-floor',
    'clover-ground', 'concrete-wall', 'cream-carpet', 'cream-plaster-wall',
    'dark-soil', 'dark-wood-floor', 'deep-water', 'dock-planks', 'dry-grass',
    'fallen-leaves', 'fieldstone-wall', 'floral-rug', 'floral-wallpaper',
    'flower-patch', 'fountain-water', 'garden-bed', 'garden-soil',
    'gravel-ground', 'ice-ground', 'kitchen-tile', 'marble-floor',
    'metal-roof', 'mosaic-tile', 'moss-ground', 'mud-ground', 'oak-floor',
    'ocean-water', 'packed-path', 'painted-brick-wall', 'pale-wood-floor',
    'parquet-floor', 'pebble-bed', 'picnic-blanket', 'pine-needles',
    'pond-water', 'pool-water', 'red-brick-path', 'red-rug', 'red-siding',
    'river-water', 'rose-carpet', 'shallow-water', 'slate-path', 'slate-roof',
    'snow-ground', 'speckled-linoleum', 'spring-grass', 'stucco-wall',
    'subway-tile-wall', 'swamp-water', 'terracotta-roof', 'thatch-roof',
    'weathered-deck', 'white-siding', 'wood-panel-wall', 'workshop-floor',
}


def bit_depth(path):
    with open(path, 'rb') as image:
        header = image.read(29)

    return struct.unpack('>IIBBBBB', header[16:29])[2]


def edge_error(rows, width, height):
    horizontal = sum(
        abs(rows[y][0][channel] - rows[y][-1][channel])
        for y in range(height)
        for channel in range(3)
    ) / (height * 3)
    vertical = sum(
        abs(rows[0][x][channel] - rows[-1][x][channel])
        for x in range(width)
        for channel in range(3)
    ) / (width * 3)

    return horizontal, vertical


def main(folder):
    present = {
        os.path.splitext(name)[0]
        for name in os.listdir(folder)
        if name.endswith('.png')
    }
    failures = 0

    for name in sorted(EXPECTED):
        path = os.path.join(folder, f'{name}.png')

        if not os.path.exists(path):
            print(f'MISSING {name}.png')
            failures += 1
            continue

        width, height, colour, rows = decode(path)
        problems = []

        if (width, height) != (SIZE, SIZE):
            problems.append(f'{width}x{height}, expected {SIZE}x{SIZE}')
        if bit_depth(path) != 8:
            problems.append('must be 8-bit')
        if colour not in (2, 6):
            problems.append(f'colour type {colour}, expected RGB or RGBA')

        horizontal, vertical = edge_error(rows, width, height)
        if max(horizontal, vertical) > MAX_EDGE_ERROR:
            problems.append(
                f'edge error left/right {horizontal:.1f}, top/bottom {vertical:.1f}'
            )

        if problems:
            failures += 1
            print(f'FAIL {name}.png: {"; ".join(problems)}')
        else:
            print(f'ok   {name}.png')

    for name in sorted(present - EXPECTED):
        print(f'EXTRA {name}.png')
        failures += 1

    print(f'\n{failures} failing, {len(EXPECTED) - failures} good')
    return failures


if __name__ == '__main__':
    sys.exit(main(sys.argv[1] if len(sys.argv) > 1 else 'public/sprites/textures'))
