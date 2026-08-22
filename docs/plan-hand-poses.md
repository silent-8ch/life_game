# Wiring up the unused hand art

## Context

`hands.ts` hangs two painted cards off the camera and picks between exactly two poses:
`POSES = { walk: 'edge-open', run: 'edge' }` (`resources/js/lib/engine/hands.ts:23`). Both
are seen edge on — a flat hand while walking, a fist while running.

`public/sprites/hands` holds 43 files for six people, and only 12 of them are loaded. The
rest is not junk; it is art that was made and never connected. This plan connects the part
of it that is ready, and says plainly what the rest needs before it can be.

Decisions taken: the new poses are driven by whether the crosshair is resting on something
interactable; the frame that currently lives inside a two-cell sheet gets cut out to a file
per person, preserving the file-per-pose convention.

Rough size: **~1 day**, most of it the asset cut and the handedness measurements.

## What the art actually is

The folder holds two generations, and they are not equivalent.

**Second generation** — 887×887, transparent, centred, dated 21 Aug:

| File | Content | Status |
| --- | --- | --- |
| `{who}-edge-open.png` | flat hand, edge on, fingers straight | wired as `walk` |
| `{who}-edge.png` | fist, edge on | wired as `run` |
| `{who}-back.png` | open hand, back of hand to the viewer, fingers up | **unwired** |
| `{who}-views-sheet.png` | 1774×887, two cells: fist from the back, then open hand from the back | **unwired**; cell 1 duplicates `-back.png`, cell 0 is the only unique frame |

**First generation** — 887×887, dated 20 Aug:

| File | Problem |
| --- | --- |
| `{who}-open.png` | **white background, not transparent**, and the hand is cropped off the right edge of the canvas |
| `{who}-fist.png` | same generation, same treatment |
| `{who}-hands-sheet.png` | 1774×887, the above two as cells |

The first generation cannot be wired as it stands — a white background renders as a white
box over the view, and the framing does not match the second generation's centring, so the
cards would not line up with each other. These files are **kept**, not deleted; the task for
them is regeneration to the second generation's spec, not connection. That is called out at
the end rather than folded into this plan.

`.ai/rules/engine.md` currently lists `-open`, `-fist` and `-hands-sheet` as "superseded and
now unused", which is accurate, and `-back` and `-views-sheet` as superseded too, which is
not — they are the newer art. That rule needs correcting as part of this work.

## The two new poses

```ts
const POSES = {
    walk:  'edge-open',   // existing
    run:   'edge',        // existing
    reach: 'back',        // new — open hand, back to the viewer
    grip:  'back-fist',   // new — fist, back to the viewer (cut from views-sheet)
} as const;
```

`reach` and `grip` are back-of-hand views rather than edge-on, so they read as a hand put
out toward what is in front of you rather than one swinging at your side.

### Cutting `back-fist` out of the sheet

`hands.ts:20-22` states the convention outright: "A file per pose rather than cells on a
sheet, so another pose is another file and nothing depends on the order they were cut in."
Loading cell 0 of `views-sheet` by texture offset would reintroduce exactly the order
dependency that comment exists to prevent, and `.ai/rules/engine.md` already records two
separate bugs caused by assuming a cell order held across all six sheets (`ORDERS` in
`sprite-direction.ts`, and `DRAWN` in `hands.ts`).

So: export cell 0 of each `{who}-views-sheet.png` — the left 887×887 — to
`public/sprites/hands/{who}-back-fist.png`, transparent, no resampling. Six files. The
sheets stay in place as source art.

## The trigger

Everything needed already runs every frame. `lookedAtSlug()`
(`components/game/level-viewport.tsx:484-498`) raycasts from the screen centre against
`built.targets` and returns the slug of whatever the crosshair rests on, or null; the result
is already held as `focusedSlug` and already passed to `describeSpot` as `lookingAt`
(`:725`).

The rule:

| Condition | Pose |
| --- | --- |
| crosshair on a thing, verb menu closed | `reach` |
| crosshair on a thing, a verb chosen / menu open | `grip` |
| otherwise, running | `run` |
| otherwise | `walk` |

`hands.update()` (`hands.ts:113`, `:218`) changes signature from
`(seconds, walked, running)` to `(seconds, walked, running, focus)` where `focus` is
`'none' | 'reach' | 'grip'`. The pace rule stays exactly as it is and applies whenever
`focus` is `'none'`, so nothing about walking or running changes.

The existing `CLENCH_RATE` easing (`hands.ts:40`) already smooths the walk/run swap; the new
poses ride the same easing so the hand does not pop between cards.

## Handedness

This is the part that needs care rather than typing. `DRAWN` is
`Record<sprite, Record<Pose, 1 | -1>>` (`hands.ts:64`) because, in the comment's own words,
"the art does not agree with itself" — Paul's and Wade's fists face the opposite way to
their own open hands, and William's pair face the opposite way to everyone else's.
`.ai/rules/engine.md` records the same trap twice and says to suspect this table first
whenever a hand looks inside out.

Two new poses × six people = **twelve new measurements**, each read off the artwork the way
the existing ones were: find which side carries the finger outlines; the thumb is the other
side; the thumb must end up inward. A back-of-hand view shows the thumb clearly, unlike an
edge-on one, so these should be easier and more reliable to read than the existing entries —
the rules file flags Krystal's and Luke's edge-on readings as the weakest, and these poses
are a chance to confirm them independently.

`DRAWN_BY_DEFAULT` (`hands.ts:74`) gains the two keys as well.

## Files touched

```
public/sprites/hands/{who}-back-fist.png     (new, six files, cut from views-sheet)
resources/js/lib/engine/hands.ts             POSES, Pose, DRAWN, DRAWN_BY_DEFAULT, update()
resources/js/components/game/level-viewport.tsx  pass focus into hands.update()
tests/Unit/HandsTest.php                     extend
.ai/rules/engine.md                          correct the superseded-art list
```

## Tests

`tests/Unit/HandsTest.php` already pins the handedness table. Extend it:

- every person has an entry for all four poses, and `DRAWN_BY_DEFAULT` does too
- every pose in `POSES` names a file that exists on disk for every person in
  `LevelAssets::HEIGHTS` — this is the assertion that would have caught a missing
  `back-fist` cut, and it also guards the first generation from being wired by accident
- every wired pose file is 887×887 and has an alpha channel — the check that makes the
  white-background first-generation art fail loudly rather than render as a white box
- `focus: 'none'` reproduces the current pace-derived pose exactly, so the change is inert
  when nothing is under the crosshair

`tests/Feature/DebugSnapshotTest.php` already exercises `describeSpot` with `lookingAt`; no
change needed there, but it is the quickest way to confirm the focus slug is live.

## Verification

1. `php artisan test --compact --filter=Hands`, then `npm run types:check`.
2. In the game, walk up to any thing with interactions and watch the hands change as the
   crosshair crosses onto it and off again.
3. Check both hands at once — they are mirror images, so a wrong `DRAWN` entry is wrong on
   both sides simultaneously and is obvious side by side.
4. Look at a mirror while reaching. `hands.object.visible = false` around
   `refreshReflections` should still hide them from every reflected pass; if a reaching hand
   turns up floating in a mirror, that guard has been missed for the new pose.

## Follow-on task: regenerate the first-generation art

Separate piece of work, no code in it. `{who}-open.png`, `{who}-fist.png` and
`{who}-hands-sheet.png` are palm-to-viewer poses — a genuinely different view from the
back-of-hand ones this plan wires, and worth having for gestures like waving, pushing, or
holding something out flat. They need re-rendering to the second generation's spec:

- 887×887, transparent background, hand centred in the canvas
- same wrist crop and same watch placement as `-back.png`, so the cards are interchangeable
- one file per pose; the sheet is source art, not something the engine loads

Once they exist they wire exactly the way this plan wires `reach` and `grip`: a `POSES`
entry, a `DRAWN` row per person, and a trigger. Until then, leave them where they are.

---

## Status update

The unused art was deleted from the working tree after this plan was written — only the 12
`-edge` / `-edge-open` files remain. The blobs are still in the git index (staged, never
committed), so `git checkout -- public/sprites/hands/` restores all 30 if wanted.

The art is being regenerated rather than recovered. `docs/handoff-hand-art.md` is the brief
for that, and it supersedes this plan's "cut cell 0 out of views-sheet" step: `back-fist`
now comes from its own generation, like every other pose. Two poses become four —
`back`, `back-fist`, `palm`, `palm-fist` — so `POSES`, `DRAWN` and the trigger table below
grow accordingly, and the handedness values come from the generator's report rather than
from measuring the artwork.
