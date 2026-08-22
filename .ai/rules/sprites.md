---
paths:
  - 'public/sprites/**'
---

# Sprites

## Where the sky images came from, and what shape they are
`sky-day`, `sky-sunset` and `sky-night` in public/sprites/bg are strips of four variants, each variant one equirectangular panorama at 1024x512. `sky.ts` wraps one cell round the whole dome (`repeat.x = 1/4`, `offset.x = variant/4`), so a 2:1 cell maps correctly and the horizon lands at eye level. The old hand-painted gradients were 1:1 cells, which put their horizon at the bottom pole; they are kept unindexed in public/sprites/bg/retired (`File::files()` does not recurse, so `LevelAssets::skies()` never sees them).

The panoramas are the tonemapped JPGs from Poly Haven, CC0 — no attribution required, but for the record: syferfontein_0d_clear, kloofendal_43d_clear, kloofendal_48d_partly_cloudy, wasteland_clouds (day); qwantani_dusk_1, belfast_sunset, qwantani_dusk_2, evening_road_01 (sunset); qwantani_night, qwantani_moonrise, qwantani_moon_noon, qwantani_dawn (night), all the `_puresky` variants, which have no ground in them.

They were reduced to 26 colour steps per channel with a 4x4 ordered dither, which is what keeps them reading as painted next to pixel-art sprites and stops a sky gradient banding into stripes. Rebuild the same way if you add one.

## The sky is the one texture that is smoothed, and is kept at full colour
Correcting the earlier note on these: the sky strips are NOT posterised or dithered. They are the panoramas at full colour, and `sky.ts` gives the dome `LinearFilter` with mipmaps while everything else in the engine uses `NearestFilter`.

The sprites and the wall textures are drawn to be blocky and want their texels left alone. The sky is a photograph of the sky; stepping it into squares reads as a mistake rather than as a style. Posterising to 26 levels with an ordered dither was tried first and looked wrong.

What blockiness remains in the sky is `PIXEL_SCALE` — the whole frame is rendered at a third and blown up with `image-rendering: pixelated`. No amount of work on the texture touches that; lowering PIXEL_SCALE is the only lever, and it changes the look of the entire game, not just the sky.
