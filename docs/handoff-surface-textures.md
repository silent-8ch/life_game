# Handoff: surface textures, at three times the density

For whoever is generating the images. **Read `docs/handoff-hand-art.md` first** — its two
governing rules apply here unchanged and are not repeated in full:

1. **Never feed your own output back in.** Every image is a fresh generation from the prompt
   and real reference. No img2img, no refine passes, no upscaling the existing 256s, and above
   all **no generating several textures in one image and cutting them up**.
2. **Measure, don't look.** The tools in `docs/tools/` are the judge.

**64 textures, all of which already exist** at 256×256 in `public/sprites/textures`. This is a
replacement at higher density, not a new set. **Every filename stays exactly as it is.**

---

## Why this is being done, because it changes what "good" means

Not for sharpness. Two decisions upstream force it.

**`PIXEL_SCALE` is being removed.** The renderer drew into a buffer a third of the canvas and
upscaled it. That was matched, near enough, to these textures: 256 pixels over 2 metres is 128
texels per metre, and a wall two metres away resolved to about 117 screen pixels per metre —
just under 1:1, which is why the game looked crisp rather than cheap. At full resolution the
same wall gets **about 352 screen pixels per metre**, so every surface magnifies 2.7× until
the art catches up.

**The lighting plan derives normal maps from these files.** `docs/tools/make_normals.py` reads
height out of brightness, so **the diffuse texture is the ceiling on how much surface relief
the baked lighting can ever show**. Tripling the density triples the relief, from the same
generator, for free. That is the real reason.

---

## The spec

| Property | Value | Why |
| --- | --- | --- |
| Size | **768 × 768** | 384 texels/metre against ~352 screen px/metre at 2 m. Matches a wall at arm's length; undersamples if you press your face to it. |
| Format | **PNG, 8-bit** | Colour type 6 (RGBA) matches the current set; type 2 (RGB) is also fine — these are opaque. |
| Coverage | **exactly 2 metres square** | `TEXTURE_METRES = 2`. The image *is* two metres of wall or floor. Draw it at that scale. |
| Tiling | **seamless, all four edges** | Non-negotiable — see below. |
| Lighting in the art | **flat and even. No shadow, no highlight, no vignette.** | See below. |
| Filtering it will get | `NearestFilter` magnified, mipmapped minified, anisotropy 16 | No soft interpolation on close-up. The art must be clean at native size, not rely on blur. |
| Colour space | sRGB | The loader sets `SRGBColorSpace`. |

---

## Rule 1 — seamless, and this is the one that fails

These are drawn with `RepeatWrapping` and tile every 2 metres. A texture whose left edge does
not meet its right edge produces **a visible grid across every floor in the game, one line
every two metres.** That exact symptom has already cost this project an afternoon from a
different cause, and it is the first thing anyone will notice.

Both axes. Top to bottom as well as left to right. Test it by tiling the image 2×2 and looking
for the seam — if you can see where the copies meet, it is not done.

## Rule 2 — no light in the texture

The game is getting **baked global illumination**. Light, shade, bounce and ambient occlusion
are computed separately and multiplied over these images at runtime.

So a texture with a shadow painted into it gets that shadow **twice**, and in the wrong place
the moment the room is lit from another direction. A brick wall with a soft dark corner will
have a dark corner in the middle of a brightly lit wall. **Draw every surface as if it were
lit perfectly evenly from directly in front.** Albedo only: the colour the material *is*, not
the colour it appears under any particular light.

This is the rule that most distinguishes these from ordinary game textures, and the one a
generator will silently break, because "a photograph of a brick wall" has light in it.

## Rule 3 — brightness becomes relief, so mind what is dark

`make_normals.py` turns brightness into height: dark is low, light is high. That is right for
the mortar between bricks, the grooves between planks, the gaps between tiles.

It is wrong for anything that is dark **because of its colour rather than its depth**. A dark
tile set among light ones comes out as a hole in the floor. A black rug reads as a pit.

Where a surface has strong colour variation at the same physical height — a chequerboard, a
patterned rug, a mosaic — **keep the brightness close between the colours** and let hue carry
the difference. Where the variation *is* depth, make the brightness difference clear.

---

## What must not change

- **Filenames.** Every level in the database references these by name. A renamed texture is a
  surface that stops drawing. There are 64; there should be 64 afterwards, named identically.
- **What each one depicts.** `oak-floor` must still be an oak floor. This is a resolution pass,
  not a redesign — levels have been authored against how these look.
- **The 2-metre scale.** A texture drawn at 768 px but depicting *one* metre of brick will make
  every brick in the game half size.

---

## The set

**Floors and interior surfaces (14)** — `blue-carpet`, `checker-floor`, `cream-carpet`,
`dark-wood-floor`, `kitchen-tile`, `marble-floor`, `mosaic-tile`, `oak-floor`,
`pale-wood-floor`, `parquet-floor`, `rose-carpet`, `speckled-linoleum`, `subway-tile-wall`,
`workshop-floor`

**Rugs and soft furnishing (4)** — `blue-rug`, `floral-rug`, `picnic-blanket`, `red-rug`

**Walls (10)** — `castle-stone-wall`, `concrete-wall`, `cream-plaster-wall`,
`fieldstone-wall`, `floral-wallpaper`, `painted-brick-wall`, `stucco-wall`, `wood-panel-wall`,
`cedar-siding`, `red-siding`, `white-siding`

**Roofs (5)** — `asphalt-shingles`, `cedar-shingles`, `metal-roof`, `slate-roof`,
`terracotta-roof`, `thatch-roof`

**Ground and outdoors (16)** — `clover-ground`, `dark-soil`, `dry-grass`, `fallen-leaves`,
`flower-patch`, `garden-bed`, `garden-soil`, `gravel-ground`, `ice-ground`, `moss-ground`,
`mud-ground`, `pebble-bed`, `pine-needles`, `snow-ground`, `spring-grass`

**Paths and decking (7)** — `asphalt-path`, `dock-planks`, `packed-path`, `red-brick-path`,
`slate-path`, `weathered-deck`

**Water (8)** — `deep-water`, `fountain-water`, `ocean-water`, `pond-water`, `pool-water`,
`river-water`, `shallow-water`, `swamp-water`

Water is animated separately by the engine from a strip; these are the still surfaces. Draw
them as water reads from directly above, flat and even, with no specular highlight — the
highlight is light, and light is baked.

---

## Done means

There is **no batch gate for surface textures yet**, and one should be written before the set
is judged — `docs/tools/verify_hands.py` is specific to hand cards and `verify_props.py` to
props. The equivalent here checks: the file exists for every one of the 64 names, is 768×768,
is 8-bit, and **tiles seamlessly** — which is the only one of the three that is interesting to
implement and the only one that catches Rule 1. Comparing the left column against the right
and the top row against the bottom, allowing for a small tolerance, will do it.

Until it exists, per file:

```
python3 docs/tools/pnginspect.py public/sprites/textures/oak-floor.png
```

and check the size and colour type by hand.

**Hand back the files, and a note of anything you had to change from this brief.**

---

## One decision this reopens

`public/sprites/textures` is 17 MB today. At 768×768 it is roughly **150 MB**. ISSUE-7 ruled
that normal maps are *committed* to the repository rather than generated at build time — a
decision taken against the 17 MB folder, which would roughly double the new one. That ruling
is not obviously wrong at the new size, but it is not the same question, and it should be put
to Paul before 64 normal maps are committed alongside these.
