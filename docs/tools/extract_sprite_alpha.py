#!/usr/bin/env python3
"""Replace a generated near-white checkerboard with real transparency."""

import os
import struct
import sys
import zlib
from collections import deque

sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))
from pnginspect import decode


def is_background(pixel):
    red, green, blue, _ = pixel
    return min(red, green, blue) >= 220 and max(red, green, blue) - min(red, green, blue) <= 18


def extract(rows, width, height):
    background = [[False] * width for _ in range(height)]
    pending = deque()

    for x in range(width):
        pending.extend(((x, 0), (x, height - 1)))

    for y in range(height):
        pending.extend(((0, y), (width - 1, y)))

    while pending:
        x, y = pending.popleft()

        if background[y][x] or not is_background(rows[y][x]):
            continue

        background[y][x] = True

        if x > 0:
            pending.append((x - 1, y))
        if x + 1 < width:
            pending.append((x + 1, y))
        if y > 0:
            pending.append((x, y - 1))
        if y + 1 < height:
            pending.append((x, y + 1))

    return [
        [(*rows[y][x][:3], 0 if background[y][x] else 255) for x in range(width)]
        for y in range(height)
    ]


def write_png(path, rows, width, height):
    raw = bytearray()

    for row in rows:
        raw.append(0)

        for pixel in row:
            raw.extend(pixel)

    def chunk(kind, body):
        data = kind.encode('ascii') + body
        return struct.pack('>I', len(body)) + data + struct.pack('>I', zlib.crc32(data))

    with open(path, 'wb') as output:
        output.write(
            b'\x89PNG\r\n\x1a\n'
            + chunk('IHDR', struct.pack('>IIBBBBB', width, height, 8, 6, 0, 0, 0))
            + chunk('IDAT', zlib.compress(bytes(raw), 9))
            + chunk('IEND', b'')
        )


def main(source, destination):
    width, height, _, rows = decode(source)
    write_png(destination, extract(rows, width, height), width, height)


if __name__ == '__main__':
    main(sys.argv[1], sys.argv[2])
