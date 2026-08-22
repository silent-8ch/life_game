---
paths:
  - resources/js/components/game/level-viewport.tsx
---

# Game

## The sky must follow whichever camera is drawing, not just the player
`SkyDome.follow(x, y, z)` centres the dome and slides the parallax bands. `step()` calls it once a frame for the player — but the pane passes run afterwards with cameras somewhere else entirely (a portal's camera is the player carried through the transform, which in level 8 is 68 m away in x).

Left parked at the player, the backdrop bands appear in the pane's view as slabs of hillside a few metres across, in front of everything the far room contains. That is a portal full of grass, a portal full of sky, and the torn vertical panels — the bands, each offset by its own lag.

`drawPane` in `prepareReflections` now calls `sky.follow(at.x, from.position.y, at.z)` using the pane's own `viewerAt`, and the refresh puts it back around the player before the main render. Any new pass that renders the scene from a different viewpoint has to do the same.

`prepareReflections` is `resources/js/lib/engine/reflections.ts` now, not a closure in this file, so the node harness can load it. `tests/Unit/ReflectionsTest.php` pins the sky moving to whoever is looking and back to the player last, along with the rest of the order of a frame. The viewport imports it and calls it once a frame; nothing else should.
