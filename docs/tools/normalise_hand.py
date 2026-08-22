#!/usr/bin/env python3
"""Normalise a generated hand card onto the engine's canvas. Deterministic.

Standard library only — no Pillow, no ImageMagick.

The engine draws every pose on one card of the same size, so a hand that is a
different size or sits in a different place from pose to pose visibly jumps when
the pose changes. This puts every card on the same footing by pinning the one
feature every pose shares: the wrist.

  1. Alpha below FLOOR is set to nothing, killing the near-invisible halo that
     a soft eraser or a resize leaves behind.
  2. The wrist is measured as the mean silhouette width over the bottom tenth of
     the drawing.
  3. The image is scaled so that wrist width is WRIST_W, by box average over
     premultiplied alpha so edges do not darken.
  4. It is placed so the wrist centre is at the canvas centre and the lowest
     drawn row sits at WRIST_Y.

Nothing here is a judgement call, which is the point: run it, do not eyeball it.

    python3 normalise_hand.py raw/paul-back.png public/sprites/hands/paul-back.png
"""

import struct
import sys
import zlib

from pnginspect import decode

CANVAS = 887
FLOOR = 24        # alpha at or below this is nothing at all
WRIST_W = 140.0   # target wrist width in pixels
WRIST_Y = 850     # target y of the lowest drawn row


def spans(rows, width, height):
    out = []
    for y in range(height):
        xs = [x for x in range(width) if rows[y][x][3] > FLOOR]
        out.append((min(xs), max(xs)) if xs else None)
    return out


def measure(rows, width, height):
    s = spans(rows, width, height)
    ys = [y for y, v in enumerate(s) if v]
    if not ys:
        raise SystemExit('nothing drawn on this canvas')
    y0, y1 = ys[0], ys[-1]
    band = [v for v in s[max(y0, y1 - int((y1 - y0) * 0.10)):y1 + 1] if v]
    wrist = sum(b[1] - b[0] + 1 for b in band) / len(band)
    cx = sum((b[0] + b[1]) / 2 for b in band) / len(band)
    x0 = min(v[0] for v in s if v)
    x1 = max(v[1] for v in s if v)
    return wrist, cx, x0, x1, y0, y1


def resample(rows, width, height, scale):
    """Box average over premultiplied alpha."""
    nw, nh = max(1, round(width * scale)), max(1, round(height * scale))
    inv = 1.0 / scale
    out = []
    for Y in range(nh):
        sy0, sy1 = int(Y * inv), max(int(Y * inv) + 1, int((Y + 1) * inv))
        row = []
        for X in range(nw):
            sx0, sx1 = int(X * inv), max(int(X * inv) + 1, int((X + 1) * inv))
            r = g = b = a = n = 0
            for y in range(sy0, min(sy1, height)):
                for x in range(sx0, min(sx1, width)):
                    pr, pg, pb, pa = rows[y][x]
                    f = pa / 255.0
                    r += pr * f; g += pg * f; b += pb * f; a += pa; n += 1
            if n == 0:
                row.append((0, 0, 0, 0))
                continue
            a = round(a / n)
            if a <= FLOOR:
                # The box average makes new part-transparent pixels of its own.
                # Floor them here too, or the halo the first pass removed grows
                # straight back at the new scale.
                row.append((0, 0, 0, 0))
            else:
                f = n * (a / 255.0)
                row.append((min(255, round(r / f)), min(255, round(g / f)),
                            min(255, round(b / f)), a))
        out.append(row)
    return out, nw, nh


def write_png(path, rows, width, height):
    raw = bytearray()
    for y in range(height):
        raw.append(0)
        for x in range(width):
            raw.extend(rows[y][x])

    def chunk(kind, body):
        c = kind.encode('ascii') + body
        return struct.pack('>I', len(body)) + c + struct.pack('>I', zlib.crc32(c))

    open(path, 'wb').write(
        b'\x89PNG\r\n\x1a\n'
        + chunk('IHDR', struct.pack('>IIBBBBB', width, height, 8, 6, 0, 0, 0))
        + chunk('IDAT', zlib.compress(bytes(raw), 9))
        + chunk('IEND', b''))


def main(src, dst):
    width, height, _, rows = decode(src)

    for y in range(height):
        for x in range(width):
            if rows[y][x][3] <= FLOOR:
                rows[y][x] = (0, 0, 0, 0)

    wrist, cx, x0, x1, y0, y1 = measure(rows, width, height)
    scale = WRIST_W / wrist
    small, nw, nh = resample(rows, width, height, scale)

    w2, cx2, sx0, sx1, sy0, sy1 = measure(small, nw, nh)
    dx = round(CANVAS / 2 - cx2)
    dy = WRIST_Y - sy1

    canvas = [[(0, 0, 0, 0)] * CANVAS for _ in range(CANVAS)]
    clipped = 0
    for y in range(nh):
        ty = y + dy
        if not (0 <= ty < CANVAS):
            clipped += sum(1 for x in range(nw) if small[y][x][3] > 0)
            continue
        for x in range(nw):
            if small[y][x][3] == 0:
                continue
            tx = x + dx
            if 0 <= tx < CANVAS:
                canvas[ty][tx] = small[y][x]
            else:
                clipped += 1

    write_png(dst, canvas, CANVAS, CANVAS)

    print(f'{src} -> {dst}')
    print(f'  wrist {wrist:.0f}px -> {WRIST_W:.0f}px   scale {scale:.3f}')
    print(f'  moved dx {dx:+d} dy {dy:+d}')
    if clipped:
        print(f'  WARNING {clipped} drawn pixels fell off the canvas. This hand is '
              f'too tall for its own wrist to reach WRIST_W without overflowing. '
              f'Restore the original and regenerate it drawn LARGER in frame, so '
              f'less upscaling is needed. Do not rerun this on the output.')


if __name__ == '__main__':
    main(sys.argv[1], sys.argv[2])
