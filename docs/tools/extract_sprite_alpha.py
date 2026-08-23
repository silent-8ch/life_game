#!/usr/bin/env python3
"""Replace a generated near-white background with real transparency."""

import os
import struct
import sys
import zlib
from collections import deque

sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))
from pnginspect import decode


def is_background(pixel):
    red, green, blue, alpha = pixel
    return alpha == 0 or (min(red, green, blue) >= 215 and max(red, green, blue) - min(red, green, blue) <= 20)


def extract(rows, width, height):
    background = [[False] * width for _ in range(height)]
    pending = deque((x, y) for x in range(width) for y in (0, height - 1))
    pending.extend((x, y) for y in range(height) for x in (0, width - 1))

    while pending:
        x, y = pending.popleft()

        if background[y][x] or not is_background(rows[y][x]):
            continue

        background[y][x] = True

        for neighbour_x, neighbour_y in (
            (x - 1, y), (x + 1, y), (x, y - 1), (x, y + 1),
            (x - 1, y - 1), (x + 1, y - 1), (x - 1, y + 1), (x + 1, y + 1),
        ):
            if 0 <= neighbour_x < width and 0 <= neighbour_y < height:
                pending.append((neighbour_x, neighbour_y))

    return [[(*rows[y][x][:3], 0 if background[y][x] else rows[y][x][3]) for x in range(width)] for y in range(height)]


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
        output.write(b'\x89PNG\r\n\x1a\n' + chunk('IHDR', struct.pack('>IIBBBBB', width, height, 8, 6, 0, 0, 0)) + chunk('IDAT', zlib.compress(bytes(raw), 9)) + chunk('IEND', b''))


if __name__ == '__main__':
    width, height, _, rows = decode(sys.argv[1])
    write_png(sys.argv[2], extract(rows, width, height), width, height)
