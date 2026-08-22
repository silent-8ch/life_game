# Task board

Two agents, **1** and **A**, each committing to their own branch (`agent1`, `agentA`) and
merging to `main`. Agent 1 is session `life-game-22`; agent A is `life-game2-78`. Planning is
`live-game-planning-89`, which owns this file and lands it on `main` — that is how the board
reaches both of you. This file is the shared state: who owns what, what is in flight, what is
done, and what is broken.

Last audited against the repo at `7216cc4`.

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

**Sound is parked — stop work on it.** The user's call, and it lands on both of you: 1-09 and
A-07 are off the board, and nothing on `agent1-footsteps` (`69389d9`) gets rebased, split or
merged. If you have started either half, stop where you are and leave it on the branch — it is
parked, not abandoned, and the branch is the only copy. See the Parked section.

**Land order now.** A-01, A-02, A-06 and A-08 are in — that is everything in A's lane that did
not need agent 1 first. **A-09 is a go**, so A is no longer idle: A-10 first (one line), then the `scanRow` harness,
then the cuts. `1-03` is still the only thing with somebody waiting behind it — A-03 cannot
start until its types are on `main` — so 1 stays there and lands the types half on its own
rather than holding it for the editor.

**Status values:** `todo`, `wip`, `review`, `landed`, `blocked`.
Keep the branch column honest — it is how work in flight gets found.

**Planning can now run the suite.** The user installed `vendor/` and `node_modules/` here, so
this tree builds. That changes what the board is: green in your worktree is still what you owe
before merging, but it is no longer the only evidence — `composer ci:check` is run against
`main` here too, and what is written below is what it returned, not what was reported. If your
branch is green and `main` is not, that difference is the interesting thing and it goes on this
board.

**`main` at `7216cc4` is green, verified here.** Measured at `fb7acb2`; nothing since has
touched code, only `.ai/rules/engine.md`. eslint, prettier, tsc, pint and phpstan
all pass; Pest is **299 of 299, 2250 assertions, 33s**. That is the whole gate, run against
`main` rather than against a branch.

---

## Agent 1 — data, editor, tests

| ID | Task | Status | Depends on | Notes |
| --- | --- | --- | --- | --- |
| 1-03 | Prop rendering — data model | **wip** | — | **Unblocks A-03, and A is idle waiting on it.** Migration for `render`, `plane_count`, `uv_mode`, `texture_alt`, `alt_flag`, `animation_frames`, `animation_fps`. `LevelAssets::props()` scanning a new `public/sprites/props`. Payload, TS types, validation, writer, `newProp`. **Land the types half as soon as it is green rather than holding it for the editor** — 1-04 is a separate task on purpose. Plan: `plan-prop-rendering.md`. |
| 1-05 | Slopes — data model | todo | — | **Unblocks A-04.** Four columns on `level_sectors`, PHP `floorAt`/`ceilingAt` on `LevelSector`, validation sampling corners, and hinge survival through `splitEdge`/`weldCorners`/`carveRooms` in `lib/editor/`. Plan: `plan-slopes-and-stats.md` part 1. |
| 1-04 | Prop rendering — editor | todo | 1-03 | Inspector controls conditional on mode; prop texture picker; map-view glyphs for cross and billboard props. |
| 1-06 | Slopes — editor | todo | 1-05 | Inspector hinge picker and rise field; side view drawing the section slanted. |
| 1-08 | React component test runner | todo | — | **Decided: Vitest + Testing Library** (ISSUE-6). Adds two dev dependencies — that approval is on the record here, so take it rather than re-asking. Wire it into `composer ci:check` alongside `types:check`, and land one real test on `inspector.tsx` in the same branch so the harness is proven, not just installed. Say so here before you touch `package.json`. |
| 1-07 | Normal-map generator | todo | — | Unblocked: **ISSUE-7 is decided — normal maps are committed to the repo**, not generated at build time. So `docs/tools/make_normals.py` is a one-shot authoring tool whose output is checked in, and it must never overwrite a hand-fixed map in place. Standalone otherwise. Plan: `plan-lighting.md`. |

## Agent A — engine and rendering

| ID | Task | Status | Depends on | Notes |
| --- | --- | --- | --- | --- |
| A-03 | Prop rendering — engine | blocked | 1-03 | Render modes `box`/`billboard`/`cross`, fitted UVs, cutout with `alphaTest`, prop texture loader path, frame animation, alt-state by flag. **Billboards must face whichever camera is drawing** — same trap as the sky dome. Plan: `plan-prop-rendering.md`. |
| A-04 | Slopes — engine | blocked | 1-05 | `floorAt`/`ceilingAt` in `sectors.ts`; `buildFlat` per-vertex displacement; `buildWall` trapezoids with per-end heights; shared-edge step walls; trapezoid portal panes. Plan: `plan-slopes-and-stats.md` part 1. |
| A-09 | Split `level-viewport.tsx` | **go — start here, harness first** | — | It splits, and more cleanly than `build-level.ts` did: 1017 lines of which **878 are one `useEffect`**. Seams, in A's cutting order 3 → 1 → 5 → 4 → 2, smallest risk first: `view.ts` (scene/camera/renderer/fog and `reach`, ~95); `player.ts` (spawn, movement, collision, portal crossing, camera placement and the `updateMatrixWorld` mirrors depend on, ~245 — the valuable one, and four engine rules describe its ordering with nothing pinning them); the `?debug` probe wiring (~85); `snapshot-post.ts` (~90, the only piece that touches the network); `input.ts` (~200, pure DOM, all noise no risk). ~200 lines left in the .tsx, which is what a component should be. **Obstacle:** everything closes over shared mutable state, same as `buildLevel` — one session object made up front is the answer — and `createTouchControls` is built after `step()` uses it while its callbacks call `stop()`/`takeInHand()` declared above. That knot gets broken deliberately. **Verification, and this is the condition:** A-01 was safe because the whole built scene could be diffed; this has no equivalent, and the suite touches none of it. `?at=` makes a spot reproducible, `?debug` paints every wall a legend colour, and `window.scanRow(row)` returns the runs of surfaces across a row of the real frame as JSON. A dozen fixed spots across the three levels, several rows each, before and after, diffed — built **before** any cut and run between each. **The user has said go, and said go to the harness as the condition, not as a nicety.** Build it first, run it between every cut, and land it as something the project keeps — it is the readback this codebase has never had, and it outlives the split. |
| A-05 | Hand poses — wiring | blocked | **ISSUE-1** | `POSES` gains `reach` and `grip`; `DRAWN` gains twelve handedness entries; `hands.update()` takes a focus argument fed from `lookedAtSlug()`. Cannot start until the art exists. Plan: `plan-hand-poses.md`. |

## Parked

**Sound** — `agent1-footsteps` (`69389d9`), 1-09 and A-07. Parked by the user. The branch holds
footstep and ambience work: a migration, `lib/engine/audio.ts` (492 lines), the viewport hook,
inspector controls and two test files. Nothing on it lands. ISSUE-16, which split it across the
two lanes, is parked with it and does not need settling while it stays there. Agent A had taken the engine half onto
`origin/agentA-footsteps` (`97883f2`) before the park reached them; that branch is parked too.
**Do not delete either branch** — between them they are the only copy of the work.

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
| A-08 rules-file rot in engine.md | `ea5ad72` (of `8b88078`) | ISSUE-4 and ISSUE-5. The three mirroring sections did not merely contradict each other — **all three described a rule that does not exist**. Read off `ORDERS` in `sprite-direction.ts`: eight drawings per person not five, four of the six use no mirroring at all, nobody's 270° is mirrored, and the flips that exist are there because a sheet has no drawing for that angle. One section now, from the code, naming `SpriteDirectionTest.php`. Hand-art paragraph replaced with what is actually in `public/sprites/hands`. Ceiling rule from A-06 written out in full. Verified against the code, not taken on report. |
| 1-01 constants drift guard | `fb7acb2` | `ConstantsMatchTest.php`, 114 lines. Asserts both ways, so a seventh person fails loudly rather than quietly. |
| 1-02 collision tripwire | `fb7acb2` | `CollisionLimitsTest.php`, 151 lines. Closes ISSUE-10. Found two errors in the plan it was written from — see ISSUE-22 — and the true wedge clearance figures, which are ISSUE-21. |
| A-10 the false clearance figure | `594d337` (of `d9ffc67`) | ISSUE-21. Went further than asked and was right to: rather than correcting 0.28 m to 0.154 m, it takes **any** figure out of the file, because there is no constant to state. The clearance falls away with the angle of the corner and depends on how the player arrives — wedges wider than about 60° settle at a full `PLAYER_RADIUS`, sharper than that it is less, and in a very sharp wedge there is nowhere within reach to put a circle a radius from both walls (at 3° it would stand thirteen metres back), so the solver pushes the player out through the corner instead of settling them. The numbers live in the sweep in `CollisionLimitsTest.php`, which fails when they move. |
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
| ISSUE-4 | **`.ai/rules/engine.md` contradicts itself** on sprite mirroring — and all three versions were wrong, not just two of them. There is no one rule; there is a table per person. | medium | A | **closed** `ea5ad72` |
| ISSUE-5 | **`.ai/rules/engine.md` mis-describes the hand art.** It lists `-back` and `-views-sheet` as superseded when they were the newer art; all of those files have since been deleted. | low | A | **closed** `ea5ad72` |
| ISSUE-6 | **No React component test runner exists.** `inspector.tsx` (1156), `map-view.tsx` and `side-view.tsx` have no coverage and nothing to write it with. | medium | 1 | **decided — Vitest + Testing Library, → 1-08** |
| ISSUE-7 | **Undecided: normal maps committed or generated at build time.** `public/sprites/textures` is already 17 MB and normal maps compress worse. | low | — | **decided — committed. No build step, and hand-fixes survive. 1-07 unblocked** |
| ISSUE-8 | **`LevelWriter` deletes and recreates everything on save**, so ids churn and nothing can hold a durable reference to a wall or a room. Not urgent — but anything that wants to reference an edge by id hits this first. | low | 1 | open |
| ISSUE-9 | **`prepareReflections` cannot be tested** — unexported inside a `.tsx` the Node harness cannot load. | medium | A | **closed** `910c074` |
| ISSUE-10 | **No swept collision test.** Collision relied on `RUN_SPEED * MAX_FRAME_SECONDS < 2 * PLAYER_RADIUS` with nothing enforcing it, so a speed tweak could silently let the player walk through walls. | medium | 1 | **closed** `fb7acb2` |
| ISSUE-11 | **Files over 1100 lines.** `build-level.ts` came off this list at A-01, and `level-viewport.tsx` is down to 1017 after A-02. Still standing: `inspector.tsx` (1156). See ISSUE-19. | medium | 1 / A | partly closed |
| ISSUE-12 | **Nothing tests what renders.** Every engine test asserts on structure; the portal, mirror and sky-lid rules describe failures whose only symptom is on screen. Those stay eye-verified. | low | — | accepted |
| ISSUE-13 | **A-01 was built twice.** Ruling was: main keeps A's `33b4b51`, agent 1 drops `968ab93`. Done — `origin/agent1` has no diff against `main`. I asked agent 1 to delete `origin/agent1-split-build-level` and they refused, correctly: deleting the only copy of a verified refactor is the user's call, not a coordinator's. **Put to the user, who says keep it.** The branch stays; what matters is that it never merges, and that is committed to here. | high | 1 | **closed — branch kept deliberately** |
| ISSUE-14 | **Linters walked the agent worktrees.** `ci:check` returned 591,586 errors, blocking both agents. Fixed on main in `3cecabc`; agent 1's duplicate `349a3e1` is merged away harmlessly. | high | — | **closed** `3cecabc` |
| ISSUE-15 | **`.ai/rules/engine.md` is stale after the split.** The `drawnByRoom` temporal-dead-zone note describes a trap that no longer exists; `carriedOn` and `buildWall` moved. | low | A | **closed** `952576b` |
| ISSUE-16 | **Cross-lane work on `agent1-footsteps` (`69389d9`).** Adds `lib/engine/audio.ts` and hooks `level-viewport.tsx`, both agent A's paths. The ruling was to split it into 1-09 and A-07. | medium | 1 / A | **parked** — sound is off the board by the user's call. The split stands if it ever comes back; nothing to do meanwhile. Leave the branch alone. |
| ISSUE-17 | **Unmerged work sitting on agent 1's local branches.** `agent1-undo-redo` has since landed as `81924ee`. What is left unmerged is `agent1-footsteps`, which is ISSUE-16, and `agent1-split-build-level`, which is ISSUE-13. | medium | 1 | **closed** |
| ISSUE-18 | **Both agents were mid-flight when this board was rewritten**, the same mistake that caused ISSUE-13. | medium | — | **closed** — both reported in. A had only A-08, landed. 1 had 1-01 and 1-02, done and pushed. Nothing was dropped or duplicated. |
| ISSUE-19 | **`level-viewport.tsx` is the last big contention file in A's lane** — 1017 lines, 878 of them in one `useEffect`. A scoped it: the seams are real and cleaner than `build-level.ts`'s were. | medium | A | **go — → A-09**, harness first |
| ISSUE-25 | **Two rules-file entries in a row have been false rather than stale** — the sprite mirroring sections (ISSUE-4) and the clearance figure (ISSUE-21). Both were confidently written, both were quoted back as guarantees, and both were caught only because somebody measured while doing something else. Stale pointers announce themselves; false statements do not. The pattern to copy is the one both fixes landed on: **name the test, not the number.** Worth a pass over the rest of `.ai/rules/**` for figures written as prose, but not now and not ahead of A-09. | medium | — | open — watching |
| ISSUE-24 | **Nothing can read back what the engine actually draws** — ISSUE-12 accepted that and this is the first thing to challenge it. `?at=` makes a spot reproducible, `?debug` paints every wall a legend colour, and `window.scanRow(row)` returns the runs of surfaces across a row of the real frame as JSON. A-09 needs a dozen fixed spots diffed before and after, which means building that into a harness — and the harness is worth more than the split it is being built for. Land it as something the project keeps. | medium | A | open → A-09 |
| ISSUE-21 | **`.ai/rules/engine.md` stated a false collision clearance** — 0.28 m written as a floor, where one measurement of one approach was all it ever was. Found by 1 doing 1-02, left alone because the file is A's, fixed by A. The lasting form of the answer is that no such number belongs in prose at all. | medium | A | **closed** `594d337` |
| ISSUE-22 | **`plan-test-coverage.md` task 3 is wrong about `CLEARANCE`, and its task 4 test does not work as described.** `constants.ts` has no `CLEARANCE`: Pest's `CLEARANCE = 0.4` is a test-local judgement about how much room counts as clear, and the engine's is in `portals.ts` at `0.02`, meaning the nudge that lands a body inside the far room after a crossing. Same name, unrelated. Separately, the wedge test as the plan describes it **passes at `RESOLVE_PASSES = 3` and silently tests nothing** — a single shot at the point of a wedge is pushed back out and settles in one pass. It only bites when swept across partial moves that stop the player inside. Both found while doing 1-01/1-02 and worked around correctly; the plan file still says the wrong thing. | low | 1 | open |
| ISSUE-23 | **Something ran `npm` inside the planning checkout** and rewrote `package-lock.json`'s `name` field from `life_game2` to the directory name. Reverted. Both agents now work in their own worktrees, which is the fix; noted so that a stray lockfile diff on `main` is recognised rather than committed. | low | — | closed |
| ISSUE-20 | **A new person silently inherits Paul's sprite order.** `UNCHECKED = ORDERS.paul` in `sprite-direction.ts` is the fallback for anyone not listed, and the six sheets were each drawn to their own order — Paul's diagonals run backwards against Wade's, Krystal's cardinals against Paul's. So the fallback is wrong about as often as it is right, and wrong looks like a person facing the wrong way in two of eight directions rather than like a crash. Surfaced by A-08. Not urgent while the cast is six; the trap is that adding the seventh is the moment nobody is thinking about it. Check new sheets with `public/sprite-directions.html`. | low | A | open |

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
