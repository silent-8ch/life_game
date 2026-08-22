#!/usr/bin/env python3
"""Measure a PNG with nothing but the standard library.

No Pillow, no ImageMagick. Decodes IHDR + IDAT, undoes the scanline filters,
and reports the facts a sprite sheet has to satisfy: size, whether it really
carries alpha, where the drawing sits, and how big the art's own pixel block is.
"""

import struct
import sys
import zlib
from collections import Counter


def chunks(data):
    i = 8
    while i < len(data):
        (length,) = struct.unpack('>I', data[i:i + 4])
        kind = data[i + 4:i + 8].decode('ascii')
        yield kind, data[i + 8:i + 8 + length]
        i += 8 + length + 4


def decode(path):
    raw = open(path, 'rb').read()
    idat = b''
    width = height = depth = colour = None
    palette = None
    transparency = None

    for kind, body in chunks(raw):
        if kind == 'IHDR':
            width, height, depth, colour, _, _, interlace = struct.unpack('>IIBBBBB', body)
            if interlace:
                raise SystemExit(f'{path}: interlaced, not handled')
            if depth != 8:
                raise SystemExit(f'{path}: bit depth {depth}, not handled')
        elif kind == 'PLTE':
            palette = body
        elif kind == 'tRNS':
            transparency = body
        elif kind == 'IDAT':
            idat += body

    channels = {0: 1, 2: 3, 3: 1, 4: 2, 6: 4}[colour]
    stride = width * channels
    flat = zlib.decompress(idat)

    out = bytearray(stride * height)
    prev = bytearray(stride)

    for y in range(height):
        start = y * (stride + 1)
        filt = flat[start]
        line = bytearray(flat[start + 1:start + 1 + stride])

        for x in range(stride):
            a = line[x - channels] if x >= channels else 0
            b = prev[x]
            c = prev[x - channels] if x >= channels else 0

            if filt == 1:
                line[x] = (line[x] + a) & 0xFF
            elif filt == 2:
                line[x] = (line[x] + b) & 0xFF
            elif filt == 3:
                line[x] = (line[x] + (a + b) // 2) & 0xFF
            elif filt == 4:
                p = a + b - c
                pa, pb, pc = abs(p - a), abs(p - b), abs(p - c)
                pr = a if (pa <= pb and pa <= pc) else (b if pb <= pc else c)
                line[x] = (line[x] + pr) & 0xFF

        out[y * stride:(y + 1) * stride] = line
        prev = line

    # Normalise every colour type to RGBA rows.
    rows = []
    for y in range(height):
        line = out[y * stride:(y + 1) * stride]
        row = []
        for x in range(width):
            px = line[x * channels:(x + 1) * channels]
            if colour == 6:
                row.append(tuple(px))
            elif colour == 2:
                row.append((px[0], px[1], px[2], 255))
            elif colour == 4:
                row.append((px[0], px[0], px[0], px[1]))
            elif colour == 0:
                row.append((px[0], px[0], px[0], 255))
            elif colour == 3:
                i = px[0]
                r, g, b = palette[i * 3:i * 3 + 3]
                a = transparency[i] if transparency and i < len(transparency) else 255
                row.append((r, g, b, a))
        rows.append(row)

    return width, height, colour, rows


def block_size(rows, width, height):
    """Smallest horizontal run length in the drawing — the art's pixel block."""
    runs = []
    for y in range(0, height, 7):
        run = 1
        for x in range(1, width):
            if rows[y][x] == rows[y][x - 1]:
                run += 1
            else:
                if rows[y][x - 1][3] > 0:
                    runs.append(run)
                run = 1
    return Counter(runs).most_common(6)


def report(path):
    width, height, colour, rows = decode(path)
    names = {0: 'grey', 2: 'RGB', 3: 'palette', 4: 'grey+A', 6: 'RGBA'}

    opaque = [(x, y) for y in range(height) for x in range(width) if rows[y][x][3] > 0]
    has_alpha = any(rows[y][x][3] < 255 for y in range(height) for x in range(width))

    corners = [rows[0][0], rows[0][width - 1], rows[height - 1][0], rows[height - 1][width - 1]]

    print(f'\n{path}')
    print(f'  size          {width}x{height}   colour type {colour} ({names[colour]})')
    print(f'  has alpha     {has_alpha}')
    print(f'  corner pixels {corners[0]} {corners[1]} {corners[2]} {corners[3]}')

    if not has_alpha:
        bg = Counter(rows[y][x] for y in range(height) for x in range(width)).most_common(1)[0]
        print(f'  commonest     {bg[0]}  ({bg[1] * 100 // (width * height)}% of the canvas)')
        # Treat the corner colour as the background and measure the drawing.
        key = corners[0]
        opaque = [(x, y) for y in range(height) for x in range(width) if rows[y][x] != key]

    if opaque:
        xs = [p[0] for p in opaque]
        ys = [p[1] for p in opaque]
        x0, x1, y0, y1 = min(xs), max(xs), min(ys), max(ys)
        print(f'  drawing bbox  x {x0}..{x1}  y {y0}..{y1}   ({x1 - x0 + 1}x{y1 - y0 + 1})')
        print(f'  margins       L{x0} R{width - 1 - x1} T{y0} B{height - 1 - y1}')
        cx = (x0 + x1 + 1) / 2
        print(f'  centre        x {cx:.1f} (canvas {width / 2:.1f}, off by {cx - width / 2:+.1f})')
        touches = []
        if x0 == 0: touches.append('left')
        if x1 == width - 1: touches.append('right')
        if y0 == 0: touches.append('top')
        if y1 == height - 1: touches.append('bottom')
        print(f'  touches edge  {", ".join(touches) if touches else "no"}')
        print(f'  coverage      {len(opaque) * 100 // (width * height)}% of canvas')

    print(f'  colours       {len(set(rows[y][x] for y in range(height) for x in range(width)))} distinct')
    print(f'  run lengths   {block_size(rows, width, height)}')


if __name__ == '__main__':
    for arg in sys.argv[1:]:
        report(arg)
