---
paths:
  - resources/js/components/game/level-viewport.tsx
---

# Game

## The sky is a background, and no longer has to follow anything
`scene.background` has no position. Three draws it from the camera's orientation with the translation dropped, so it is right for every camera without being told — the player's, a portal's, a mirror's at the thirty-second bounce. `SkyDome.follow` is gone and so is `SKY_RADIUS`.

**This was the hardest-won rule in this file and it is now history.** Keeping the story because the failure it describes is the one a future sky change would reintroduce:

The sky used to be a 90m sphere with the panorama on the inside. Being finite, it had a position, and `step()` set it to the player once a frame — but the pane passes run afterwards from cameras somewhere else entirely (a portal's camera is the player carried through the transform, which in level 8 is 68m away in x). Left parked at the player, the dome appeared in a pane's view as a ball of sky off to one side: a wall of it across part of the view with the level showing past its edge. Back when there were parallax horizon bands inside it, it read worse still — slabs of hillside a few metres across in front of everything the far room contained. A portal full of grass, a portal full of sky, and torn vertical panels, one per band offset by its own lag.

The fix was `drawPane` calling `sky.follow` with the pane's own `viewerAt`, and the refresh putting it back around the player before the main render. It worked, and it was a workaround for the sky being a thing rather than a direction.

**What still needs this treatment: billboards.** `props.faceViewer` and `actors.faceViewer` sit exactly where `sky.follow` used to, in `drawPane`, for exactly the reason the sky used to. They are at a finite distance and genuinely do have to be turned per drawing camera. `tests/Unit/ReflectionsTest.php` pins the sequence — it used to assert the sky's and the props' as the same list, and now asserts only the props'. Any new pass that renders the scene from a different viewpoint has to turn them.

`prepareReflections` is `resources/js/lib/engine/reflections.ts`, not a closure in the viewport, so the node harness can load it. The viewport imports it and calls it once a frame; nothing else should.

## There are no horizon layers, and the columns that held them stay anyway
Paul, on the parallax bands of hills and rooftops that used to stand inside the sky dome: *remove the layers and horizons bits. they do not look good.* They are out of both editors and out of `sky.ts`, which is now a textured sphere and nothing else.

`levels.backdrop_theme` and `levels.backdrop_layers` were deliberately **kept**, and the 21 layer PNGs are in `public/sprites/bg/retired/layers` — `File::files()` does not recurse, so moving art into `retired` takes it out of the editor without deleting it. Nothing reads them: `LevelPayload` stopped sending them, so an engine that cannot see a horizon cannot draw one. `LevelWriter` deliberately omits both columns from its update rather than nulling them — a save has no opinion about a horizon and must not wipe what a level was given. `LevelEditorTest` pins that. Seeders and the exported level JSON still set them, and that is fine; they are inert.

So this is reversible and cost nothing to make reversible. If it is ever made permanent, the drop migration and the seeder arguments are the work, not the engine.

## One file is one sky, and what makes it a sky is its shape
The panoramas used to be packed four to a 4096x512 strip and picked as a file plus a cell number (`sky_image` + `sky_variant`). **That is gone.** Each panorama is its own 1024x512 file — `sky-day-1.png` through `sky-sunset-4.png` — `sky_image` names it, and there is nothing else to say. The strips are in `public/sprites/bg/retired/strips`.

The packing had a bug that no amount of care in the picker would have fixed: it assumed **every** `sky-*.png` held exactly four panoramas. Paul dropped in `sky-city.png`, one continuous photograph, and it was offered as City 1 to City 4 — four quarters of one picture, each stretched around the whole dome. He caught it by reading the code, not by looking at the game: *looks like you are inferring there are 4 cities, but it is one image?* A convention a file can silently fail to follow is not a structure.

So `LevelAssets::skies()` now indexes a file only if it is **2:1**, which is what equirectangular means — 360 degrees across against 180 up and down. `sky-city.png` is 8:1 and does not join up with itself, so it is not offered anywhere. The check is the file's own shape rather than a list of names, so re-exporting a rejected file at 2:1 is all it takes to make it appear. `LevelSkyTest` pins both halves.

How to tell a packed strip from a single panorama, if this ever comes up again: compare adjacent columns at the cell boundaries against the typical adjacent-column difference, and check whether each candidate cell's own left and right edges match. A real 360 panorama wraps (the twelve score 1.9 to 5.0); the city does not (139 to 313), and shows no seam at 1024/2048/3072 either.

Both pickers show the sky as a flat 2:1 band rather than a thumbnail, whole, at `background-size: 100% 100%`. Laid out flat it is very nearly the full turn on the spot — left edge and right edge the same direction — which a postage stamp cannot convey.

`2026_08_29_143104_split_sky_strips_into_one_file_per_sky` folds the cell number into the name one-based (`sky-day` cell 0 becomes `sky-day-1`) and drops `sky_variant`. It rewrites row by row rather than with a concatenation, because SQLite spells that `||` and MySQL spells it `CONCAT` — see `migrations.md`.
