# Lighting: baked global illumination

> Supersedes the earlier draft of this file, which planned Phong materials with per-sector
> shade and placeable point lights. The goal changed to realistic, simulation-level lighting,
> and that changes the architecture rather than the settings.

## Context

Every surface in the level is `MeshBasicMaterial` and the scene contains **no lights at all**.
That is deliberate — `.ai/rules/engine.md` describes surfaces as "a near-black solid so walls
occlude, plus bright lines on top" — but it means there is nothing to build on.

**The constraint that decides the architecture is `PORTAL_RENDER_BUDGET = 40`**
(`constants.ts:79`). Portals and mirrors re-render the whole scene up to forty times per
frame. Anything computed in real time costs forty times over: eight shadow-casting point
lights would be 48 extra cube-face renders per frame *before* the actual view. Real-time
global illumination is further out of reach still.

Anything **baked** costs the same in every pass — one extra texture read. So for this
renderer baked lighting is not a compromise, it is the only route to realism. It is also what
Quake, Half-Life and Source did for static geometry, and what modern engines still do.

The levels are small by lightmapping standards. Measured across all nine seeded levels: 139
sectors, 835 walls, largest level 48 sectors and 286 walls. Roughly 400 surfaces to light in
the worst case. Better still, **every surface is a rectangle or a planar polygon**, so the UV
unwrap that normally dominates this work is close to trivial here.

Decisions taken: baked path-traced GI with a light grid; the bake runs as a Node script
behind an artisan command; lighting is otherwise fully static, with **moving shadow casters**
as the single dynamic element.

Rough size: **5–8 weeks.** This is a new subsystem — a ray tracer, a BVH, a UV packer, an
asset pipeline and an editor bake step — not an addition to the material code.

---

## Architecture

```
              authoring                      bake (offline, minutes)                runtime (free)
  editor ──▶ level rows ──▶ buildLevel() ──▶ unwrap ──▶ pack ──▶ trace ──▶ denoise ──▶ lightmap.png ──▶ material.lightMap
                                │                                    └──▶ grid.png ─────▶ actor tint
                                │                                                       └──▶ dominant dir ──▶ 1 shadow map
                                └── the SAME geometry the renderer draws
```

### The single most important rule

**The baker must build its geometry by calling `buildLevel()` itself, not by
reimplementing it.**

`build-level.ts` does a great deal that affects where light can go: every wall is nudged
`WALL_INSET` into its own room and drawn past its ends where it turns a corner; a portal
mouth is one-sided and builds no wall on the face that names the link; a sky sector gets a
depth-only lid rather than a ceiling. A tracer that reconstructs geometry from the level rows
will disagree with the renderer in exactly those places, and every disagreement is a light
leak — a bright seam at a corner, or a room lit through a wall.

The Node harness for this already exists: `tests/Unit/WallOverhangTest.php` loads
`build-level.ts` under Node with a stubbed `document` so the texture loader does nothing
quietly. The baker uses the same trick.

---

## Stage 1 — unwrap, pack, and a direct-light bake

The point of stage 1 is to prove the whole pipeline end to end with the simplest possible
tracer. Do not start with bounces.

### Lightmap UVs

Each surface gets a chart — a rectangle of lightmap texels:

- **Wall**: u runs along the wall, v runs up. It is already a quad in its own plane.
- **Floor / ceiling**: u = world x, v = world z. Planar, because they are horizontal.
  (After the slopes work in `docs/plan-slopes-and-stats.md`, a sloped flat projects along its
  own plane instead — the projection is still planar, just tilted.)

Density `LIGHTMAP_TEXELS_PER_METRE = 4` to start, so a texel is 25 cm. Quake used roughly one
per 40 cm and looked fine; 4/m gives headroom to come down if the atlas gets tight.

Write the result into the geometry's **`uv1`** attribute — three renamed `uv2` to `uv1` for
lightmaps in r152, and this project is on r185.

### Packing

A shelf/skyline rectangle packer into a single atlas per level, capped at 2048². For the
largest level that is comfortable: ~400 charts, a typical wall around 30 × 12 texels.

**Every chart needs a 2-texel gutter and the border dilated outward.** Bilinear filtering
samples past a chart's edge, and without padding it reads whatever chart was packed next to
it — which shows as bright or dark specks along wall edges, and is the classic lightmapping
bug. Dilate after baking, not before.

### The tracer

- Build a BVH over the triangles `buildLevel()` produced.
- For each lightmap texel: recover its world position and normal from the chart, fire rays at
  the emitters, accumulate.
- Emitters in stage 1: lamp things, and `is_sky` sectors radiating the sky colour.

### Runtime

`surfaceMaterial` returns `MeshStandardMaterial` with `map`, `normalMap`, `lightMap` and
`lightMapIntensity`. No scene lights at all — the lightmap *is* the lighting, so the flat
unlit renderer stays flat and unlit and simply reads a second texture.

That means `PORTAL_RENDER_BUDGET` is untouched by any of this. Confirm it with a frame-time
measurement on the portals level before and after.

**Colour space trap.** `retro()` in `textures.ts:26-33` tags everything `SRGBColorSpace` and
sets `magFilter = NearestFilter`. A **normal map must be `NoColorSpace`** or its vectors
decode wrong — subtly, so the result looks almost right and the cause is very hard to find
later. A **lightmap wants `LinearFilter`**, because nearest-filtered lighting is blocky in a
way that reads as a fault rather than as style. So `textures.ts` needs three loader paths,
not one.

**Ceilings currently face the wrong way.** `buildFlat` (`build-level.ts:455-457`) applies
`rotation.x = -Math.PI / 2` for the floor *and* the ceiling, and `buildSkyCeiling` (`:522-523`)
does the same, so a ceiling's normal points **up** exactly like a floor's. Invisible while
unlit and `DoubleSide`; under any lighting every ceiling is lit as though it were a floor.
Fix this first, and test it first — every downstream result is meaningless until it is right.

`side` should also become `FrontSide`. Double-sided was free while unlit; now a wall seen from
behind lights as though its back face pointed at you. Expect this to reveal any wall whose
winding is wrong, which is worth knowing anyway.

---

## Stage 2 — bounces, and making it not look like static

### Path tracing

Cosine-weighted hemisphere sampling, N bounces (start at 3). Emissive surfaces and the sky
both contribute, which is what produces a real shaft of daylight through a window and colour
bleeding off a red carpet onto a white wall.

**Portals and mirrors should be traced properly, and this is nearly free.** A ray that hits a
portal mouth gets carried through by the same transform the renderer uses —
`turnBetween` in `portals.ts` is already the single source of that angle, and
`.ai/rules/engine.md` is emphatic that the walk and the pane must share it. The baker becomes
the third caller. A ray that hits a mirror reflects. Light through a portal is then simply
correct, rather than something to special-case.

### Denoising

A path-traced lightmap at low sample counts is noisy, and noise is the difference between
"baked" and "broken". Either raise samples until it is clean (slow) or filter. An à-trous or
bilateral filter over the atlas, **stopped at chart borders** so it does not bleed one
surface into its neighbour, is the cheap answer.

### Dynamic range

PNG is 8-bit, and a path-traced result is not. Bake with a per-level exposure constant and
tone-map into sRGB at write time. Store the exposure on the level row so it is adjustable
without re-tracing.

RGBM or half-float encoding would preserve real HDR and open up bloom later, but both need a
custom decode in the material. Note it as the upgrade path; do not do it first.

---

## Stage 3 — the light grid, so people are lit too

A lightmap lights surfaces. It does nothing for sprites, actors, hands or props, which is how
you get a person standing in a black room at full brightness.

Bake a second product: a 3D grid over the level bounds, 1 m spacing, holding per cell:

| Field | Use |
| --- | --- |
| Ambient cube (6 directional irradiance values) | tint a sprite by which way it faces |
| Dominant light direction | where a moving object's shadow falls |
| Dominant light colour and intensity | what colour that shadow's light is |
| Valid flag | cells inside solid geometry, snapped away from at sample time |

This is Source's light grid, and the dominant-direction field is exactly what stage 4 needs.

Stored as PNG slices rather than JSON — a 40 × 10 × 40 m level at 1 m spacing is 16,000 cells,
which is a large JSON payload and a trivial image.

Runtime: actors, hands and props stay `MeshBasicMaterial` — their artwork already has shading
painted in, and lighting it properly would shade it twice and turn faces muddy. Instead they
sample the grid at their position and tint:

```ts
material.color.copy(grid.sample(x, y, z, facing));
```

`actors.ts:54` already calls `sectorAt` every frame, so the hook is there.

---

## Stage 4 — moving shadow casters

The one dynamic element. Doors, actors and props cast real shadows as they move.

The technique, which is why stage 3 bakes a dominant direction: **one** `DirectionalLight`
exists permanently in the scene, aimed each frame along the dominant light direction sampled
at the player's position, with a shadow camera covering a few metres around the player. Only
moving objects `castShadow`; static geometry only `receiveShadow`.

Three things make or break this:

1. **Never add or remove the light.** three bakes the light count into every shader program,
   so changing it recompiles all of them and the frame visibly hitches. The light is always
   there; it is modulated by intensity.
2. **It must darken, not add.** The baked lightmap already contains that light's
   contribution, so a directional light on top double-counts it and everything washes out.
   What is wanted is a shadow *mask* multiplied into the result — a shadow-catcher approach,
   or a custom shader chunk. This is the fiddliest part of the whole plan and deserves a
   spike before it is estimated.
3. **Player's pass only.** `castShadow` is switched off for every portal and mirror pass, or
   the cost is multiplied by forty. The visible consequence is that a mirror will not show a
   moving shadow. Take the trade; it is what Source did too.

---

## Data model

`levels`:

| Column | Meaning |
| --- | --- |
| `lightmap_path` | atlas PNG, nullable until first bake |
| `light_grid_path` | grid slices PNG, nullable |
| `lighting_baked_at` | timestamp, nullable |
| `lighting_hash` | hash of the geometry + lights the bake was made from |
| `exposure` | tone-map constant, float |
| `sky_intensity` | how brightly an `is_sky` sector radiates |

`level_things` gains a `ThingKind::Lamp` with `light_colour`, `light_intensity`,
`light_range`, and a `light_shape` (point or area — area lights are what give soft shadows,
and are most of why a bake looks better than real-time). A lamp's height is its existing
`elevation`; no new field needed.

`lighting_hash` is what lets the editor say **"lighting is stale"** — recompute it from the
sectors, edges and lamps on save and compare. Without it, nobody can tell whether the picture
they are looking at reflects the level they just edited, which on a minutes-long bake matters
a great deal.

Validation, plumbing and the nine touch points follow the usual chain recorded in `.ai/rules`.

---

## Editor and tooling

- `php artisan level:bake {slug}` — shells out to Node, prints progress, writes the atlas and
  grid, stamps `lighting_baked_at` and `lighting_hash`. `--quality=draft|final` to trade
  samples for time.
- A **Bake** button in the editor hitting an endpoint that runs the same command, with the
  stale indicator driven by `lighting_hash`.
- Draft quality should be seconds, not minutes, or nobody will iterate. Budget for that
  explicitly — it is a usability requirement, not a nice-to-have.
- The live preview in `pages/editor/level.tsx` keeps showing the last bake, marked stale.

---

## Tests

Node-harness unit tests, following `tests/Unit/WallOverhangTest.php`:

- `tests/Unit/LightmapUvTest.php` — every surface gets a chart; no two charts overlap in the
  atlas; every chart has its full gutter; charts stay inside the atlas bounds.
- `tests/Unit/BakeGeometryTest.php` — **the baker's triangle set is identical to
  `buildLevel()`'s.** This is the test that prevents light leaks, and it is the most valuable
  one in the plan.
- `tests/Unit/LightingTest.php` — a ceiling's normal points down and a floor's points up; the
  normal map's colour space is `NoColorSpace` and the diffuse's is `SRGBColorSpace`; the
  lightmap uses `LinearFilter`; the dynamic light is never added or removed, only modulated.
- A tiny closed box scene with one emitter, traced at high samples, asserting a known
  analytic result within tolerance — the only real guard against a subtly wrong tracer.

Feature tests: the new columns round-trip; `lighting_hash` changes when geometry changes and
does not when only a name changes; a Prop with lamp fields is rejected.

Not covered, and worth saying plainly: **nothing tests what the lighting looks like.** Every
engine test asserts on structure. Bake quality stays an eye judgement.

---

## Verification

1. `composer test`, `npm run types:check`, `npm run lint:check`.
2. **Ceilings first.** Bake one room with a single lamp and confirm the ceiling above it
   lights and the floor below it lights. If the ceiling is black, the normal flip is wrong and
   nothing after this means anything.
3. Bake a room with a red carpet and white walls. If there is no red on the lower walls, the
   bounce pass is not contributing.
4. Bake a sky sector adjacent to an indoor room with an opening. Daylight should reach inside
   and fall off with distance. A hard-edged patch instead means the sky is being treated as a
   point rather than an area.
5. **Leak check.** Walk every seeded level looking for bright seams at corners and light
   through walls. Any leak means the baker and `buildLevel()` disagree — go to
   `BakeGeometryTest` before debugging the tracer.
6. Portal check on the portals level: light should carry through a mouth. Then frame-time
   against the same spawn point before the change — it should be unchanged, since nothing is
   computed per frame.
7. `?debug` still works — `paintWalls` overrides materials to `MeshBasicMaterial`
   (`probe-backdrop.ts:143, 202`), so `scanRow`'s colour legend is insulated. Verify rather
   than rework.
8. Stage 4 only: stand a person under a lamp and walk them. The shadow should move with them
   and fall away from the lamp. If the room brightens as they move, the dynamic light is
   adding rather than masking — see stage 4 note 2.

---

## Honest risks

- **Stage 4's shadow masking** is the least certain part. Making a real-time shadow darken a
  baked lightmap without double-counting the light is genuinely fiddly in stock three.js and
  may want a custom material. Spike it before committing to an estimate.
- **`logarithmicDepthBuffer: true`** makes any depth-reading post effect awkward, because
  depth is not linear the usual way. That rules out easy SSAO or contact shadows as a
  later addition without more work than it looks.
- **The portal panes are custom shaders sampling by screen position**, so full-frame
  postprocessing needs care around them.
- **Bake time is a usability risk, not just a compute one.** If a draft bake is not seconds,
  authoring lighting becomes miserable regardless of how good the result is.
