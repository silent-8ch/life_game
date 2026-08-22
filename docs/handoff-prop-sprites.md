# Handoff: prop sprites for a modern home

For whoever is generating the images. **Read `docs/handoff-hand-art.md` first** — its two
rules apply here unchanged and are not repeated in full:

1. **Never feed your own output back in.** Every image is a fresh generation from the prompt
   and real reference. No img2img, no refine passes, no using one output as reference for the
   next, and above all **no generating several props in one image and cutting them up** — that
   is exactly how the hand set was ruined.
2. **Measure, don't look.** The tools in `docs/tools/` are the judge.

About **40 sprites**, listed at the end. Modern, casual, domestic. **No war, weapons, fantasy
or adventure content** — this is a house people live in.

---

## Style — match the world, not the hands

The project contains two different art styles and it matters which one you follow.

- The **people** (`public/sprites/realistic/*.png`) are **photoreal** — actual photographic
  figures on transparent backgrounds.
- The **surface textures** (`public/sprites/textures/*.png`, 65 of them) are **soft and
  painterly** — muted, warm, gently noisy, realistic materials. 256×256, around 7,600 distinct
  colours each.
- The **first-person hands** are chunky pixel art with heavy black outlines. **These are the
  outlier.** They are a first-person view model seen at arm's length and they do not set the
  style for anything else.

**Props follow the textures and the people: soft, realistic, muted, no black outlines.** A
prop stands in the same room as a photoreal person and against a painterly wall. Chunky pixel
art would clash with both.

Look at `public/sprites/textures/kitchen-tile.png`, `cedar-siding.png` and `oak-floor.png`
before starting. Warm neutrals, low saturation, soft edges, nothing glossy or neon.

---

## The spec

| Property | Value |
| --- | --- |
| Format | **PNG, colour type 6 (RGBA)**, 8-bit |
| Background | **fully transparent** for cutout props; opaque is fine for flat panel props |
| Resolution | **128 pixels per metre**, so size follows the object — see the table |
| Alpha | **hard edges only** — see the cutout rule below |
| Lighting in the art | flat and even, no cast shadow, no ground shadow |
| View | straight-on orthographic-ish elevation, not a perspective three-quarter view |

### Resolution follows real size

The engine tiles surface textures at `TEXTURE_METRES = 2`, so 256 px covers 2 m — **128 px per
metre**. Props use the same density so everything in the world has consistent apparent
resolution. Multiply the object's real size in metres by 128 and round to a multiple of 4.

A prop's image is stretched to fit its box exactly once, so **the aspect ratio must match the
real object**. A door authored square renders as a squashed door.

### Hard alpha only

Cutout props are drawn with `alphaTest`, which keeps a pixel only if its alpha is above the
halfway mark. So:

- No soft or feathered edges — they vanish and leave a ragged fringe.
- No drop shadows, glows or fades into transparency — they disappear entirely.
- No semi-transparent glass. A window pane reads as glass through its *reflections and
  tint painted opaque*, not through actual transparency.

`docs/tools/pnginspect.py` reports the count of part-transparent pixels; it should be small
and confined to the outline.

---

## Cross-plane props — read this before drawing any plant

Some props are built from **two flat images intersecting at right angles down the vertical
axis**, like a cardboard cut-out X seen from above:

```
   top view        \   /
                    \ /
                     X
                    / \
                   /   \
```

Three planes at 60° is also supported and is better for round bushes.

This is the standard trick for foliage. It costs nothing at runtime and reads as volume from
every angle — but only if the art is drawn for it. Four rules:

1. **The content must be centred on the vertical axis**, because that is where the planes
   intersect. An off-centre plant makes the two halves visibly miss each other.
2. **The silhouette must not be a rectangle.** If the leaves reach the edges of the image, the
   X reads as two crossed boards. Let the silhouette breathe — irregular outline, gaps
   between leaves, transparent corners.
3. **Foliage should cross the axis**, with leaves reaching out past the centre line, so the
   two planes interleave rather than sitting as two separate flat pictures.
4. **It is seen from behind and mirrored.** Draw something that reads either way round — no
   text, no strong front/back asymmetry.

A plant may also be authored as **two stacked things**: a cross-plane stalk plus a billboard
head that turns to face the viewer. Where that is wanted, the two images are listed separately
in the table below.

---

## States and animation — one file per state

Discrete states are separate files with a suffix. Never a sheet, never cells.

```
light-switch-off.png     light-switch-on.png
window-picture.png       window-picture-broken.png
tv-off.png               tv-screen-1.png … tv-screen-4.png
```

The **broken** state is a genuinely broken version of the *same* object, drawn at the same
size and framing, so it can be swapped in place — a spiderweb crack pattern with a few missing
shards and darker gaps behind them. Not a pile of debris.

The **TV frames** must loop cleanly and read as vague moving light — a blurred bright scene,
different in each frame — rather than a legible picture. Four frames at 8 fps. Keep the frame
and bezel identical across all four; only the screen content changes, or the TV appears to
wobble.

---

## The set

Sizes in metres are the object's real size, which sets both the image aspect ratio and the
box the editor will give it. Pixel sizes are metres × 128, rounded to a multiple of 4.

### Doors — 5

| File | Real size (w × h) | Pixels | Notes |
| --- | --- | --- | --- |
| `door-interior.png` | 0.82 × 2.04 | 104 × 260 | white painted, panelled |
| `door-front.png` | 0.92 × 2.04 | 116 × 260 | solid exterior, letterbox, handle |
| `door-sliding-glass.png` | 1.80 × 2.10 | 232 × 268 | aluminium frame, painted-opaque glass |
| `door-bifold.png` | 0.76 × 2.04 | 96 × 260 | louvred closet door |
| `door-frame.png` | 0.96 × 2.12 | 124 × 272 | architrave only, hole transparent |

### Windows — 4

| File | Real size | Pixels | Notes |
| --- | --- | --- | --- |
| `window-single-hung.png` | 0.90 × 1.20 | 116 × 156 | white frame |
| `window-picture.png` | 1.60 × 1.10 | 204 × 140 | large single pane |
| `window-picture-broken.png` | 1.60 × 1.10 | 204 × 140 | **same framing**, cracked, shards gone |
| `window-blind.png` | 0.90 × 1.20 | 116 × 156 | slatted blind, drawn down |

### Switches and fixtures — 5

| File | Real size | Pixels |
| --- | --- | --- |
| `light-switch-off.png` | 0.08 × 0.12 | 12 × 16 |
| `light-switch-on.png` | 0.08 × 0.12 | 12 × 16 |
| `wall-outlet.png` | 0.08 × 0.12 | 12 × 16 |
| `thermostat.png` | 0.10 × 0.10 | 16 × 16 |
| `wall-clock.png` | 0.30 × 0.30 | 40 × 40 |

Small props still get real detail — they are seen from half a metre away in first person.

### Screens and lights — 9

| File | Real size | Pixels | Notes |
| --- | --- | --- | --- |
| `tv-off.png` | 1.20 × 0.70 | 154 × 90 | dark screen, faint room reflection |
| `tv-screen-1.png` … `-4.png` | 1.20 × 0.70 | 154 × 90 | animated, bezel identical |
| `ceiling-light.png` | 0.34 × 0.12 | 44 × 16 | flush mount |
| `pendant-light.png` | 0.26 × 0.60 | 34 × 76 | shade and flex |
| `table-lamp.png` | 0.30 × 0.45 | 40 × 58 | |
| `floor-lamp.png` | 0.34 × 1.60 | 44 × 204 | |

### Plants — 8, mostly cross-plane

| File | Real size | Pixels | Mode |
| --- | --- | --- | --- |
| `plant-monstera.png` | 0.80 × 1.20 | 104 × 154 | cross, 2 planes |
| `plant-snake.png` | 0.40 × 0.90 | 52 × 116 | cross, 2 planes |
| `plant-fern.png` | 0.60 × 0.55 | 76 × 72 | cross, 2 planes |
| `plant-succulent.png` | 0.18 × 0.16 | 24 × 20 | billboard |
| `plant-pot.png` | 0.34 × 0.30 | 44 × 40 | box — the pot for a stacked plant |
| `shrub-round.png` | 1.00 × 0.90 | 128 × 116 | cross, **3 planes** |
| `grass-tuft.png` | 0.40 × 0.30 | 52 × 40 | cross, 2 planes |
| `small-tree.png` | 1.60 × 2.60 | 204 × 332 | cross, **3 planes** |

`plant-pot.png` is the base of a stacked plant — a box-rendered pot with a cross-plane plant
sitting on it, which is the hybrid arrangement. Draw the pot as a plain elevation.

### Furniture and fittings — 13

| File | Real size | Pixels |
| --- | --- | --- |
| `sofa.png` | 2.00 × 0.85 | 256 × 108 |
| `armchair.png` | 0.90 × 0.90 | 116 × 116 |
| `bed-double.png` | 1.50 × 0.60 | 192 × 76 |
| `bookshelf.png` | 0.80 × 1.80 | 104 × 232 |
| `desk.png` | 1.20 × 0.75 | 154 × 96 |
| `dining-chair.png` | 0.45 × 0.90 | 58 × 116 |
| `dining-table.png` | 1.60 × 0.75 | 204 × 96 |
| `kitchen-counter.png` | 2.00 × 0.90 | 256 × 116 |
| `fridge.png` | 0.70 × 1.80 | 90 × 232 |
| `wardrobe.png` | 1.00 × 2.00 | 128 × 256 |
| `toilet.png` | 0.40 × 0.78 | 52 × 100 |
| `bathroom-sink.png` | 0.55 × 0.85 | 70 × 108 |
| `bathtub.png` | 1.70 × 0.60 | 218 × 76 |

Front elevations, straight on. These are box props, so the image covers the front face — do
not draw them in perspective.

---

## Prompt shape

One generation per file. Keep the style clause identical across every prop so the set holds
together.

> A single {OBJECT}, drawn as a straight-on front elevation, modern and casual, the kind found
> in an ordinary present-day home. Soft realistic painted style with muted warm colours and no
> black outline. Flat even lighting, no cast shadow. Fully transparent background. The object
> fills the frame with no margin, nothing cropped, drawn at an aspect ratio of {W}:{H}.

For cross-plane plants, replace the last sentence with:

> Centred on its vertical axis, with an irregular silhouette and transparent gaps between the
> leaves — it must not fill the frame as a rectangle. Leaves reach out past the centre line in
> both directions. It will be mirrored, so it must read the same either way round.

---

## Done means

Put everything in `public/sprites/props/` and check each file:

```
python3 docs/tools/pnginspect.py public/sprites/props/door-interior.png
```

Every file must report colour type 6, the right dimensions from the table, and a small count
of part-transparent pixels. There is no batch gate for props yet — `verify_hands.py` is
specific to hand cards. Writing the equivalent for props is a small task once the set exists
and the real sizes have settled.

Hand back the files, and a note of anything you had to change from the table.
