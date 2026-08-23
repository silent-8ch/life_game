#!/usr/bin/env python3
"""Remove a bright green background and suppress green edge spill."""

import os
import struct
import sys
import zlib

sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))
from pnginspect import decode

OPAQUE_AT = 24
TRANSPARENT_AT = 96


def key(pixel):
    red, green, blue, source_alpha = pixel
    dominance = green - max(red, blue)

    if dominance <= OPAQUE_AT:
        return red, green, blue, source_alpha

    if dominance >= TRANSPARENT_AT:
        return red, green, blue, 0

    alpha = round(source_alpha * (TRANSPARENT_AT - dominance) / (TRANSPARENT_AT - OPAQUE_AT))
    cleaned_green = min(green, max(red, blue))
    return red, cleaned_green, blue, alpha


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


if __name__ == '__main__':
    width, height, _, rows = decode(sys.argv[1])
    write_png(sys.argv[2], [[key(pixel) for pixel in row] for row in rows], width, height)
