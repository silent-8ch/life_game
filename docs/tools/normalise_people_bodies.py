#!/usr/bin/env python3
"""Register one generated body sprite to a shared neck anchor and baseline."""

import os
import struct
import sys
import zlib

sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))
from pnginspect import decode

SIZE = 512
NECK = (256, 104)
FEET_Y = 496


def opaque_bounds(rows, width, height):
    points = [(x, y) for y in range(height) for x in range(width) if rows[y][x][3] > 0]

    if not points:
        raise ValueError('image has no opaque pixels')

    xs = [point[0] for point in points]
    ys = [point[1] for point in points]
    return min(xs), min(ys), max(xs), max(ys)


def neck_anchor(rows, bounds):
    left, top, right, bottom = bounds
    target_y = round(top + (bottom - top + 1) * 0.17)

    for radius in range(8):
        for y in {target_y - radius, target_y + radius}:
            xs = [x for x in range(left, right + 1) if rows[y][x][3] > 0]

            if xs:
                return (min(xs) + max(xs)) / 2, y

    raise ValueError('could not locate neck row')


def normalise(rows, width, height):
    bounds = opaque_bounds(rows, width, height)
    left, top, right, bottom = bounds
    neck_x, neck_y = neck_anchor(rows, bounds)
    scale = (FEET_Y - NECK[1]) / (bottom - neck_y)
    output = [[(0, 0, 0, 0)] * SIZE for _ in range(SIZE)]

    for source_y in range(top, bottom + 1):
        destination_y = round(NECK[1] + (source_y - neck_y) * scale)

        if not 0 <= destination_y < SIZE:
            continue

        for source_x in range(left, right + 1):
            pixel = rows[source_y][source_x]

            if pixel[3] == 0:
                continue

            destination_x = round(NECK[0] + (source_x - neck_x) * scale)

            if 0 <= destination_x < SIZE:
                output[destination_y][destination_x] = pixel

    return output


def write_png(path, rows):
    raw = bytearray()

    for row in rows:
        raw.append(0)
        for pixel in row:
            raw.extend(pixel)

    def chunk(kind, body):
        data = kind.encode('ascii') + body
        return struct.pack('>I', len(body)) + data + struct.pack('>I', zlib.crc32(data))

    with open(path, 'wb') as output:
        output.write(b'\x89PNG\r\n\x1a\n' + chunk('IHDR', struct.pack('>IIBBBBB', SIZE, SIZE, 8, 6, 0, 0, 0)) + chunk('IDAT', zlib.compress(bytes(raw), 9)) + chunk('IEND', b''))


if __name__ == '__main__':
    width, height, _, rows = decode(sys.argv[1])
    write_png(sys.argv[2], normalise(rows, width, height))
