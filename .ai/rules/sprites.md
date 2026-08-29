---
paths:
  - 'public/sprites/**'
---

# Sprites

## Where the sky images came from, and what shape they are
One file is one sky: twelve 4096x2048 JPEGs in public/sprites/bg, each a single equirectangular panorama. 2:1 is what equirectangular means, and `LevelAssets::skies()` indexes a file only if it measures that way — so a wrongly shaped file is not offered rather than stretched over the sky. `sky-city.jpg` (4096x512) is the standing example and is offered nowhere.

They were 1024x512 cells packed four to a 4096x512 strip until 2026-08-29. Two things were wrong with that. The packing assumed every sky file held exactly four, so a single-image sky dropped in the folder was sliced into quarters and each quarter stretched around the whole dome. And 1024 across 360 degrees is about ten screen pixels per texel on a 3440-wide display, which made the sky by far the softest thing in the picture. The strips are in `retired/strips`.

The panoramas are the tonemapped JPGs from Poly Haven, CC0 — no attribution required, but for the record: syferfontein_0d_clear, kloofendal_43d_clear, kloofendal_48d_partly_cloudy, wasteland_clouds (day 1-4); qwantani_dusk_1, belfast_sunset, qwantani_dusk_2, evening_road_01 (sunset 1-4); qwantani_night, qwantani_moonrise, qwantani_moon_noon, qwantani_dawn (night 1-4), all the `_puresky` variants, which have no ground in them. That order is not taken on trust: each re-download was matched against the art it replaced by thumbnail difference, and every one won its row by 42x or more.

Source is 8192x4096; downscale to 4096x2048 and encode at **quality 95**. Measured rather than chosen: the same image at 85 through 100 moved the worst error in the flat gradient near the zenith by one level (9 to 11) while the file went 0.25MB to 2.95MB. The floor is the source already being a JPEG, so paying past 95 buys nothing, and libgd stops subsampling chroma at 90 and above. Twelve files come to 11MB.

Check a new one three ways before shipping it: exactly 2:1; its left and right edges join (2-7 here, against 93 for the city); and no internal seam, which is what a packed strip looks like.

## The sky is the one texture that is smoothed, and is kept at full colour
Correcting the earlier note on these: the sky strips are NOT posterised or dithered. They are the panoramas at full colour, and `sky.ts` gives the dome `LinearFilter` with mipmaps while everything else in the engine uses `NearestFilter`.

The sprites and the wall textures are drawn to be blocky and want their texels left alone. The sky is a photograph of the sky; stepping it into squares reads as a mistake rather than as a style. Posterising to 26 levels with an ordered dither was tried first and looked wrong.

What blockiness remains in the sky is `PIXEL_SCALE` — the whole frame is rendered at a third and blown up with `image-rendering: pixelated`. No amount of work on the texture touches that; lowering PIXEL_SCALE is the only lever, and it changes the look of the entire game, not just the sky.
