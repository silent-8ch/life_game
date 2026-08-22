# Prop rendering: fitted UVs, cutout sprites, cross planes and states

## Context

`ThingKind` already has `Door`, `Window` and `Fixture` (`app/Enums/ThingKind.php`) — and none
of them do anything. Every thing that is not an actor renders the same way
(`build-level.ts:1009-1050`): a `BoxGeometry` of the thing's width/height/depth, wrapped in a
`MeshBasicMaterial` with one of the 65 tiling surface textures, UVs scaled by
`tileUvs(box, Math.max(width, depth), height)`.

That has three consequences that block the art:

1. **Tiling crops a single object.** `TEXTURE_METRES` is 2, so a 0.9 m wide door shows 45% of
   its own image. Any door, TV or light switch authored today renders cropped.
2. **There is no cutout path.** Props are opaque boxes. A plant, a chair or anything with a
   silhouette cannot be drawn — only `sprite-actor.ts` does alpha cutout, and only for people.
3. **There is no state.** A switch cannot be on and off, glass cannot be broken, a TV cannot
   animate. The only animated thing in the engine is water.

This plan adds the render modes the prop art needs. It deliberately does **not** make doors
open — that is a separate item in `docs/next-directions.md`.

Decisions taken: a `render` mode per thing with 2- or 3-plane cross sprites; one file per
state, named by suffix; fitted UVs for props with tiling kept for surfaces; a starter set of
about 40 sprites, briefed in `docs/handoff-prop-sprites.md`.

Rough size: **~1 week.**

---

## Where the art lives

New folder `public/sprites/props/`, and a new `LevelAssets::props()` scanning it the way
`textures()` scans `sprites/textures` (`LevelAssets.php:64`).

Keep them apart. They differ in kind — surface textures are opaque, square, seamlessly
tiling; props carry alpha, have real aspect ratios and never tile — and mixing them puts
forty doors and pot plants in the wall-texture dropdown. The editor gets a separate picker.

`textures.ts` needs a third loader path alongside `retro()`. Props want
`ClampToEdgeWrapping` (they never repeat, and wrapping shows as a one-pixel band of the
opposite edge along every silhouette), and they keep `NearestFilter` for magnification to
match the house look.

---

## Render modes

`level_things` gains `render`, an enum of `box` | `billboard` | `cross`, default `box`.

### `box` — as today, with a UV mode

`uv_mode` enum `tile` | `fit`, **database default `tile`** so no seeded level changes
appearance, but `newProp` in `lib/editor/map.ts` sets `fit` so everything authored from now on
is right by default. `fit` writes UVs of 0..1 across each face, so the image covers it exactly
once.

Long props are why `fit` is not simply forced everywhere: a six-metre kitchen counter or a
fence run wants its texture repeated, not stretched.

### `billboard` — one quad, turned to face the viewer

A single `PlaneGeometry`, alpha-cut, rotated about Y only (never pitched — a prop leaning back
as you look up reads as a bug).

**It must face whichever camera is drawing, not the player.** `.ai/rules/game.md` records
exactly this trap for the sky dome: the pane passes run with cameras somewhere else entirely,
and anything parked facing the player appears wrong in every portal and mirror view. Billboard
props are updated inside `drawPane` alongside `sky.follow`, and put back for the main render.

### `cross` — two or three quads, locked to the thing's angle

`plane_count`, unsigned tiny int, 2 or 3. Planes are evenly spaced about Y — 2 gives 0°/90°,
3 gives 0°/60°/120° — and offset by the thing's existing `angle`. Nothing rotates at runtime,
so cross props are ordinary static geometry and cost nothing extra in the 40 portal passes.
This is the classic foliage trick and it is why a plant reads acceptably from any direction
without billboarding.

A plant that wants a cross stalk and a billboarding head is authored as **two things** stacked
by `elevation`, not as one composite. Keeping a thing to one mode keeps the save path flat.

### Cutout, not blending

Every non-box mode uses `transparent: false` with `alphaTest: 0.5`, exactly as
`sprite-actor.ts:121-127` already does. Alpha *testing* writes depth and needs no sorting;
alpha *blending* would need every prop sorted against every other one and against the portal
panes, which is a class of bug not worth inviting. `side: THREE.DoubleSide`, because both
modes are seen from behind.

---

## States and animation

### Two states, driven by a flag

`level_things` gains `texture_alt` (string, nullable) and `alt_flag` (string, nullable). When
`alt_flag` names a game flag that is set, the thing draws `texture_alt` instead of `texture`.

This needs **no new effect type**. `EffectType::SetFlag` and `ConditionType::FlagIs` already
exist and already round-trip through `EffectApplier` and the editor's interaction panel. A
light switch is a thing with `texture` = `light-switch-off`, `texture_alt` =
`light-switch-on`, `alt_flag` = `kitchen-light`, and a `Use` interaction whose effect sets
that flag. Breaking glass is the same shape with a one-way flag.

The flag lives on the game state, so a flipped switch survives a reload — which is the
behaviour anyone would expect and would otherwise be a bug report.

### Animation

`animation_frames`, unsigned tiny int, default 1. Above 1, the engine loads
`{texture}-1.png` … `{texture}-N.png` and advances them on a timer, reusing the tick the water
strip already runs on (`textures.ts`, `WATER_FRAME_SECONDS = 0.18`). `animation_fps` float,
default 8.

Files per frame rather than a strip, matching the decision for states and the convention
`hands.ts:20-22` states outright: "A file per pose rather than cells on a sheet, so another
pose is another file and nothing depends on the order they were cut in." The botched cut of
the old hand sheets is the cautionary tale.

---

## Data model summary

`level_things`:

| Column | Type | Default |
| --- | --- | --- |
| `render` | enum box/billboard/cross | `box` |
| `plane_count` | unsigned tiny int | 2 |
| `uv_mode` | enum tile/fit | `tile` (editor writes `fit`) |
| `texture_alt` | string, nullable | null |
| `alt_flag` | string, nullable | null |
| `animation_frames` | unsigned tiny int | 1 |
| `animation_fps` | float | 8 |

Validation: `render` and `uv_mode` by `Rule::enum`; `plane_count` `in:2,3` and only meaningful
when render is `cross`; `texture_alt` must name a prop that exists, and must be null unless
`alt_flag` is set (and vice versa); `animation_frames` `between:1,16`.

Nine touch points as usual — migration, model fillable and casts, `LevelPayload::forEngine`,
TS `LevelThing`, `newProp`, `UpdateLevelMapRequest`, `LevelWriter::writeThings`, and
`build-level.ts`.

Colliders are unchanged: a solid thing still gets its box collider from width/depth. Most
plants should simply be authored `is_solid = false`.

---

## Editor

Inspector, thing mode: a render-mode picker; plane count shown only for `cross`; the UV mode
toggle shown only for `box`; the prop texture picker reading `LevelAssets::props()`; alt
texture and flag; animation frames and rate. Everything conditional on what it applies to,
matching how sprite and behaviour are already conditional on kind.

Map view: draw cross props with a small X glyph and billboards with a circle, so the mode is
legible in plan without selecting each one.

---

## Tests

Node-harness unit tests, as `tests/Unit/WallOverhangTest.php` does it:

- `tests/Unit/PropRenderTest.php`
  - `cross` with `plane_count` 2 builds two quads 90° apart, 3 builds three 60° apart, both
    offset by the thing's `angle`
  - `fit` UVs run 0..1 on every face; `tile` UVs still match `TEXTURE_METRES`
  - every cutout material has `alphaTest` set and `transparent` false — the sorting guarantee
  - a thing with `alt_flag` set draws `texture_alt`, and draws `texture` without it
  - `animation_frames > 1` loads N files and advances them
- Extend `tests/Unit/PortalBoundaryTest.php`: a billboard prop faces the pane's camera during
  a pane pass, not the player. This is the regression `.ai/rules/game.md` describes for the
  sky, and it will happen again here.

Feature: the new columns round-trip; a `cross` thing with `plane_count` 4 is rejected;
`texture_alt` without `alt_flag` is rejected; an unknown prop name is rejected.

---

## Verification

1. `composer test`, `npm run types:check`.
2. Put a door in a doorway with `uv_mode = fit` and confirm the whole door shows rather than
   the middle 45% of it.
3. Put a cross plant in a room and walk a full circle around it. It should read as a plant
   from every angle and never as two flat boards — if it does, the art's silhouette is too
   rectangular, which is an art note, not a code bug.
4. Look at a billboard prop **through a portal**. If it faces the player rather than the
   pane's camera it will be edge-on or backwards in the pane.
5. Flip a light switch, reload the page, confirm it is still on.
6. Stand in front of the TV and confirm it animates, then look at it in a mirror and confirm
   the reflection animates in step.
7. `?debug` still works — `paintWalls` overrides thing materials too, so cutout props should
   read as flat quantised colour.
