#!/usr/bin/env python3
"""Align a transparent 4x4 sprite atlas to the engine's 256px cells."""

import os
import struct
import sys
import zlib

sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))
from pnginspect import decode

CELL = 256
FRAME_MARGIN = 5
TOP = 10
FEET = 245


def bounds(rows, left, top):
    points = [
        (x, y)
        for y in range(top, top + CELL)
        for x in range(left, left + CELL)
        if rows[y][x][3] > 0
    ]

    if not points:
        raise ValueError(f'empty cell at {left // CELL}, {top // CELL}')

    xs = [point[0] for point in points]
    ys = [point[1] for point in points]
    return min(xs), min(ys), max(xs), max(ys)


def normalise(rows):
    frames = [
        bounds(rows, column * CELL, row * CELL)
        for row in range(4)
        for column in range(4)
    ]
    maximum_width = max(right - left + 1 for left, _, right, _ in frames)
    maximum_height = max(bottom - top + 1 for _, top, _, bottom in frames)
    scale = min(
        (CELL - FRAME_MARGIN * 2) / maximum_width,
        (FEET - TOP + 1) / maximum_height,
    )
    output = [[(0, 0, 0, 0)] * (CELL * 4) for _ in range(CELL * 4)]

    for index, (left, top, right, bottom) in enumerate(frames):
        row, column = divmod(index, 4)
        width = right - left + 1
        height = bottom - top + 1
        scaled_width = max(1, round(width * scale))
        scaled_height = max(1, round(height * scale))
        destination_left = column * CELL + (CELL - scaled_width) // 2
        destination_top = row * CELL + FEET - scaled_height + 1

        for y in range(scaled_height):
            source_y = top + min(height - 1, int(y / scale))

            for x in range(scaled_width):
                source_x = left + min(width - 1, int(x / scale))
                output[destination_top + y][destination_left + x] = rows[source_y][source_x]

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
        output.write(
            b'\x89PNG\r\n\x1a\n'
            + chunk('IHDR', struct.pack('>IIBBBBB', CELL * 4, CELL * 4, 8, 6, 0, 0, 0))
            + chunk('IDAT', zlib.compress(bytes(raw), 9))
            + chunk('IEND', b'')
        )


def main(source, destination):
    width, height, _, rows = decode(source)

    if (width, height) != (CELL * 4, CELL * 4):
        raise ValueError(f'expected 1024x1024, got {width}x{height}')

    write_png(destination, normalise(rows))


if __name__ == '__main__':
    main(sys.argv[1], sys.argv[2])
