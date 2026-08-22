# Next directions

A menu, not a plan. Four buckets — ambitious, conservative, quick wins, tech debt — each
item saying what it is, where it lands, and roughly what it costs. Everything here is
grounded in the code as it stands; file references are current.

Things that already have written plans are summarised here but not repeated in full:

| Plan | Covers |
| --- | --- |
| `docs/plan-slopes-and-stats.md` | Sloped floors and ceilings; starting RPG stats |
| `docs/plan-test-coverage.md` | The four test-coverage tasks |
| `docs/plan-hand-poses.md` | Wiring the unused hand art, and regenerating the rest |
| `docs/handoff-hand-art.md` | Brief for the image AI, plus the tools in `docs/tools/` |
| `docs/plan-lighting.md` | Baked GI lightmaps, light grid, moving shadow casters |
| `docs/plan-prop-rendering.md` | Fitted UVs, cutout sprites, cross planes, prop states |
| `docs/handoff-prop-sprites.md` | Brief for ~40 modern-home prop sprites |

---

## Ambitious

### Room over room (Build's TROR)

**The big one for anyone coming from Build.** Right now a level is a flat floor plan: one
sector per patch of x/z, and a second storey is faked with the Doom trick — the upstairs
room sits next door in x/z and a portal or staircase carries you there. `.ai/rules/engine.md`
records the cost of that fake in detail: sky lids exist purely to stop sight-lines running
out over a sky room's walls into the bedrooms sitting next to the yard, and
`BuiltLevel.skyLids` only shows a lid to somebody standing in its own room, which means
looking *into* a sky room from next door still leaks.

True room-over-room would let sectors stack in y, and it would delete that whole class of
workaround. It is also the largest change on this list: `sectorAt` is a 2D point-in-polygon
test (`sectors.ts`), `contains` knows nothing of height, the collider set is a flat list of
segments, and `LevelWriter` has no notion of which sector is above which.

Realistically this wants vertical physics (below) first, and it wants slopes first too,
since a Build veteran will expect the two to compose. **Weeks, not days.**

### Vertical physics — gravity, jumping, falling

`collision.ts` opens with a comment saying nothing has a height as far as that file is
concerned, and it means it: collision is circle-vs-segment and circle-vs-box in 2D, with no
swept test. The player's y is not simulated at all — `level-viewport.tsx:608-622` reads the
sector's floor height and eases the eye toward it with `STEP_SMOOTHING`.

Adding real vertical motion unlocks jumping, ledges, falling damage, crouching under a low
lintel (which `.ai/rules/seeders.md` currently says to fake by marking a lintel not solid,
"because collision ignores height"), and makes slopes feel like slopes rather than like a
smoothly changing floor number. It also makes `MAX_STEP` a runtime decision rather than a
build-time collider, which is a better model.

The honest cost: a swept solver, a y axis through `collision.ts`, and a rethink of the
build-time step gate at `build-level.ts:878-888`. **~1 week**, and it invalidates a few
existing tests by design.

### Shared presence — more than one person in the level at once

`@laravel/multiplex` is already sitting in `package.json` as an optional dependency and is
imported nowhere. The game is *about* a household of six, the engine already draws all six
as sprite actors with correct per-person facing tables, mirrors already show other bodies
correctly, and `sprite-actor.ts` already interpolates movement. The hard rendering work is
done; what is missing is a transport and a server-side authority for positions.

Scope control matters here: "everyone walks around the same house and can see each other"
is a satisfying week. "Everyone shares game state, inventory and interactions" is a
different and much longer project, because `game_states` currently has exactly one row per
game. **~1 week for presence only**, if kept to positions and facing.

### Stats that actually do something

The natural sequel to `docs/plan-slopes-and-stats.md` Part 2, which deliberately stops at
storing numbers. The interesting version is not damage rolls — it is letting stats change
what the world *says*. `describeSpot` and the interaction system are already the game's
voice; gating a response on Perception, or offering an extra `Talk` line on Charisma, makes
the numbers matter without inventing combat.

Lands as a `StatAtLeast` case on `ConditionType`, a `ChangeStat` case on `EffectType`, and
their editor controls in `interaction-panel.tsx`. **~3 days**, and it is a clean, low-risk
extension of machinery that already works.

### Sector lighting and surface relief — **now planned in `docs/plan-lighting.md`**

Colour lives at level scope only, every surface is `MeshBasicMaterial`, and the scene has no
lights at all — so bump mapping is impossible until lighting exists. Planned as one job:
Phong materials, per-sector light level and tint carried as vertex colours, placeable lamps,
and normal maps derived from the existing diffuse textures. **~2 weeks**, and it exposes a
latent bug — every ceiling's surface normal currently points up, the same way as a floor's.

---

## Conservative

### Doors that open

Today a doorway is a *gap between wall runs* — `.ai/rules/seeders.md` is explicit that
doorways and windows are authored as separate wall runs with a hole between them, and a
thing placed in the hole. Nothing swings.

A real door is a `level_things` kind whose open/closed state drives whether its collider is
in the set, animated by rotating the sprite quad or a thin box. The `Use` verb already
exists and already routes through `InteractionResolver`, so the interaction plumbing is
free. **~2-3 days**, and it is the single feature most likely to make the house feel like a
house.

### Footsteps and per-sector ambience

There is no audio anywhere in the project. Footsteps timed off the same metre tally that
already picks the walk frame and swings the hands (`hands.ts` uses it today) is a small,
high-return addition, and a per-sector ambient loop — rain in the yard, hum indoors — rides
on a nullable column beside the textures. **~2 days.**

### Saving where you are in a first-person level

`game_states` has `current_level_id` but no position, angle, or sector. Reopening a game
drops you back at the level's spawn point. Storing x/z/angle on the state row and restoring
it is small and immediately noticeable. **~1 day.**

### A stair generator in the editor

Pairs naturally with slopes: select a room, say how many steps and over what rise, and the
editor carves it into N sectors with the heights already set. `carve.ts` already knows how
to split a room into slabs (`intoSlabs`), so most of the geometry work exists. **~2 days**,
and it turns the most tedious authoring job in the editor into one dialog.

### Minimap

`sectors.ts` has `boundsOf` and the editor already draws a complete canvas floor plan in
`map-view.tsx`. A corner minimap is largely a matter of reusing that drawing code with the
player's position on top. **~1-2 days.**

### First-person item pickup

The `Take` verb exists and items exist, but inventory is currently a point-and-click concern.
Wiring `Take` on a level thing through to `EffectApplier::giveItem` closes the loop between
the two halves of the game. **~2 days.**

---

## Quick wins

Each of these is under half a day. **Five of the six are done** — struck through, with the
commit, so the list stays a menu rather than becoming a claim that nothing has happened.

- ~~**Arrow-key nudge for heights in the editor.**~~ `cb7aa76`. Up/down nudges the floor by
  0.1, Shift for the ceiling, on the whole picked set, sharing `HEIGHT_STEP` with the
  side-view drag so the two cannot drift apart.
- ~~**Undo/redo.**~~ `4ba8756`. A bounded history of forty drafts plus Cmd-Z, in
  `lib/editor/history.ts` so the ordering is testable rather than buried in the component.
  A drag or a burst of arrow presses coalesces into one step.
- ~~**Duplicate a room.**~~ `cb7aa76`. Cmd-D copies every picked room a metre along with a
  free slug. Walls keep their textures and flags; portal links are dropped, since a portal
  pairs exactly two mouths by name. The copy is not carved against its source.
- ~~**Drag the spawn point.**~~ `cb7aa76`. Grabbable with the select tool, after a corner and
  before a thing, matching what draws on top of what.
- **Wire up the unused hand art.** Still open, and the description below is now wrong in two
  ways worth knowing about: the `-back` and `-views-sheet` files it names have since been
  deleted, and the blocker is not wiring but art — 24 of the 36 cards do not exist. Two
  generation rounds regenerated `edge`/`edge-open`, which already existed, instead. See
  `docs/plan-hand-poses.md` and the task board's ISSUE-1.
- ~~**A room-count / extent readout in the editor.**~~ `cb7aa76`. Bottom-left of the floor
  plan, and it says whether there is an edit to step back from.

---

## Tech debt

Ordered by how much it will hurt to leave.

### 1. ~~`UpdateLevelMapRequest::authorize()` returns `true`~~ — **closed, `9110b36`**

`app/Policies/LevelPolicy.php` exists and both ways into the editor go through the gate:
the request defers to it, and `LevelEditorController::edit()` calls `Gate::authorize`.

Struck through rather than deleted, because a menu that reports a fixed security hole as
live is worse than one that is merely out of date — it gets the work done twice, or worse,
believed.

One thing it did **not** fix, and the reason the rule is still a placeholder: nothing in the
schema says who owns a game. `LevelPolicy::update()` returns `true` for any signed-in user,
which is what the `auth` middleware already implied. The gain is structural — there is now
one method to change when a game gains an owner, instead of a `return true` in a request
class. Only `update` is defined, deliberately: Filament treats a *missing* policy method as
allowed, so the generated `return false` stubs would have hidden the Levels resource from
the admin panel.

### 2. Test coverage gaps — **now planned in `docs/plan-test-coverage.md`**

Four tasks, ~2 days total: `carve.ts` and `map.ts` (289 + 507 lines carrying the project's
trickiest invariants, with zero coverage); extracting `prepareReflections` out of the .tsx so
it can be tested at all; a constants-drift guard between the PHP and TypeScript copies of
`MAX_STEP`/`MIN_HEADROOM`/`CLEARANCE` and `HEIGHTS`; and a CI tripwire for the documented
collision tunneling condition.

**All four are done**, and so is the gap the plan named as remaining: there is a React
component test runner now (Vitest and Testing Library, `093f208`), wired into
`composer ci:check` so it runs on every push rather than when somebody remembers.

Two corrections came out of doing the work, and are written into the plan file itself:
`CLEARANCE` was never a copied constant — the engine's is in `portals.ts` and means
something unrelated — and the wedge test as originally described passes at
`RESOLVE_PASSES = 3` while pinning nothing at all.

What is still uncovered: nothing tests what actually **renders**. The component runner tests
what a panel does, not what appears on a screen; the engine's rules about portals, mirrors
and sky lids describe failures whose only symptom is visual, and those stay eye-verified.

### 3. ~~The 1000-line files~~ — **all three split**

`build-level.ts` went first (`0f2cb7c`) into `lib/engine/build/`, because everything else
rebased onto it. `level-viewport.tsx` followed. `inspector.tsx` is 124 lines and an
orchestrator (`a162ba3`), with its panels under `components/editor/inspector/`.

`map-view.tsx` (821) is the one left, and it is not the same kind of problem: it is one
canvas draw loop and a pointer state machine, not several modes sharing a file.

Worth recording how the last one was done, because the ordering was the whole of it. The
seams were real, but a React split has no equivalent of the whole-scene diff that made the
engine refactor safe — so the component tests came **first** (`bd0c9bc`), pinning which
callback each control reaches as well as what it sends, and the move ran behind them with
the suite re-run after every panel. That is more work than the refactor it protects, which
is worth knowing before budgeting the next one.

### 4. No swept collision

`.ai/rules/engine.md` states that collision "relies on `RUN_SPEED * MAX_FRAME_SECONDS`
staying under `2 * PLAYER_RADIUS`", and that raising the speed cap or the frame ceiling
without raising the radius lets the player walk through walls. That is a live tripwire under
an innocuous-looking constant change.

The CI assertion for it now exists — `tests/Unit/CollisionLimitsTest.php`, which fails if
anyone raises the speed or the frame ceiling without the radius. A real swept solver is
still folded into vertical physics, above; the tripwire only tells you when you have tripped
it.

### 5. `.ai/rules/engine.md` contradicts itself

Three consecutive sections cover sprite mirroring: two both titled "Mirror paired sprite
directions in UVs" giving *different* rules for 225°/315°, and a third headed "Diagonal
handedness correction" that supersedes one of them. An agent reading top to bottom gets the
wrong answer twice before getting the right one. **The contradictions are fixed** (A-08, A-10) — including a clearance figure that was
simply false, and which is now stated as a relationship rather than a number, since a number
in a rules file drifts and the test does not. The file being 250+ lines and readable-if-split
by subsystem still stands.

### 6. `LevelWriter` recreates everything on save

`save()` deletes all sectors and vertices and recreates them; `writeThings()` does the same
for things and their interactions. It works, and `.ai/rules/engine.md` even depends on it
(portals pair by name rather than id "because `LevelWriter` rebuilds every edge row on
save"). But it means ids churn on every save, so nothing can ever hold a durable reference
to a wall or a room, and a large level rewrites hundreds of rows to change one number. Not
urgent — worth knowing before anything tries to reference an edge by id.

---

## If you want a suggested order

Items 1 to 3 are done. What is left of the original list, in the same order:

1. ~~The `LevelPolicy`.~~ `9110b36`.
2. ~~The test-coverage tasks.~~ All four, plus the component runner they said was missing.
3. ~~Slopes.~~ Data model and editor landed; the engine half is with agent A. Both
   `build-level.ts` and `inspector.tsx` were split on the way in.
4. **Hand poses** — blocked on art, not on code. 24 of the 36 cards do not exist.
5. **Doors**, still the largest felt improvement per day spent. The `Use` verb and
   `InteractionResolver` already exist, so the interaction plumbing is free.
6. **Vertical physics**, which slopes will have made you want.
7. **Room over room**, once physics can support it.

Live work is tracked in `docs/tasks.md`, which is the board rather than the menu: this file
says what is worth doing, that one says who is doing it.
