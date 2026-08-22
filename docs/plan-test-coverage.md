# Test coverage: filling the gaps

## Context

The project tests its TypeScript from PHP. `tests/js/typescript-hooks.mjs` is an ESM loader
resolving `@/` and adding `.ts` extensions, and each `tests/Unit/*.php` shells out through
`Symfony\Component\Process\Process` to run a script under Node and assert on printed JSON.
`tests/Pest.php:20-22` binds `RefreshDatabase` to `Feature` only, so `Unit` gets no database
— which is why the engine tests live there.

The harness works well and there are eight tests using it. **All eight target
`lib/engine`.** Nothing covers `lib/editor`, nothing covers any React component, and a few
of the engine's most load-bearing behaviours are documented in `.ai/rules` as verified by
eye rather than by test.

Four tasks, independent of each other, ordered by how much they are protecting.

Total: **~2 days.**

---

## Task 1 — `carve.ts` and `map.ts`

**~1 day. The largest gap in the project.**

`resources/js/lib/editor/carve.ts` (289 lines) and `resources/js/lib/editor/map.ts` (507
lines) carry the invariants that `.ai/rules/editor.md` describes as load-bearing, and have
no tests at all. Every one of these behaviours is currently unprotected:

**From `carve.ts`:**

- `carveRooms(level, index)` subtracts a newly closed room from everything it overlaps,
  using `polygon-clipping`, so rooms never overlap.
- A sector is one closed loop with no holes, so a room drawn wholly inside another leaves a
  ring the model cannot represent. `intoSlabs` cuts the remainder into horizontal slabs —
  **box-in-box produces four rooms.** This is the single most surprising behaviour in the
  editor and the first thing to pin.
- A room fully covered by the new one is deleted.
- `inherit`/`dress`: edges surviving from an old wall keep their texture and their
  solid/mirror flags; edges created by the cut start open and untextured.
- `weldCorners(level)` inserts a corner where one room's corner lands partway along
  another's wall. Without it the engine sees a T-junction rather than a doorway.

**From `map.ts`:**

- `moveCorner` moves **every** point at that position in every sector — corners are shared
  by position, never by id, and dragging one must not tear two rooms apart.
- `splitEdge` clears the split wall's `portal_link`, because half a wall is not the wall its
  partner was paired with (`.ai/rules/engine.md` depends on this).
- `updateEdge` via `twinEdge` sets `blocks` on both sides of a shared wall at once, because
  the two sides must agree or the level is broken.
- `windingOf` / `newSector` wind new rooms positive, because the engine uses winding to work
  out which side of a wall faces in.

**Approach.** Follow the shape of `tests/Unit/WallOverhangTest.php`: a module-level PHP
helper that builds a level literal in JavaScript, calls the function under test, and prints
JSON for PHP to assert on. These are pure functions over plain data with no three.js and no
DOM, so the stub browser that `WallOverhangTest` sets up for the texture loader is not
needed — the scripts are simpler than the existing ones.

**Suggested files.** `tests/Unit/CarveRoomsTest.php` and `tests/Unit/EditorMapTest.php`.

**Start with two cases**, because they are the ones that break silently: box-in-box giving
four rooms with the right heights and textures inherited, and weld inserting a corner where
a T-junction would otherwise form.

---

## Task 2 — Extract and test `prepareReflections`

**~half a day.**

`.ai/rules/game.md` says outright of the sky-follows-the-drawing-camera fix: "Not covered by
a test: `prepareReflections` is unexported inside a .tsx, which the node-based unit harness
cannot load. Verified by eye at the coordinates from a debug snapshot."

That function (`components/game/level-viewport.tsx:93`) holds a lot of subtle, hard-won
behaviour, most of it documented in `.ai/rules/engine.md` as having been got wrong at least
once:

- `release()` on every portal at the top of each frame, **before** any pane is drawn — the
  rules note that without it the hugged pane is still parked at the player's face while
  every other pane's camera renders, which reads as "the portal shows the sky".
- `deepen()` draws depth-first, and recursion only follows panes whose `home` is in this
  pane's `onto` list — a frustum knows nothing of walls, so without the filter the depth
  budget goes on rooms that are not through the portal at all.
- At `depth >= allowed`, panes are hidden **and the sky group with them**, because a portal
  mouth with the sky left up puts daylight exactly where the next opening should be.
- `sky.follow()` is called per drawing camera, not once for the player.
- The order within a frame: release everything, run passes deepest-first, `show(0)`, then
  hug for the player's camera alone.

**Approach.** Move it to `resources/js/lib/engine/reflections.ts` and export it. It needs
the scene, renderer, camera, the built level's portals and the sky object — all of which are
already arguments or closures over values the viewport owns, so this is a mechanical
extraction, not a redesign. The viewport imports it and calls it exactly as now.

Then pin, with stub renderer and pane objects recording the order of calls:

- release is called on every portal before any `render`
- a pane whose `home` is not in the parent's `onto` list is never recursed into
- the sky's visibility is false during the deepest pass and restored after
- `show(0)` happens before the player's `hug()`, and `hug()` is called for the player's
  camera only

**Suggested file.** `tests/Unit/ReflectionsTest.php`.

This also makes `level-viewport.tsx` shorter, which at 1053 lines is worth something on its
own.

---

## Task 3 — Constants drift guard

**~2 hours.**

Two sets of numbers are written down twice, in two languages, with nothing checking they
agree:

1. `tests/Pest.php:59-63` hardcodes `MAX_STEP = 0.55` and `MIN_HEADROOM = 1.2`, copied from
   `resources/js/lib/engine/constants.ts`. The level-geometry invariants in
   `tests/Feature/LevelGeometryTest.php` are asserted against the **copies**, so if the
   engine's real values changed, those tests would keep passing while every seeded level
   quietly stopped matching the engine.

   **Correction, from writing it (ISSUE-22).** This section used to name `CLEARANCE = 0.4`
   as a third copy. It is not one: `constants.ts` has no `CLEARANCE`. Pest's is a judgement
   the tests make about how much room counts as clear, and the engine's `CLEARANCE` lives in
   `portals.ts` at `0.02` meaning the nudge that lands a body inside the far room after a
   portal crossing. Two unrelated constants sharing a name, which reads as a bug six months
   from now. `ConstantsMatchTest` pins it as a relationship to `PLAYER_RADIUS` instead of an
   equality, and says why.
2. `LevelAssets::HEIGHTS` (`app/Services/LevelAssets.php:28`) is mirrored in
   `sprite-actor.ts`. `.ai/rules/services.md` flags it: "Two copies of heights exist by
   necessity (PHP + TS) — change one, change the other". `NewLevelTest` pins the order but
   not the values against the TypeScript.

`tests/Pest.php` also re-implements `pointInSector` and `sectorAt` in PHP, mirroring
`engine/sectors.ts`. That one is harder to guard and is out of scope here — note it, do not
try to solve it.

**Approach.** One test that runs the two modules under the existing Node harness, prints
their exported constants as JSON, and asserts the PHP copies match. Not a parser — importing
the module and printing the values is both simpler and correct, and the harness already does
exactly this for other engine modules.

**Suggested file.** `tests/Unit/ConstantsMatchTest.php`.

Assert both directions where it matters: every key of `LevelAssets::HEIGHTS` appears in the
TypeScript `HEIGHTS` with the same value, **and** vice versa, so adding a seventh person to
one side fails rather than half-working.

---

## Task 4 — Collision tripwire, and hand-pose coverage

**~2 hours. Two small unrelated things.**

### The tripwire

`.ai/rules/engine.md` states: collision "is 2D only (circle vs segment vs rotated box) and
has no swept test, so it relies on `RUN_SPEED * MAX_FRAME_SECONDS` staying under
`2 * PLAYER_RADIUS`. Raising the speed cap or the frame ceiling without raising the radius
lets the player walk through walls."

That is a live tripwire sitting under three innocuous-looking numbers in `constants.ts`, and
nothing enforces it. Someone making the game feel faster will trip it, and the symptom —
occasionally falling out of the level at speed — is miserable to diagnose from scratch.

One assertion, in the same file as Task 3 or its own:

```
RUN_SPEED * MAX_FRAME_SECONDS < 2 * PLAYER_RADIUS
```

with a comment naming what breaks and pointing at the rule. It costs nothing and turns a
future bug into a red CI run with an explanation attached.

Worth adding alongside it: `RESOLVE_PASSES` in `collision.ts` is 12 rather than 3 because
two walls at a sharp angle need several passes, each only halving what is left, and at 3 the
player noticeably sank into acute corners.

**Two corrections here too, both found by writing the test (ISSUE-22, ISSUE-30).**

*A single shot at the wedge tests nothing.* This section used to say "resolves a circle
against a twelve-degree wedge and asserts the settled distance is at least `PLAYER_RADIUS`".
Written that way it **passes at `RESOLVE_PASSES = 3`** — shoving the player hard at the point
of a wedge pushes them straight back out of the mouth and settles in one pass. The first
version of `CollisionLimitsTest` did exactly that and pinned nothing. What needs twelve
passes is a *partial* move that stops the player inside, where leaving one wall pushes them
into the other, and finding it needs sweeping across start positions and push distances
rather than one fixed shot. See the test; it explains this where somebody changing it will
read it.

*And the 0.28 m figure was wrong.* `.ai/rules/engine.md` used to claim the solver settles the
player "no closer than 0.28 m to a wall even in a 12-degree wedge". Swept, the worst case is
0.154 m at twelve passes and 0.064 m at three. Below about six degrees it stops being a
clearance at all: there is nowhere within thirteen metres of a three-degree apex to stand a
circle of `PLAYER_RADIUS` clear of both walls, so the solver pushes the player out through
the corner rather than settling them. Fixed in the rules file under A-10.

Do not restate any of those numbers here. They will drift; the tests will not.

### Hand poses

Folded in here because it is the same size and touches the same kind of table. Covered in
full by `docs/plan-hand-poses.md` — extend `tests/Unit/HandsTest.php` so that every pose in
`POSES` has a `DRAWN` entry for every person, names a file that exists, and that every wired
file is 887×887 with an alpha channel. That last assertion is what stops the
white-background first-generation art from being wired by accident.

---

## What is still not covered after all four

Named so nobody assumes otherwise:

- **No React component tests anywhere.** `inspector.tsx` (1051 lines), `map-view.tsx` (821)
  and `side-view.tsx` have no coverage, and there is no vitest or jest in the project to
  write them with. Adding a component test runner is a real decision, not a task — it is the
  first thing to weigh if editor bugs start recurring.
- **`tests/Pest.php`'s PHP re-implementation of `pointInSector` / `sectorAt`** still drifts
  freely from `engine/sectors.ts`.
- **Nothing renders.** Every engine test asserts on geometry and structure, never on pixels.
  The portal, mirror and sky-lid rules in `.ai/rules/engine.md` are full of failures whose
  only symptom is what appears on screen — a bright hairline at a pane's rim, a flash of sky
  walking through a portal, coplanar faces flickering. Those remain eye-verified.

## Verification

`composer test` runs `config:clear`, Pint, PHPStan and then the suite; `composer ci:check`
adds `npm run lint:check`, `format:check` and `types:check`. Both should be green before any
of these tasks is called done.

While iterating, `php artisan test --compact --filter=Carve` and friends are much faster than
the full run — each Node-harness test spawns a subprocess, so the engine tests are the slow
part of the suite.
