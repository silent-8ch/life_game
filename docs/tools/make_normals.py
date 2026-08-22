#!/usr/bin/env python3
"""Make a tangent-space normal map from a tiling texture.

A one-shot authoring tool. Its output is committed alongside the texture it
came from, so this is run when a texture is added or redrawn, not as a build
step — that was decided (ISSUE-7) because committing means no build step and,
more importantly, means a normal map somebody has fixed by hand survives.

Which is why it will not overwrite an existing map. A generated normal is a
first draft: the estimate below reads height out of brightness, and brightness
is not height — a dark tile on a light floor comes out as a hole. Fixing that
by hand is expected, and silently regenerating over the fix is the one thing
this tool must never do. Pass --force when you mean it.

Nothing but the standard library, like the rest of docs/tools. Reuses
pnginspect's decoder.

    python3 docs/tools/make_normals.py public/sprites/textures/oak-floor.png
    python3 docs/tools/make_normals.py public/sprites/textures/*.png
    python3 docs/tools/make_normals.py --strength 3 --force one.png
"""

import os
import struct
import sys
import zlib

sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))

from pnginspect import decode

# How much the surface is taken to stand out. Higher tilts the normals further
# from straight-on, so the lighting picks up more of the texture's detail.
# Three is a reasonable default for the house's stone and plaster; cloth and
# wallpaper want less.
STRENGTH = 2.0

# What a normal map is called, given the texture it came from. Kept next to it
# rather than in a folder of its own so the pair moves together.
SUFFIX = '-normal'


def height_of(rows, width, height):
    """Brightness, 0..1, as a stand-in for how high the surface is.

    The usual estimate and the reason the output is a draft. Perceptual weights
    rather than a flat average, so a red brick and a blue tile of the same
    lightness do not come out at different heights.
    """
    return [
        [
            (
                0.2126 * rows[y][x][0]
                + 0.7152 * rows[y][x][1]
                + 0.0722 * rows[y][x][2]
            )
            / 255.0
            for x in range(width)
        ]
        for y in range(height)
    ]


def normals_of(field, width, height, strength):
    """Turn a height field into normals, with a Sobel gradient.

    Wraps at the edges rather than clamping, because these textures tile: a
    clamped edge leaves a seam of flat normals down every join, which lights as
    a visible line exactly where the tiling was supposed to disappear.
    """
    rows = []

    for y in range(height):
        row = []
        up, down = (y - 1) % height, (y + 1) % height

        for x in range(width):
            left, right = (x - 1) % width, (x + 1) % width

            dx = (
                (field[up][right] + 2 * field[y][right] + field[down][right])
                - (field[up][left] + 2 * field[y][left] + field[down][left])
            )
            dy = (
                (field[down][left] + 2 * field[down][x] + field[down][right])
                - (field[up][left] + 2 * field[up][x] + field[up][right])
            )

            # The surface normal of a height field, before normalising: the two
            # gradients against a unit of depth.
            nx = -dx * strength
            ny = -dy * strength
            nz = 1.0

            length = (nx * nx + ny * ny + nz * nz) ** 0.5
            nx, ny, nz = nx / length, ny / length, nz / length

            # Packed the way every renderer expects: -1..1 into 0..255, so a
            # flat surface is the familiar (128, 128, 255).
            row.append((
                max(0, min(255, round((nx * 0.5 + 0.5) * 255))),
                max(0, min(255, round((ny * 0.5 + 0.5) * 255))),
                max(0, min(255, round((nz * 0.5 + 0.5) * 255))),
                255,
            ))

        rows.append(row)

    return rows


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


def target_for(source):
    stem, extension = os.path.splitext(source)

    return stem + SUFFIX + extension


def make(source, strength, force):
    if SUFFIX in os.path.basename(source):
        print(f'skip  {source} — already a normal map')
        return True

    target = target_for(source)

    if os.path.exists(target) and not force:
        # The whole point of the tool refusing rather than asking. A hand-fixed
        # map looks exactly like a generated one from here, so there is no way
        # to tell them apart and no safe default but to stop.
        print(f'KEEP  {target} — already there; --force to write over it')
        return False

    width, height, _, rows = decode(source)
    field = height_of(rows, width, height)
    write_png(target, normals_of(field, width, height, strength), width, height)

    print(f'wrote {target}  ({width}x{height})')
    return True


def main(argv):
    strength = STRENGTH
    force = False
    sources = []

    rest = list(argv)

    while rest:
        argument = rest.pop(0)

        if argument == '--force':
            force = True
        elif argument == '--strength':
            if not rest:
                raise SystemExit('--strength wants a number')
            strength = float(rest.pop(0))
        elif argument.startswith('-'):
            raise SystemExit(f'unknown option {argument}')
        else:
            sources.append(argument)

    if not sources:
        raise SystemExit(__doc__)

    kept = 0

    for source in sources:
        if not make(source, strength, force):
            kept += 1

    if kept:
        print(f'\n{kept} left alone. Nothing was written over.')

    return 0


if __name__ == '__main__':
    raise SystemExit(main(sys.argv[1:]))
