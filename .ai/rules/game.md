---
paths:
  - resources/js/components/game/level-viewport.tsx
---

# Game

## The sky must follow whichever camera is drawing, not just the player
`SkyDome.follow(x, y, z)` centres the dome. `step()` calls it once a frame for the player — but the pane passes run afterwards with cameras somewhere else entirely (a portal's camera is the player carried through the transform, which in level 8 is 68 m away in x).

The dome is `SKY_RADIUS` (90 m) across, not infinite, so a dome parked at the player is a sphere of sky sitting off to one side of the pane's camera: a wall of it across part of the view and the rest of the level showing past its edge.

This bit first: back when there were parallax horizon bands inside the dome, the same bug read far worse, because a band is close enough to have a near side. Left parked at the player they appeared in the pane's view as slabs of hillside a few metres across, in front of everything the far room contained — a portal full of grass, a portal full of sky, and torn vertical panels, one per band offset by its own lag. **The bands are gone** (see below); the rule survives them because the dome alone still breaks.

`drawPane` in `prepareReflections` now calls `sky.follow(at.x, from.position.y, at.z)` using the pane's own `viewerAt`, and the refresh puts it back around the player before the main render. Any new pass that renders the scene from a different viewpoint has to do the same.

`prepareReflections` is `resources/js/lib/engine/reflections.ts` now, not a closure in this file, so the node harness can load it. `tests/Unit/ReflectionsTest.php` pins the sky moving to whoever is looking and back to the player last, along with the rest of the order of a frame. The viewport imports it and calls it once a frame; nothing else should.

## There are no horizon layers, and the columns that held them stay anyway
Paul, on the parallax bands of hills and rooftops that used to stand inside the sky dome: *remove the layers and horizons bits. they do not look good.* They are out of both editors and out of `sky.ts`, which is now a textured sphere and nothing else.

`levels.backdrop_theme` and `levels.backdrop_layers` were deliberately **kept**, along with the 21 layer PNGs in `public/sprites/bg`. Nothing reads them: `LevelPayload` stopped sending them, so an engine that cannot see a horizon cannot draw one. `LevelWriter` deliberately omits both columns from its update rather than nulling them — a save has no opinion about a horizon and must not wipe what a level was given. `LevelEditorTest` pins that. Seeders and the exported level JSON still set them, and that is fine; they are inert.

So this is reversible and cost nothing to make reversible. If it is ever made permanent, the drop migration and the seeder arguments are the work, not the engine.

## A sky is one choice over two columns
The art is packed four equirectangular panoramas to a strip (`sky-day.png` is 4096x512, four 1024x512 cells), so `sky_image` and `sky_variant` are two columns. Which strip a panorama landed in is a fact about the file and not a decision anybody makes, and asking for it as a second question made a twelve-item list read as three.

`Level::$sky` is an appended accessor/mutator over the pair, `image:variant` — `sky-day:0`. It is in `$appends` because Filament fills a form from `attributesToArray()` and would not otherwise see it. `LevelAssets::skyChoices()` is the menu (`skies()` is still the storage) and both editors read it. The two columns are untouched underneath, which is why the engine, the payload and every level already drawn needed no migration.

Both pickers show the chosen cell as a flat 2:1 band rather than a thumbnail: a cell is equirectangular, so laid out flat it is very nearly the full turn on the spot, left edge and right edge the same direction. It is done with `background-size: 400% 100%` and a background-position of `variant / (cells - 1)` as a percentage — 0%, 33.3%, 66.7%, 100% — because in CSS 100% means the image's right edge against the box's right edge, not anything about the image's own width.
