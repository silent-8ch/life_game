# Doors that open

## Context

A doorway in this engine is a **gap between wall runs**. `.ai/rules/seeders.md` says so
outright: doorways and windows are authored as separate wall runs with a hole between them,
with a thing placed in the hole, and a lintel marked not solid because "collision ignores
height". Nothing swings, and nothing can be shut.

`ThingKind::Door` and `ThingKind::Window` already exist (`app/Enums/ThingKind.php`) and are
**completely inert** — `buildThings` (`lib/engine/build/things.ts:20`) skips actors and treats
every other kind identically: a `BoxGeometry`, a tiled texture, and a box collider if
`is_solid`.

This is the highest value-per-day item left. A house whose doors work is a house; a house of
permanently open holes is a floor plan.

Two things make it much smaller than it looks:

- **Prop rendering (`plan-prop-rendering.md`) already does most of the groundwork** — fitted
  UVs so a 0.9 m door shows its whole image, and alt-state textures driven by a game flag.
  Doors are that machinery plus movement.
- **The interaction system is already complete.** `Verb::Use` exists, routes through
  `InteractionResolver` and `EffectApplier`, and `EffectType::SetFlag` / `ConditionType::FlagIs`
  already round-trip through the editor. A door needs no new verb, effect or condition.

Rough size: **~3 days**, after `1-03` / `A-03`.

---

## The one real gap: flags never reach the engine

`LevelPayload::forEngine()` ships a thing's `verbs` and nothing else about state
(`LevelPayload.php:78-93`). `GameFlag` rows (`game_state_id`, `key`, `value`) live entirely
server-side. So on load, the engine has no idea whether the front door was left open.

**`forEngine` must ship the game state's set flags**, and the `Level` payload gains
`flags: Record<string, string>`. This is a prerequisite for doors and it also completes the
prop alt-state feature, which has the same hole — a light switch would forget it was on.

Keep it narrow, in the spirit of `.ai/rules/services.md`'s note that `forEngine` ships only
what the engine needs: send the flags, not the conditions or effects that set them.

---

## How a door is authored

A thing of kind `Door`, sitting in the gap between two wall runs, with:

| Field | Meaning |
| --- | --- |
| `texture` | the closed face, e.g. `door-interior` from `plan-prop-rendering.md` |
| `angle` | which way the door faces, as now |
| `is_solid` | true — its collider is what makes it a door rather than a curtain |
| `alt_flag` | the flag that means "this door is open" |
| `swing` | **new** — degrees it opens through, signed. Default 90. |
| `hinge` | **new** — `left` or `right`, which vertical edge it turns about |

`alt_flag` is reused from `plan-prop-rendering.md` rather than adding a `door_open` column.
A door is a two-state thing; that is exactly what `alt_flag` already models.

Opening is authored as an ordinary interaction: verb `Use`, effect `SetFlag`, subject the
flag name, value `open`. Locking is `ConditionType::HasItem` on a key — free, already works,
no new machinery.

---

## Engine changes

### 1. Colliders need to be switchable

`scene.colliders` is a flat array built once (`build/things.ts:69`) and read every frame by
`moveWithCollisions` (`collision.ts:161-208`). Nothing can currently change after build.

Give `BoxCollider` two optional fields — `slug` and `enabled` (default true) — and have the
solver skip anything disabled. That is a one-line filter in `resolveCollisions` and leaves
segment colliders untouched.

Deliberately **not** rebuilding the collider array on each change: it is read every frame and
also handed to `actors.update` (`level-viewport.tsx:766`), so swapping the array underneath
them invites a stale reference. A flag on a stable object cannot go stale.

### 2. A door turns about its edge, not its middle

`buildThings` puts the mesh at the holder's origin and rotates the holder about its centre
(`things.ts:26-33`). A door hinged in the middle looks like a revolving door.

So a door's holder sits **at the hinge edge**, with the mesh offset by half its width inside
it. Then `holder.rotation.y` is the swing, and the geometry follows correctly for free.
`hinge: 'left' | 'right'` decides the sign of that offset.

### 3. The swing itself

A small controller returned by `buildThings`, in the shape it already uses for the
looked-at highlight (`things.ts:81-92` returns a closure). It holds each door's target angle
and eases toward it — the same treatment `STEP_SMOOTHING` gives the player's eye, so a door
never snaps.

The collider follows the *state*, not the animation: **it goes solid the moment a door starts
closing and stops being solid the moment it starts opening.** Tying the collider to the
animated angle means the player can be caught inside a closing door, which is a far worse bug
than a door that is walkable a few frames early.

### 4. Opening must feel instant

Interactions round-trip to the server. Waiting for that before the door moves would make
every door feel broken.

So the engine opens the door **optimistically** on `Use` and lets the flag confirm it. Inertia
v3 has optimistic updates with automatic rollback built in (see the Inertia section of
`CLAUDE.md`), so a refused interaction — a locked door, a missing key — puts the door back
without any bespoke reconciliation.

The rollback path is the one to test properly: a locked door must swing shut again, not stay
open with the refusal text on screen.

---

## Windows, cheaply

`ThingKind::Window` is inert in the same way, and once doors work a window is the same object
with a different swing and no collider change — plus the broken state that
`plan-prop-rendering.md` already covers via `texture_alt`. Not in scope here, but the shape
falls out for free, so do not design doors in a way that excludes it.

---

## Data model

`level_things` gains `swing` (float, default 90) and `hinge` (enum `left`/`right`, default
`left`). Both meaningful only when kind is `Door`, enforced in `after()` validation rather
than by making everything nullable.

`levels` needs nothing. The flags ride on the existing `game_states` → `game_flags` relation.

Nine touch points as usual, plus `LevelPayload::forEngine()` for the flags.

---

## Tasks

Following the board's path split:

| ID | Owner | Task |
| --- | --- | --- |
| 1-08 | 1 | Ship set flags in `forEngine`; `Level.flags` in TS types. **Unblocks A-07 and completes prop alt-state.** |
| 1-09 | 1 | `swing` and `hinge` columns, model, payload, validation, writer, `newProp`; inspector controls shown only for kind `Door`. |
| A-07 | A | Switchable colliders; hinge-edge holders; the swing controller; optimistic open with rollback. Depends on 1-08, 1-09, A-03. |

---

## Tests

- `tests/Unit/DoorTest.php` (Node harness, as `WallOverhangTest.php` does it)
  - a door's holder sits at its hinge edge, so the far edge sweeps and the hinge edge does not
    move — the direct test for "does it revolve or does it swing"
  - `hinge: 'right'` mirrors that offset
  - a disabled collider is skipped by `moveWithCollisions`; enabling it blocks again
  - the collider is solid the instant a close begins, and open the instant an open begins —
    **not** interpolated with the angle
  - a door whose `alt_flag` is set in `level.flags` is built already open
- Feature: `swing`/`hinge` round-trip; they are rejected on a non-Door kind; `forEngine` ships
  flags and `forEditor` still ships the full interaction tree (`.ai/rules/services.md` is
  explicit that the engine payload must never widen).

---

## Verification

1. `composer test`, `npm run types:check`, `npm run lint:check`.
2. Put a door in a doorway, `Use` it, watch it swing about its hinge edge rather than its
   middle, and walk through.
3. Close it and walk into it — you should be stopped.
4. **Close it while standing in the doorway.** You should not end up inside the door. This is
   the case the collider-follows-state rule exists for.
5. Lock one behind `HasItem` on a key you do not have. It must swing shut again on refusal,
   not stay open with an error message.
6. Reload the page with a door left open. It must still be open — that is the flags-in-payload
   change working.
7. Look at a door through a portal and in a mirror while it swings; it is ordinary geometry, so
   it should animate in every pass without special handling.

---

## Honest risks

- **The optimistic-update rollback is the fiddly part**, not the swing. Test the refusal path
  before the happy path.
- **A door in a portal mouth is undefined.** A mouth is one-sided and builds no wall or
  collider on the face naming the link (`.ai/rules/engine.md`), so a door there would be a
  collider inside an opening designed to have none. Reject it in validation rather than
  discovering it later.
- Doors are not counted in `MAX_STEP`/`MIN_HEADROOM` doorway reachability
  (`LevelGeometryTest.php`), so a level could shut itself into two halves and still pass. Worth
  a follow-up invariant once doors exist; not worth guessing the rule before then.
