#!/usr/bin/env python3
"""Make a texture tile using deterministic irregular wrap cuts.

The original borders move into the image along smooth, wavy cuts, where their
joins are feathered. This avoids both a visible border and a straight cross.
No model output is used as input and no existing project texture is consulted.

    python3 normalise_texture.py source.png destination.png
"""

import math
import os
import struct
import sys
import zlib

sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))
from pnginspect import decode

FEATHER = 36
WAVE = 16


def mix(first, second, strength):
    return tuple(round(channel_a * (1 - strength) + channel_b * strength)
                 for channel_a, channel_b in zip(first, second))


def wave(index, length):
    angle = math.tau * index / length
    return round(
        math.sin(angle * 2) * WAVE
        + math.sin(angle * 5 + 0.7) * WAVE * 0.38
    )


def normalise(rows, width, height):
    seam_x = width // 2
    seam_y = height // 2
    horizontal = [
        [row[(x + seam_x) % width] for x in range(width)]
        for row in rows
    ]

    for y in range(height):
        feather_x = min(FEATHER + wave(y, height), width // 4)

        for distance in range(feather_x):
            left = seam_x - 1 - distance
            right = seam_x + distance
            strength = (1 + math.cos(math.pi * distance / feather_x)) / 2
            average = tuple(round((a + b) / 2)
                            for a, b in zip(horizontal[y][left], horizontal[y][right]))
            horizontal[y][left] = mix(horizontal[y][left], average, strength)
            horizontal[y][right] = mix(horizontal[y][right], average, strength)

    vertical = [
        list(horizontal[(y + seam_y) % height])
        for y in range(height)
    ]

    for x in range(width):
        feather_y = min(FEATHER + wave(x, width), height // 4)

        for distance in range(feather_y):
            top = seam_y - 1 - distance
            bottom = seam_y + distance
            strength = (1 + math.cos(math.pi * distance / feather_y)) / 2
            average = tuple(round((a + b) / 2)
                            for a, b in zip(vertical[top][x], vertical[bottom][x]))
            vertical[top][x] = mix(vertical[top][x], average, strength)
            vertical[bottom][x] = mix(vertical[bottom][x], average, strength)

    for y in range(height):
        vertical[y][-1] = vertical[y][0]

    vertical[-1] = list(vertical[0])

    return vertical


def write_png(path, rows, width, height):
    raw = bytearray()

    for row in rows:
        raw.append(0)

        for red, green, blue, alpha in row:
            raw.extend((red, green, blue, alpha))

    def chunk(kind, body):
        data = kind.encode('ascii') + body
        return (struct.pack('>I', len(body)) + data
                + struct.pack('>I', zlib.crc32(data)))

    with open(path, 'wb') as output:
        output.write(
            b'\x89PNG\r\n\x1a\n'
            + chunk('IHDR', struct.pack('>IIBBBBB', width, height, 8, 6, 0, 0, 0))
            + chunk('IDAT', zlib.compress(bytes(raw), 9))
            + chunk('IEND', b'')
        )


def main(source, destination):
    width, height, _, rows = decode(source)
    write_png(destination, normalise(rows, width, height), width, height)
    print(f'{source} -> {destination}')


if __name__ == '__main__':
    main(sys.argv[1], sys.argv[2])
