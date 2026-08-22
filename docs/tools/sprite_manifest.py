#!/usr/bin/env python3
"""List every sprite on disk, so the checker page never needs a hardcoded set.

    python3 docs/tools/sprite_manifest.py

Writes public/sprites/manifest.json. Run it after new art lands and the page
picks the art up — that is the whole point of it. Standard library only, and it
reads dimensions straight out of the IHDR chunk rather than decoding the image,
because it is walking a few hundred files and only needs their size.

The manifest deliberately carries no opinions. It says what exists and how big
it is; every judgement about what a sprite *is* belongs to the person looking
at it on the page.
"""

import json
import os
import struct
import sys
from datetime import datetime, timezone

HERE = os.path.dirname(os.path.abspath(__file__))
ROOT = os.path.abspath(os.path.join(HERE, '..', '..'))
SPRITES = os.path.join(ROOT, 'public', 'sprites')
OUT = os.path.join(SPRITES, 'manifest.json')

#: Folders holding one file per sprite. Anything else found is still listed —
#: an unknown folder is more likely to be new art than a mistake.
KNOWN = ('hands', 'props', 'textures', 'realistic', 'illustrated', 'bg', 'people')


def measure(path):
    """Width and height from the IHDR chunk, without decoding the image."""
    with open(path, 'rb') as handle:
        head = handle.read(24)

    if len(head) < 24 or head[:8] != b'\x89PNG\r\n\x1a\n':
        return None, None

    width, height = struct.unpack('>II', head[16:24])

    return width, height


def collect(here, folder, sprites):
    """Every PNG in one folder, plus one level of versioned subfolders.

    A versioned set lives at people/v2/..., so a flat listing would miss it
    entirely. The subfolder is recorded as `sub` rather than as `version`,
    because not every subfolder is one — hands/overlays, bg/retired and
    textures/sources are all archives or sources. Whoever reads the manifest
    decides what a v-number means; this only says where the file was.
    """
    for name in sorted(os.listdir(here)):
        path = os.path.join(here, name)

        if os.path.isdir(path):
            # One level only. A version holds files, not more folders, and
            # walking without a bound turns a stray directory into a crawl.
            if '/' in folder:
                continue

            collect(path, f'{folder}/{name}', sprites)
            continue

        if not name.endswith('.png'):
            continue

        width, height = measure(path)
        family, _, sub = folder.partition('/')

        sprites.append({
            'folder': family,
            'sub': sub or None,
            'file': name,
            'name': name[:-4],
            'url': f'/sprites/{folder}/{name}',
            'w': width,
            'h': height,
        })


def walk():
    sprites = []

    for folder in sorted(os.listdir(SPRITES)):
        here = os.path.join(SPRITES, folder)

        if os.path.isdir(here):
            collect(here, folder, sprites)

    return sprites


def main():
    if not os.path.isdir(SPRITES):
        print(f'no sprite folder at {SPRITES}', file=sys.stderr)
        return 2

    sprites = walk()

    with open(OUT, 'w') as handle:
        json.dump({
            'generated': datetime.now(timezone.utc).isoformat(timespec='seconds'),
            'sprites': sprites,
        }, handle, indent=1)
        handle.write('\n')

    counts = {}
    for sprite in sprites:
        where = sprite['folder']
        if sprite['sub']:
            where = f'{where}/{sprite["sub"]}'
        counts[where] = counts.get(where, 0) + 1

    print(f'{len(sprites)} sprites -> public/sprites/manifest.json')
    for folder in sorted(counts):
        flag = '' if folder.split('/')[0] in KNOWN else '   (unknown folder, listed anyway)'
        print(f'  {counts[folder]:4d}  {folder}{flag}')

    return 0


if __name__ == '__main__':
    sys.exit(main())
