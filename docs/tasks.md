# Task board

Two agents, **1** and **A**, each committing to their own branch (`agent1`, `agentA`) and
merging to `main`. Agent 1 is session `life-game-22`; agent A is `life-game2-78`. This file is the shared state: who owns what, what is in flight, what is
done, and what is broken.

Last audited against the repo at `cf87e8a`, when both branches were level with `main` and the
tree was clean.

---

## How this works

**Ownership is by path, not by task.** The two agents collide on files, not on features, so
the split below is designed to keep them out of each other's way. Three files are the real
contention risk — `build-level.ts` (1242 lines), `level-viewport.tsx` (1221) and
`inspector.tsx` (1156) — and every remaining feature touches at least one of them.

| Path | Owner |
| --- | --- |
| `app/**`, `database/**` | **1** |
| `resources/js/types/**` | **1** — this is the contract between them |
| `resources/js/components/editor/**`, `resources/js/lib/editor/**` | **1** |
| `resources/js/lib/engine/**` | **A** |
| `resources/js/components/game/**` | **A** |
| `public/sprites/**` | **A** |
| `docs/**`, `docs/tools/**` | **1** |
| `tests/**` | whoever wrote the code — one new file per feature, so conflicts are unlikely |
| `tests/Pest.php`, `.ai/rules/**` | **1**, and say so first — both are shared |

**The pattern per feature is: 1 lands the data model, types and editor; A then consumes it in
the renderer.** `resources/js/types/game.ts` is the handoff — once the new fields are on
`main`, A can build against them.

**Land order.** `A-01` goes first and everyone rebases onto it. It splits `build-level.ts`,
and until it lands, slopes and props cannot be worked in parallel at all.

**Status values:** `todo`, `wip`, `review`, `landed`, `blocked`.
Keep the branch column honest — it is how work in flight gets found.

---

## Agent 1 — data, editor, tests

| ID | Task | Status | Depends on | Notes |
| --- | --- | --- | --- | --- |
| 1-01 | Constants drift guard | todo | — | `tests/Unit/ConstantsMatchTest.php`. `tests/Pest.php:59-63` hardcodes `MAX_STEP`, `MIN_HEADROOM`, `CLEARANCE` copied from `constants.ts`, and `LevelAssets::HEIGHTS` is mirrored in `sprite-actor.ts`. Import both under the Node harness and assert they match **both ways**, so adding a seventh person fails loudly. Plan: `plan-test-coverage.md` task 3. |
| 1-02 | Collision tripwire | todo | — | Assert `RUN_SPEED * MAX_FRAME_SECONDS < 2 * PLAYER_RADIUS` with a comment naming what breaks. Plus a circle-vs-12°-wedge test asserting the settled distance is at least `PLAYER_RADIUS`, pinning why `RESOLVE_PASSES` is 12 and not 3. Plan: `plan-test-coverage.md` task 4. |
| 1-03 | Prop rendering — data model | todo | — | **Unblocks A-03.** Migration for `render`, `plane_count`, `uv_mode`, `texture_alt`, `alt_flag`, `animation_frames`, `animation_fps`. `LevelAssets::props()` scanning a new `public/sprites/props`. Payload, TS types, validation, writer, `newProp`. Plan: `plan-prop-rendering.md`. |
| 1-04 | Prop rendering — editor | todo | 1-03 | Inspector controls conditional on mode; prop texture picker; map-view glyphs for cross and billboard props. |
| 1-05 | Slopes — data model | todo | — | **Unblocks A-04.** Four columns on `level_sectors`, PHP `floorAt`/`ceilingAt` on `LevelSector`, validation sampling corners, and hinge survival through `splitEdge`/`weldCorners`/`carveRooms` in `lib/editor/`. Plan: `plan-slopes-and-stats.md` part 1. |
| 1-06 | Slopes — editor | todo | 1-05 | Inspector hinge picker and rise field; side view drawing the section slanted. |
| 1-07 | Normal-map generator | todo | — | `docs/tools/make_normals.py`. Standalone, touches nothing else. Blocked on **ISSUE-7** being decided first. Plan: `plan-lighting.md`. |

## Agent A — engine and rendering

| ID | Task | Status | Depends on | Notes |
| --- | --- | --- | --- | --- |
| A-01 | Split `build-level.ts` | **landed** | — | `0f2cb7c` (merge of `33b4b51`). 1242 → 88 lines plus `lib/engine/build/`. Both agents built this independently; see **ISSUE-13**. |
| A-02 | Extract `prepareReflections` | todo | A-01 | Move it out of `level-viewport.tsx` into `lib/engine/reflections.ts` so the Node harness can load it, then pin release-before-render ordering, the `onto`-room filter, sky hidden at the deepest pass, and `show(0)` before the player's `hug()`. Closes **ISSUE-9**. Plan: `plan-test-coverage.md` task 2. |
| A-03 | Prop rendering — engine | todo | A-01, 1-03 | Render modes `box`/`billboard`/`cross`, fitted UVs, cutout with `alphaTest`, prop texture loader path, frame animation, alt-state by flag. **Billboards must face whichever camera is drawing** — same trap as the sky dome. Plan: `plan-prop-rendering.md`. |
| A-04 | Slopes — engine | todo | A-01, 1-05 | `floorAt`/`ceilingAt` in `sectors.ts`; `buildFlat` per-vertex displacement; `buildWall` trapezoids with per-end heights; shared-edge step walls; trapezoid portal panes. Plan: `plan-slopes-and-stats.md` part 1. |
| A-05 | Hand poses — wiring | blocked | **ISSUE-1** | `POSES` gains `reach` and `grip`; `DRAWN` gains twelve handedness entries; `hands.update()` takes a focus argument fed from `lookedAtSlug()`. Cannot start until the art exists. Plan: `plan-hand-poses.md`. |
| A-06 | Ceiling normals | todo | A-01 | `buildFlat` applies `rotation.x = -π/2` to floors *and* ceilings, so a ceiling's normal points up. Harmless while unlit, fatal under lighting. Cheap to fix now and worth doing before it is load-bearing. Closes **ISSUE-3**. |

## Parked

**Lighting** (`plan-lighting.md`, baked GI, 5–8 weeks) is not assigned. It touches everything
A owns and should not start until A-01, A-03, A-04 and A-06 have landed. ISSUE-7 needs
deciding before 1-07 is worth doing.

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

`plan-test-coverage.md` task 1 is therefore complete. Tasks 2, 3 and 4 remain as A-02, 1-01
and 1-02.

---

## Issues

Add to this list whenever something is raised. Keep the newest at the bottom, do not renumber,
and strike an entry rather than deleting it when it closes.

| ID | Issue | Severity | Owner | Status |
| --- | --- | --- | --- | --- |
| ISSUE-1 | **24 of 36 hand cards missing.** `back`, `back-fist`, `palm`, `palm-fist` for all six people. Two generation rounds have both regenerated `edge`/`edge-open`, which already existed, instead. Blocks A-05. | high | art | open |
| ISSUE-2 | **Hand art is inconsistently scaled between poses.** Fists came back drawn 1.3×–2.5× larger than open hands, for every person. The normaliser corrects it but throws away drawn resolution. Spec updated in `handoff-hand-art.md`; needs the generator to comply. | medium | art | open |
| ISSUE-3 | **Ceiling surface normals point up**, same as floors — `buildFlat` uses one rotation for both. Invisible while unlit and double-sided; every ceiling would light as a floor. | medium | A | open → A-06 |
| ISSUE-4 | **`.ai/rules/engine.md` contradicts itself.** Two sections both titled "Mirror paired sprite directions in UVs" give different rules for 225°/315°, and a third supersedes one of them. An agent reading top to bottom gets the wrong answer twice. | medium | 1 | open |
| ISSUE-5 | **`.ai/rules/engine.md` mis-describes the hand art.** It lists `-back` and `-views-sheet` as superseded when they were the newer art; all of those files have since been deleted, so the section is now stale in a second way. | low | 1 | open |
| ISSUE-6 | **No React component test runner exists.** `inspector.tsx` (1156), `map-view.tsx` and `side-view.tsx` have no coverage and nothing to write it with. Adding one is a decision, not a task. | medium | — | open |
| ISSUE-7 | **Undecided: normal maps committed or generated at build time.** `public/sprites/textures` is already 17 MB and normal maps compress worse. Committing means no build step and hand-fixes survive; generating means a smaller repo but overwrites hand-fixes. Blocks 1-07. | low | — | **needs a decision** |
| ISSUE-8 | **`LevelWriter` deletes and recreates everything on save**, so ids churn and nothing can hold a durable reference to a wall or a room. Not urgent — but anything that wants to reference an edge by id hits this first. | low | 1 | open |
| ISSUE-9 | **`prepareReflections` cannot be tested** — unexported inside a `.tsx` the Node harness cannot load. The least-tested, most load-bearing code in the project. | medium | A | open → A-02 |
| ISSUE-10 | **No swept collision test.** Collision relies on `RUN_SPEED * MAX_FRAME_SECONDS < 2 * PLAYER_RADIUS` and nothing enforces it, so a future speed tweak silently lets the player walk through walls. | medium | 1 | open → 1-02 |
| ISSUE-11 | **Files over 1100 lines.** `build-level.ts` is off this list — 88 lines after A-01. Still standing: `level-viewport.tsx` (1221) and `inspector.tsx` (1156), both still contention points. | medium | A / 1 | partly closed |
| ISSUE-12 | **Nothing tests what renders.** Every engine test asserts on structure; the portal, mirror and sky-lid rules describe failures whose only symptom is on screen. Those stay eye-verified. | low | — | accepted |
| ISSUE-13 | **A-01 was built twice.** Agent A landed `33b4b51` (merged `0f2cb7c`, into `lib/engine/build/`); agent 1 had already built `968ab93` on `agent1-split-build-level` (into flat `build-*.ts`). Both verified byte-identical scene output. **Ruling: main keeps A's. Agent 1 drops `968ab93`** — not on quality, purely because main has moved and re-landing a competing refactor of the same 1242 lines is a merge nobody should attempt. Root cause: the board was written after work had already started. | high | 1 | **resolved — agent 1 to drop** |
| ISSUE-14 | **Linters walked the agent worktrees.** `.claude/` holds ~1 GB of whole project copies; git skips it via the global ignore file, which eslint and prettier do not read. `ci:check` returned 591,586 errors, blocking both agents. Fixed on main in `3cecabc`. Agent 1 had the same fix unpushed as `349a3e1` — drop it. | high | — | **closed** `3cecabc` |
| ISSUE-15 | **`.ai/rules/engine.md` is stale after the split.** The note about `drawnByRoom` needing to be declared above `buildWall`/`buildFlat` describes a temporal-dead-zone trap that no longer exists — it is a scene field now, created before any builder runs. Two other notes point at `build-level.ts` for `carriedOn` (now `build/topology.ts`) and `buildWall` (now `build/walls.ts`); both still true, just relocated. **Agent A is authorised to edit `engine.md` for this** — it knows exactly what moved. | low | A | open |
| ISSUE-16 | **Cross-lane work on `agent1-footsteps` (`69389d9`).** Adds `lib/engine/audio.ts` and hooks `level-viewport.tsx`, both agent A's paths, and edited `.ai/rules/engine.md` without notice. **Ruling: split it** — agent 1 keeps the data model, payload, validation and inspector half; agent A takes the engine module and the viewport hook. Neither half lands alone. | medium | 1 / A | open |
| ISSUE-17 | **Unmerged work sitting on agent 1's local branches.** `agent1-undo-redo` (`4ba8756`, `lib/editor/history.ts`, bounded draft history + ⌘Z, 5 tests) is done and unpushed. Nothing on `origin/agent1` is ahead of main, so none of agent 1's four branches exist remotely. Push or lose them. | medium | 1 | open |

---

## Working agreement

- Rebase on `main` before opening work; land small and often. A-01 in particular should land
  on its own, ahead of everything else.
- Do not edit a path you do not own without saying so here first. If a task genuinely needs
  to, split it and hand the other half over.
- A feature is done when its plan's verification section passes, not when the code compiles.
  `composer test` and `npm run types:check` both green.
- Raise anything unexpected as an ISSUE here rather than fixing it silently in an unrelated
  branch — a surprise fix in someone else's file is how the merge goes wrong.
