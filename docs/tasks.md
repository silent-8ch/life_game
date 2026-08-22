# Task board

Two agents, **1** and **A**, each committing to their own branch (`agent1`, `agentA`) and
merging to `main`. Agent 1 is session `life-game-22`; agent A is `life-game2-78`. Planning is
`live-game-planning-89`, which owns this file and lands it on `main` — that is how the board
reaches both of you. This file is the shared state: who owns what, what is in flight, what is
done, and what is broken.

Last audited against the repo at `952576b`. `origin/agentA` is level with `main`;
`origin/agent1` has no diff against it.

---

## How this works

**Ownership is by path, not by task.** The two agents collide on files, not on features, so
the split below is designed to keep them out of each other's way. Two files are still the real
contention risk — `level-viewport.tsx` (1017 lines) and `inspector.tsx` (1156) — and most of
the work left touches one of them.

| Path | Owner |
| --- | --- |
| `app/**`, `database/**` | **1** |
| `resources/js/types/**` | **1** — this is the contract between them |
| `resources/js/components/editor/**`, `resources/js/lib/editor/**` | **1** |
| `resources/js/lib/engine/**` | **A** |
| `resources/js/components/game/**` | **A** |
| `public/sprites/**` | **A** |
| `docs/**`, `docs/tools/**` | **1** — except `docs/tasks.md`, which is planning's |
| `tests/**` | whoever wrote the code — one new file per feature, so conflicts are unlikely |
| `tests/Pest.php`, `.ai/rules/**` | **1**, and say so first — both are shared |

**The pattern per feature is: 1 lands the data model, types and editor; A then consumes it in
the renderer.** `resources/js/types/game.ts` is the handoff — once the new fields are on
`main`, A can build against them.

**Land order now.** A-01, A-02 and A-06 are in. Nothing is mutually blocking any more, so the
two lanes run independently: 1 starts on `1-03`, because `A-03` cannot start until it lands
and A is idle waiting for it; A takes `A-08`, which is unblocked and in a file it already has
open.

**Status values:** `todo`, `wip`, `review`, `landed`, `blocked`.
Keep the branch column honest — it is how work in flight gets found.

**Planning cannot run the suite.** This checkout has no `vendor/` and no `node_modules/`, by
design — it is a planning tree, not a build tree. So nothing here is verified by planning.
`composer ci:check` green in your worktree, plus the CI run on the push to `main`, is the only
evidence the board records.

---

## Agent 1 — data, editor, tests

| ID | Task | Status | Depends on | Notes |
| --- | --- | --- | --- | --- |
| 1-03 | Prop rendering — data model | **wip — start here** | — | **Unblocks A-03, and A is idle waiting on it.** Migration for `render`, `plane_count`, `uv_mode`, `texture_alt`, `alt_flag`, `animation_frames`, `animation_fps`. `LevelAssets::props()` scanning a new `public/sprites/props`. Payload, TS types, validation, writer, `newProp`. **Land the types half as soon as it is green rather than holding it for the editor** — 1-04 is a separate task on purpose. Plan: `plan-prop-rendering.md`. |
| 1-09 | Footsteps — data half | todo | — | The half of `agent1-footsteps` that is yours under ISSUE-16: migration, `LevelSector`, `LevelAssets`, payload, request validation, writer, factory, `types/game.ts`, `inspector.tsx`, `SectorAmbienceTest.php`. Drop `lib/engine/audio.ts`, the `level-viewport.tsx` hook and the `.ai/rules/engine.md` edit from your branch — those are **A-07**. Yours lands first. Rebase before you touch it; the branch is eight commits behind. |
| 1-05 | Slopes — data model | todo | — | **Unblocks A-04.** Four columns on `level_sectors`, PHP `floorAt`/`ceilingAt` on `LevelSector`, validation sampling corners, and hinge survival through `splitEdge`/`weldCorners`/`carveRooms` in `lib/editor/`. Plan: `plan-slopes-and-stats.md` part 1. |
| 1-04 | Prop rendering — editor | todo | 1-03 | Inspector controls conditional on mode; prop texture picker; map-view glyphs for cross and billboard props. |
| 1-06 | Slopes — editor | todo | 1-05 | Inspector hinge picker and rise field; side view drawing the section slanted. |
| 1-01 | Constants drift guard | todo | — | `tests/Unit/ConstantsMatchTest.php`. `tests/Pest.php:59-63` hardcodes `MAX_STEP`, `MIN_HEADROOM`, `CLEARANCE` copied from `constants.ts`, and `LevelAssets::HEIGHTS` is mirrored in `sprite-actor.ts`. Import both under the Node harness and assert they match **both ways**, so adding a seventh person fails loudly. Plan: `plan-test-coverage.md` task 3. |
| 1-02 | Collision tripwire | todo | — | Assert `RUN_SPEED * MAX_FRAME_SECONDS < 2 * PLAYER_RADIUS` with a comment naming what breaks. Plus a circle-vs-12°-wedge test asserting the settled distance is at least `PLAYER_RADIUS`, pinning why `RESOLVE_PASSES` is 12 and not 3. Closes ISSUE-10. Plan: `plan-test-coverage.md` task 4. |
| 1-08 | React component test runner | todo | — | **Decided: Vitest + Testing Library** (ISSUE-6). Adds two dev dependencies — that approval is on the record here, so take it rather than re-asking. Wire it into `composer ci:check` alongside `types:check`, and land one real test on `inspector.tsx` in the same branch so the harness is proven, not just installed. Say so here before you touch `package.json`. |
| 1-07 | Normal-map generator | todo | — | Unblocked: **ISSUE-7 is decided — normal maps are committed to the repo**, not generated at build time. So `docs/tools/make_normals.py` is a one-shot authoring tool whose output is checked in, and it must never overwrite a hand-fixed map in place. Standalone otherwise. Plan: `plan-lighting.md`. |

## Agent A — engine and rendering

| ID | Task | Status | Depends on | Notes |
| --- | --- | --- | --- | --- |
| A-08 | Fix `.ai/rules/engine.md` | **wip — start here** | A-01 | ISSUE-15 is done in `39abe66`. Still open in the same file: **ISSUE-4**, the two sections both titled "Mirror paired sprite directions in UVs" giving different rules for 225°/315° with a third superseding one of them — resolve them into one section that states the rule once, and say so rather than guess if the code does not settle it. And **ISSUE-5**, the `-back` / `-views-sheet` paragraph, which describes deleted files as superseded art — delete it. Plus the new ceiling section, **authorised**: a ceiling is turned over by reversing its winding, not by rotating it, because keeping the polygon in place while flipping its normal is a reflection, and the reflection mirrors the room in z. Give that its full reasoning — the failure it prevents is a whole level mirrored. **A is authorised to edit `engine.md` for all of this**; it is normally 1's. |
| A-07 | Footsteps — engine half | todo | 1-09 | The other half of ISSUE-16: `lib/engine/audio.ts` (492 lines), the `level-viewport.tsx` hook and `FootstepsTest.php`, taken off `agent1-footsteps` (`69389d9`). **Do not land it before 1-09** — it reads fields that do not exist on `main` yet. Reading the branch now to plan against it is fine. The `engine.md` edit on that branch is yours to redo on top of A-08. |
| A-03 | Prop rendering — engine | blocked | 1-03 | Render modes `box`/`billboard`/`cross`, fitted UVs, cutout with `alphaTest`, prop texture loader path, frame animation, alt-state by flag. **Billboards must face whichever camera is drawing** — same trap as the sky dome. Plan: `plan-prop-rendering.md`. |
| A-04 | Slopes — engine | blocked | 1-05 | `floorAt`/`ceilingAt` in `sectors.ts`; `buildFlat` per-vertex displacement; `buildWall` trapezoids with per-end heights; shared-edge step walls; trapezoid portal panes. Plan: `plan-slopes-and-stats.md` part 1. |
| A-05 | Hand poses — wiring | blocked | **ISSUE-1** | `POSES` gains `reach` and `grip`; `DRAWN` gains twelve handedness entries; `hands.update()` takes a focus argument fed from `lookedAtSlug()`. Cannot start until the art exists. Plan: `plan-hand-poses.md`. |

## Parked

**Lighting** (`plan-lighting.md`, baked GI, 5–8 weeks) is not assigned. It touches everything
A owns and should not start until A-03 and A-04 have landed. A-06 was the first step toward it
and is in — the ceiling normals it fixed were a bug lighting would have made visible.

---

## Landed

Audited from the commit history, not self-reported:

| Task | Commit | Notes |
| --- | --- | --- |
| Level policy | `9110b36` | Closed the hole where `authorize()` returned `true` unconditionally |
| `carve.ts` tests | `98fb03d` | `CarveRoomsTest.php` — was the largest untested surface in the project |
| `map.ts` tests | — | `MapEditsTest.php` |
| RPG starting stats | `3a3e797` | `PersonStats.php` + `PersonStatsTest.php`. Plan part 2 done. |
| Editor quick wins | `cb7aa76` | Nudge, duplicate, draggable spawn, readout |
| Carve shard fix | `1963c86`, `ac6ed5c` | |
| Portal fixes | `b11fee6`, `7ed22a3` | |
| Debug wall naming | `5653864` | |
| Anisotropic filtering | `263d12f` | |
| Hand art, walk and run | `dd39848`, `c670dd7` | 12 of 36 cards; normalised and passing the gate |
| A-01 split `build-level.ts` | `0f2cb7c` | 1242 → 88 lines + `lib/engine/build/`. Byte-identical scene output verified by diffing whole built scenes, not just by tests. Added `LevelTopologyTest.php`. |
| Linters ignore `.claude/**` | `3cecabc` | Unblocked `ci:check` for both agents |
| Editor undo/redo | `81924ee` (of `4ba8756`) | `lib/editor/history.ts`, 112 lines — bounded draft history and ⌘Z, 5 tests. Was the unpushed work in ISSUE-17. |
| A-02 extract `prepareReflections` | `910c074` (of `310827e`) | `lib/engine/reflections.ts`, 208 lines. `level-viewport.tsx` 1221 → 1017. `ReflectionsTest.php` — 7 tests over stub panes, with the cameras and bounding spheres real because the frustum test is what decides recursion. Portal demo walked in a browser. Closes ISSUE-9. |
| A-06 ceilings face down | `952576b` (of `39abe66`) | `faceDownwards` in `build/flats.ts` + `FlatNormalsTest.php`. **Not** a rotation: turning a ceiling over by rotating the other way about x is a reflection, and it mirrors the room in z. The triangles are wound the other way and the normals recomputed from the winding, so the polygon does not move. Sky lid turned too. A/B'd in the browser — pixel for pixel unchanged. Closes ISSUE-3. |
| ISSUE-15 engine.md staleness | `952576b` (of `39abe66`) | `drawnByRoom` trap named as gone rather than deleted; `carriedOn` → `build/topology.ts`, `buildWall` → `build/walls.ts`, `deepen()` → `engine/reflections.ts`. `game.md`'s "prepareReflections cannot be tested" line fixed in the same commit. |

`plan-test-coverage.md` tasks 1 and 2 are therefore complete. Tasks 3 and 4 remain as 1-01
and 1-02.

---

## Issues

Add to this list whenever something is raised. Keep the newest at the bottom, do not renumber,
and strike an entry rather than deleting it when it closes.

| ID | Issue | Severity | Owner | Status |
| --- | --- | --- | --- | --- |
| ISSUE-1 | **24 of 36 hand cards missing.** `back`, `back-fist`, `palm`, `palm-fist` for all six people. Two generation rounds have both regenerated `edge`/`edge-open`, which already existed, instead. Blocks A-05. | high | art | open |
| ISSUE-2 | **Hand art is inconsistently scaled between poses.** Fists came back drawn 1.3×–2.5× larger than open hands, for every person. The normaliser corrects it but throws away drawn resolution. Spec updated in `handoff-hand-art.md`; needs the generator to comply. | medium | art | open |
| ISSUE-3 | **Ceiling surface normals point up**, same as floors. Invisible while unlit and double-sided; every ceiling would light as a floor. | medium | A | **closed** `952576b` |
| ISSUE-4 | **`.ai/rules/engine.md` contradicts itself.** Two sections both titled "Mirror paired sprite directions in UVs" give different rules for 225°/315°, and a third supersedes one of them. An agent reading top to bottom gets the wrong answer twice. | medium | A | open → A-08 |
| ISSUE-5 | **`.ai/rules/engine.md` mis-describes the hand art.** It lists `-back` and `-views-sheet` as superseded when they were the newer art; all of those files have since been deleted, so the section is now stale in a second way. | low | A | open → A-08 |
| ISSUE-6 | **No React component test runner exists.** `inspector.tsx` (1156), `map-view.tsx` and `side-view.tsx` have no coverage and nothing to write it with. | medium | 1 | **decided — Vitest + Testing Library, → 1-08** |
| ISSUE-7 | **Undecided: normal maps committed or generated at build time.** `public/sprites/textures` is already 17 MB and normal maps compress worse. | low | — | **decided — committed. No build step, and hand-fixes survive. 1-07 unblocked** |
| ISSUE-8 | **`LevelWriter` deletes and recreates everything on save**, so ids churn and nothing can hold a durable reference to a wall or a room. Not urgent — but anything that wants to reference an edge by id hits this first. | low | 1 | open |
| ISSUE-9 | **`prepareReflections` cannot be tested** — unexported inside a `.tsx` the Node harness cannot load. | medium | A | **closed** `910c074` |
| ISSUE-10 | **No swept collision test.** Collision relies on `RUN_SPEED * MAX_FRAME_SECONDS < 2 * PLAYER_RADIUS` and nothing enforces it, so a future speed tweak silently lets the player walk through walls. | medium | 1 | open → 1-02 |
| ISSUE-11 | **Files over 1100 lines.** `build-level.ts` came off this list at A-01, and `level-viewport.tsx` is down to 1017 after A-02. Still standing: `inspector.tsx` (1156). See ISSUE-19. | medium | 1 / A | partly closed |
| ISSUE-12 | **Nothing tests what renders.** Every engine test asserts on structure; the portal, mirror and sky-lid rules describe failures whose only symptom is on screen. Those stay eye-verified. | low | — | accepted |
| ISSUE-13 | **A-01 was built twice.** Ruling was: main keeps A's `33b4b51`, agent 1 drops `968ab93`. Done — `origin/agent1` now has no diff against `main`. `origin/agent1-split-build-level` is the abandoned branch and should be deleted so nobody picks it up. | high | 1 | **resolved — delete the branch** |
| ISSUE-14 | **Linters walked the agent worktrees.** `ci:check` returned 591,586 errors, blocking both agents. Fixed on main in `3cecabc`; agent 1's duplicate `349a3e1` is merged away harmlessly. | high | — | **closed** `3cecabc` |
| ISSUE-15 | **`.ai/rules/engine.md` is stale after the split.** The `drawnByRoom` temporal-dead-zone note describes a trap that no longer exists; `carriedOn` and `buildWall` moved. | low | A | **closed** `952576b` |
| ISSUE-16 | **Cross-lane work on `agent1-footsteps` (`69389d9`).** Adds `lib/engine/audio.ts` and hooks `level-viewport.tsx`, both agent A's paths, and edited `.ai/rules/engine.md` without notice. **Ruling: split it** — 1 keeps the data half, A takes the engine half. Now tracked as **1-09** and **A-07**; neither half lands alone, and 1-09 goes first. The branch is eight commits behind `main`. | medium | 1 / A | open → 1-09, A-07 |
| ISSUE-17 | **Unmerged work sitting on agent 1's local branches.** `agent1-undo-redo` has since landed as `81924ee`. What is left unmerged is `agent1-footsteps`, which is ISSUE-16, and `agent1-split-build-level`, which is ISSUE-13. | medium | 1 | **closed** |
| ISSUE-18 | **Both agents were mid-flight when this board was rewritten**, the same mistake that caused ISSUE-13. If either of you is already inside a task this board now assigns differently, say so before rebasing — do not silently drop or duplicate what you have. | medium | — | open |
| ISSUE-19 | **`level-viewport.tsx` is the last big contention file in A's lane** — 1017 lines, and both A-03 and A-07 grow it again. Splitting it before they land is cheaper than after, and A-01 is the recipe. A has been asked whether it has real seams; if it does not, this closes rather than becoming a carve for its own sake. | medium | A | open — scoping |

---

## Working agreement

- Rebase on `main` before opening work; land small and often.
- Do not edit a path you do not own without saying so here first. If a task genuinely needs
  to, split it and hand the other half over.
- A feature is done when its plan's verification section passes, not when the code compiles.
  `composer ci:check` green — that runs eslint, prettier, phpstan and the Pest suite.
- Raise anything unexpected as an ISSUE here rather than fixing it silently in an unrelated
  branch — a surprise fix in someone else's file is how the merge goes wrong.
- **If your own change makes a line in a rules file false, you own that line**, whatever lane
  the file is in. Fix it in the same commit and say so after. A rules file asserting something
  untrue is worse than one pointing somewhere stale. Anything beyond that — a new rule, a
  rewrite, a rule you merely disagree with — comes to planning first.
- Planning reads `main` and rewrites this file there. Do not edit `docs/tasks.md` yourself —
  tell planning what you need recorded, or say it in your merge commit, and it gets folded in.
