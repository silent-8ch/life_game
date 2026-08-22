# Planning notes: slopes, and starting RPG stats

Two independent pieces of design, plus the repository housekeeping asked for alongside
them. Nothing here is implemented — these are notes to be dropped in `docs/` and kept out
of the repository.

---

## Housekeeping (do this now)

The repo has **no commits yet**; all 480 files are staged as one initial import, so "the
staged changes" is the whole project. It is clean — no sqlite, logs, keys, `.DS_Store`,
build output, `node_modules`, or files over 200KB. Everything that ought to be ignored
already is:

| Path | Ignored by |
| --- | --- |
| `.DS_Store`, `.claude/` | `~/.gitignore_global` |
| `database/*.sqlite*` | `database/.gitignore` |
| `storage/framework/**`, `storage/logs/**` | the per-directory `.gitignore` files |
| `node_modules`, `vendor`, `public/build`, `resources/js/{actions,routes,wayfinder}` | `.gitignore` |

So `docs/` is the only entry that needs adding.

**Actions:**

1. Write these notes to `docs/slopes.md` and `docs/rpg-stats.md`.
2. Append `docs/` to `.git/info/exclude`.

Caveat worth stating plainly: `.git/info/exclude` only affects **untracked** files. It will
not unstage anything already added. Nothing currently staged needs unstaging, so this is
not a problem today — but if `docs/` were ever `git add`ed, the exclude entry would be
silently ignored and it would need `git rm --cached -r docs`.

---

# Part 1 — Sloped floors and ceilings ("slants")

## Context

Every room in the engine is a closed polygon with two scalar heights — `floor_height` and
`ceiling_height` — and both surfaces are rigidly horizontal. `buildFlat` sets
`group.position.y = height; group.rotation.x = -π/2`, and `buildWall` uses
`THREE.PlaneGeometry(drawn, height)`, an axis-aligned rectangle centred at
`bottom + height/2`. There is no per-vertex or per-edge height anywhere: `level_vertices`
holds only `x` and `z`, and `grep -rin "slope\|slant"` returns nothing.

The goal is Build-engine slopes: a room's floor and/or ceiling tilts about one of its own
walls, so ramps, staircases, roof pitches and hillsides become authorable. Because the
renderer is retained-mode three.js rather than a per-column span rasterizer, this is a
geometry and data-model change, not a renderer rewrite — but it reaches every surface
builder, the portal panes, the editor and the level validator.

Decisions taken: hinge edge + gradient (Build's model); floors **and** ceilings; portal
mouths, sky lids and water all supported on sloped sectors; the eye follows the floor plane
at any steepness with no gravity or sliding; the editor authors slopes through Inspector
fields with a slanted side-view section.

Rough size: **~4 days.**

## The slope function

A slope is a base height along a chosen **hinge wall**, plus a rise per metre measured
perpendicular into the room:

```
floorAt(sector, x, z):
    if slope == 0 or hinge is null: return sector.floorHeight
    hinge = edge from points[hingeIndex] to points[(hingeIndex + 1) % n]
    n     = inwardNormal(sector, hinge.from, hinge.to)      // sectors.ts, already exists
    d     = (x - hinge.from.x) * n.x + (z - hinge.from.z) * n.z
    return sector.floorHeight + sector.floorSlope * d
```

`floorHeight` therefore means "the height along the hinge wall", which is exactly Build's
convention (floorz sits at the first wall). This is what makes shared walls line up for
free: two rooms hinged on the same wall with the same base height meet flush there, and
each rises into its own room because `inwardNormal` points opposite ways for the two sides.

Both surfaces are planar by construction, so a linear function's extremes over the polygon
are always at its corners — every validation and headroom check below is exact when
sampled at corners only, with no need to sample the interior.

New in `resources/js/lib/engine/sectors.ts`: `floorAt`, `ceilingAt`, and `heightsAlong(edge)`
returning the four numbers a wall between two sectors needs. A PHP mirror goes on
`app/Models/LevelSector.php` alongside the existing `headroom()`, and the geometry helpers
in `tests/Pest.php` use it. Two copies is the established cost here — the same note as
`LevelAssets::HEIGHTS` in `.ai/rules/services.md`.

## Data model

`level_sectors` gains four columns (one migration):

| Column | Type | Meaning |
| --- | --- | --- |
| `floor_slope` | float, default 0 | rise in metres per metre from the hinge |
| `floor_slope_edge` | unsignedSmallInteger, nullable | hinge wall, an edge `sort_order` |
| `ceiling_slope` | float, default 0 | as above |
| `ceiling_slope_edge` | unsignedSmallInteger, nullable | as above |

Nine touch points, the chain named in `.ai/rules` for any new sector field:

1. migration above
2. `app/Models/LevelSector.php` — `#[Fillable]` (~line 34) and `casts()` (~line 55)
3. `app/Services/LevelPayload.php:53` — camelCase into the sector payload
4. `resources/js/types/game.ts:129` — `Sector` gains `floorSlope`, `floorSlopeEdge`, `ceilingSlope`, `ceilingSlopeEdge`
5. `resources/js/lib/editor/map.ts` — `newSector` (~line 475) spells out every field, so it must list these
6. `resources/js/lib/editor/carve.ts` — `inherit`/`dress` spread sector fields; see hinge survival below
7. `app/Http/Requests/UpdateLevelMapRequest.php:53-70` — rules and the new `after()` check
8. `app/Services/LevelWriter.php:49-60` — write the columns
9. `resources/js/lib/engine/build-level.ts` — the geometry, below

### Keeping the hinge attached to the right wall

A hinge stored as an index breaks whenever the point list is mutated — `splitEdge`,
`weldCorners`, `moveCorner` and `carveRooms` all do this, and `weldCorners` runs after
every carve. Same class of problem as a split wall losing its `portal_link`.

Resolve it by coordinate, not by index: a helper in `lib/editor/map.ts` reads the hinge's
`from`/`to` coordinates before a mutation and re-finds the matching edge after, rewriting
the index. Splitting the hinge wall keeps the first half (same line, same plane, so the
slope is unchanged). If no edge matches afterwards — the hinge wall was carved away — the
slope is cleared to 0 with a null hinge, mirroring how carved edges lose their link.

### Validation

In `UpdateLevelMapRequest`:

- `floorSlope`/`ceilingSlope`: `numeric|between:-8,8`
- `floorSlopeEdge`/`ceilingSlopeEdge`: `nullable|integer|min:0`, and an `after()` rule that
  it is less than that sector's point count, and non-null whenever its slope is non-zero
- a new `after()` check replacing the flat clamp: sample `floorAt`/`ceilingAt` at every
  corner of every sector and reject if the ceiling ever falls below its floor. The existing
  `prepareForValidation` clamp at `:245-261` stays for the flat case.

## Engine geometry

### Flats — `build-level.ts:403-489 buildFlat`

`ShapeGeometry` triangulates the polygon in the XY plane as `(x, -z)`, and
`rotation.x = -π/2` maps local +Z onto world +Y. So keep the group transform exactly as it
is and displace each vertex's **local z** by `slope * d(x, z)`. `holder.position.y` stays
at the sector's base height.

That leaves `tileFlatUvs` (`textures.ts:127`) untouched and correct: UVs stay projected
from the horizontal plane, so texels stretch along the slope — Build's behaviour. The
untextured path's wireframe `gridGeometry` needs the same displacement, and its 0.004
local-z offset still works because local z is the surface normal.

`buildSkyCeiling` (`:496-515`) shares `shapeOf`, so it gets the same displacement and keeps
its `colorWrite: false` depth-only role. The water path (`:412-440`) rewrites UVs from the
sector's bounding box and is unaffected by a y displacement.

### Walls — `build-level.ts:300-397 buildWall`

Signature changes from `(edge, bottom, top, texture)` to per-end heights —
`(edge, { bottomFrom, bottomTo, topFrom, topTo }, texture)`.

- Replace `THREE.PlaneGeometry(drawn, height)` with an explicit four-vertex
  `BufferGeometry` in the holder's local frame (local x along the wall, local y up), and set
  `holder.position.y = 0` rather than the midpoint.
- The `WALL_INSET` overhang (`back`/`front`, drawn past the ends where `carriedOn` says the
  wall turns a corner) must **extrapolate** the heights linearly past each end, or the
  overhang is flat and pokes through the neighbouring wall.
- **Do not keep the `top - bottom <= 1e-3` early return as a whole-wall test.** A sloped
  wall legitimately reaches zero height at one end — that is what a Build staircase looks
  like. Clamp per end (`top = max(top, bottom)`) so the quad degenerates into a triangle,
  and only skip when both ends are degenerate.
- UVs: `tileUvs` (`textures.ts:105`) scales uniformly from the wall's own bottom. Replace
  with a per-vertex version taking V from world y, so a wall under a slope keeps its
  texture level rather than shearing with the top edge.

### Shared edges — `build-level.ts:864-888`

```
buildWall(edge, sector.floorHeight, beyond.floorHeight, texture)
buildWall(edge, beyond.ceilingHeight, sector.ceilingHeight, texture)
```

becomes four evaluations per surface — each sector's plane at each of the edge's two
endpoints — fed to the trapezoid builder. Per-end clamping handles floors that cross mid
wall: each side builds the triangle covering the stretch where its own floor is lower, and
the two triangles together close the gap.

`climb` and `headroom` are differences of linear functions along the edge, so their extremes
are at the endpoints: take `max` of the two climbs and `min` of the two headrooms, then
apply the existing `MAX_STEP` / `MIN_HEADROOM` gate unchanged.

Authoring consequence worth knowing: a ramp joining two rooms only stays walkable if both
rooms are hinged on the shared wall at the same base height, so the climb is zero at both
ends. A room sloped away from its neighbour gets a blocking collider across the whole
doorway. That is Build's workflow and the gate is already correct.

### Portal panes — `build-level.ts:665-780`, `portal-surface.ts`

- The mouth's height is currently `entry.sector.ceilingHeight - entry.sector.floorHeight`
  (`:701`) centred at `floorHeight + height/2` (`:757`). Both become the four plane
  evaluations at the mouth edge's endpoints, and `createPortalSurface` takes a trapezoid
  instead of width/height.
- `hug()`'s across/up containment test (which per `.ai/rules/engine.md` is what stops a
  pane covering the screen anywhere along its wall's plane) uses the trapezoid's bounding
  rectangle — conservative in the right direction, so the level-8 fix it pins still holds.
- `paneTexels` and `EDGE_BIAS_TEXELS` likewise take the bounding rectangle.
- The pane is still built `WALL_INSET * 2` narrower than the mouth, and `PORTAL_RECESS`
  stays 0.
- **The `through` transform stays y-free.** `turnBetween` remains the single source of the
  angle for both `crossPortal` and `buildPortalPane`, and neither gains a y term. A portal
  joining two mouths at different heights shows the same vertical offset it does today —
  this change does not fix that and does not make it worse. Two linked mouths should have
  matching height profiles as well as matching length, or the far view is stretched;
  treat that as an authoring hazard, not a validated constraint, so existing seeded levels
  keep passing.

### Player, actors, everything else that reads a floor height

`level-viewport.tsx:608-622` is the only place the player's floor comes from:

```
const floor = standingIn?.floorHeight ?? 0;
```

becomes `floorAt(standingIn, player.x, player.z)`. `STEP_SMOOTHING` (12) already filters it,
so a ramp feels right with no further change, and there is no max grade — the eye simply
follows the plane. Same one-line substitution at:

- `actors.ts:54` `floorUnder`, used at `:91`, `:163`, `:190`
- `level-viewport.tsx:683`, `:701` and `spells.ts:309` — mark/recall circles
- `snapshot.ts:40-41`, `:163-164` — report the height under the player, not the sector's base

`collision.ts` stays untouched and stays 2D. Step gating remains a build-time collider
decision, as it is today.

## Editor

**Inspector** (`components/editor/inspector.tsx`) — under the existing Floor/Ceiling pair at
`:848-869`, add a slope block per surface: a hinge `<select>` listing the selected room's
walls (labelled by index plus a compass direction derived from `inwardNormal`) and a
`NumberInput` with `step="0.05"` for the rise. Follow the existing `Field` + `NumberInput`
pattern, and add both to the multi-room `shared()` path at `:213-256` so a mixed selection
shows the `—` placeholder.

**Side view** (`components/editor/side-view.tsx`) — the section looks north, so sample
`floorAt`/`ceilingAt` at the room's min-x and max-x and draw the two lines slanted. Keep the
existing drag (`:210-249`) editing base heights only; a slope running north–south reads as
flat in this section, so annotate a sloped room with a small marker so it is not mistaken
for a bug.

**Map view** (`components/editor/map-view.tsx:330`) — keep the `"{floor} → {ceiling}"` label
and draw a short arrow on the hinge wall pointing uphill, so a slope is visible in plan.

## Files touched

```
database/migrations/…_add_slopes_to_level_sectors_table.php   (new)
app/Models/LevelSector.php
app/Services/LevelPayload.php
app/Services/LevelWriter.php
app/Http/Requests/UpdateLevelMapRequest.php
resources/js/types/game.ts
resources/js/lib/engine/sectors.ts          floorAt / ceilingAt / heightsAlong
resources/js/lib/engine/build-level.ts      buildFlat, buildWall, shared edges, panes, sky lid
resources/js/lib/engine/portal-surface.ts   trapezoid pane, hug(), paneTexels
resources/js/lib/engine/textures.ts         per-vertex wall UVs
resources/js/lib/engine/actors.ts           floorUnder
resources/js/lib/engine/spells.ts           circle height
resources/js/lib/engine/snapshot.ts         reported heights
resources/js/lib/editor/map.ts              newSector, hinge re-resolution helper
resources/js/lib/editor/carve.ts            hinge survival through a carve
resources/js/components/game/level-viewport.tsx
resources/js/components/editor/inspector.tsx
resources/js/components/editor/side-view.tsx
resources/js/components/editor/map-view.tsx
tests/Pest.php                              PHP floorAt/ceilingAt for the geometry helpers
```

## Tests

New, following the Node-subprocess harness pattern of `tests/Unit/WallOverhangTest.php`
(a `Symfony\Component\Process\Process` running the TypeScript and printing JSON):

- `tests/Unit/SectorSlopeTest.php` — `floorAt` rises into the room, not out of it; a flat
  sector is unchanged; two rooms hinged on their shared wall at the same base height agree
  along that wall to within a millimetre.
- `tests/Unit/SlopedGeometryTest.php` — every flat vertex lies on its plane; a wall under a
  slope is a trapezoid whose corners match `floorAt`/`ceilingAt` at the edge endpoints; a
  step wall between crossing floors degenerates to a triangle on each side rather than being
  skipped; the `WALL_INSET` overhang extrapolates the slope.

Extended:

- `tests/Unit/WallOverhangTest.php` — its coplanar-overlap assertion must handle trapezoids.
- `tests/Unit/PortalBoundaryTest.php` — a mouth on a sloped sector builds a trapezoid pane;
  `hug()` still refuses along the same wall away from the opening.
- `tests/Feature/LevelEditorTest.php` — the four fields round-trip; a hinge index past the
  point count is rejected; a slope that drives the ceiling below the floor at a corner is
  rejected; a carve that removes the hinge wall clears the slope.
- `tests/Feature/LevelGeometryTest.php` — `:140` and the doorway step/headroom invariants
  switch to the sampled PHP `floorAt`/`ceilingAt`.

## Verification

1. `composer test` — Pint, PHPStan, then the full Pest suite. Then `npm run types:check`
   and `npm run lint:check`.
2. `php artisan test --compact --filter=Slope` for the new geometry tests while iterating.
3. In the app: open the level editor (`/editor/{level}`, auth required), give a room a
   floor slope of 0.25 hinged on the wall it shares with its neighbour, and set the
   neighbour's base floor to match. The debounced live preview in `pages/editor/level.tsx`
   renders it without a save.
4. Walk it: save, open the game page, and confirm the eye rises smoothly, the wall under the
   ramp is a triangle with no gap at the corner, and the doorway is not blocked.
5. Sloped-ceiling check: pitch a ceiling until headroom at one end drops under
   `MIN_HEADROOM` (1.2) and confirm the doorway gains its blocking collider.
6. Portal check: put a portal mouth in a sloped room in the portal-demo level and confirm
   the pane fills the sloped opening with no bright rim and no sky flash walking through.
7. `tests/Feature/DebugSnapshotTest.php`'s snapshot output is a quick way to read back the
   floor height under the player at a given spot.

---

# Part 2 — Starting RPG stats

## Context

The six people in the household — paul, wade, krystal, luna, luke, william — are currently
described by exactly one number each: how tall they stand.
`LevelAssets::HEIGHTS` (`app/Services/LevelAssets.php:28`) is the authority, mirrored in
`sprite-actor.ts`, and `.ai/rules/services.md` already records that the two copies must move
together.

The intent is to give every person a starting stat block that game mechanics can eventually
read. Nothing consumes the numbers in this pass — the point is to get them defined, shipped
to the client, and tunable, before deciding what they do.

Decisions taken: **SPECIAL** (Strength, Perception, Endurance, Charisma, Intelligence,
Agility, Luck); starting values as a PHP const table beside `HEIGHTS`; the six household
people plus per-thing overrides in the level editor; **no mechanics** — no derived values,
no condition type, no effect type.

Rough size: **~1 day.**

## Where the numbers live

A new `app/Services/PersonStats.php`, deliberately shaped like `LevelAssets`:

```php
class PersonStats
{
    /** The seven, in canonical SPECIAL order. */
    public const ATTRIBUTES = [
        'strength', 'perception', 'endurance',
        'charisma', 'intelligence', 'agility', 'luck',
    ];

    public const MINIMUM = 1;
    public const MAXIMUM = 10;

    /** Every person spends the same pool, so nobody starts ahead. */
    public const BUDGET = 35;   // 7 × 5

    /** @var array<string, array<string, int>> */
    public const STARTING = [ /* one block per person */ ];

    /** @return array<string, int> */
    public function for(string $sprite): array { … }
}
```

**On the numbers themselves:** these are real people, and I am not going to invent
characterisations of them. The table ships with every person at a flat 5 across the board —
a valid, balanced `BUDGET` spend — and a comment saying the point of the file is for you to
redistribute them. The tests assert the invariants (seven keys, in range, summing to
`BUDGET`), not any particular person's numbers, so retuning is a one-file edit that stays
green. Height stays exactly where it is in `LevelAssets::HEIGHTS`; it is measured, the stats
are authored, and conflating them would tie a real measurement to a game value.

`PersonStats::STARTING` must cover every key of `LevelAssets::HEIGHTS` — pinned by a test,
so adding a seventh person fails loudly rather than silently shipping no stats.

## Per-thing overrides

`level_things` gains one nullable `json` column, `stats`, cast to `array` on
`app/Models/LevelThing.php`. Precedent: `levels.backdrop_layers` is the one existing json
column and is deliberately narrow — this follows it rather than adding seven columns to a
table that already has eighteen.

Semantics: **all-or-nothing.** `null` (the default, and what every existing row gets) means
"use `PersonStats::for($thing->sprite)`". A non-null value must be a complete block of all
seven keys. There is no partial merge — a half-specified block is the kind of thing that
reads fine in the editor and surprises you two months later.

Resolution order, exposed as `LevelThing::stats(): array`:

```
$this->stats ?? PersonStats::for($this->sprite) ?? PersonStats::neutral()
```

The last fallback covers a thing of kind Person with no sprite, and props (kind Prop) simply
never get asked.

### Validation — `UpdateLevelMapRequest`

Alongside the existing `things.*` rules at `:72-89`:

```php
'things.*.stats' => ['nullable', 'array', 'size:7'],
'things.*.stats.*' => ['required', 'integer', 'between:1,10'],
```

plus an `after()` check that the keys are exactly `PersonStats::ATTRIBUTES` (so a typo is
rejected rather than stored), and that `stats` is null unless the thing's `kind` is
`ThingKind::Person`.

The budget is **not** enforced on overrides — an authored NPC should be allowed to be
exceptional. Only `PersonStats::STARTING` is budget-checked, and only by a test.

## Shipping them to the client

`app/Services/LevelPayload.php`:

- `forEngine()` (`:22`) — each thing of kind Person gains a resolved `stats` block, and the
  payload gains a top-level `playerStats` from `PersonStats::for($level->player_sprite)`.
  This follows the rule in `.ai/rules/services.md` that `forEngine` ships only what the
  engine needs; stats are the resolved values, never the raw override plus the fallback.
- `forEditor()` (`:102`) — additionally ships the **raw** `stats` (null or a block) per
  thing, so the Inspector can tell "inherited" from "overridden" and offer a reset.

TypeScript: `resources/js/types/game.ts` gains

```ts
export type Stats = {
    strength: number; perception: number; endurance: number;
    charisma: number; intelligence: number; agility: number; luck: number;
};
```

with `stats: Stats` on `LevelThing` and `playerStats: Stats` on `Level`. Nothing in
`lib/engine` reads them yet — that is the point of stopping here.

`LevelWriter::writeThings()` (`:93`) writes the column; it already deletes and recreates
things wholesale each save, so nothing else changes.

## Editor

`components/editor/inspector.tsx`, in the thing/person mode only, below the existing sprite
and behaviour controls: a seven-row block of `NumberInput`s (`step="1"`, min 1, max 10)
behind a checkbox reading something like "Override stats". Unchecked ships `null` and shows
the inherited values greyed out; checking it seeds the inputs from the inherited block so
the author starts from the person's own numbers rather than from nothing. `newPerson` in
`lib/editor/map.ts` sets `stats: null`.

The block is hidden entirely for `ThingKind::Prop`, matching how sprite and behaviour are
already conditional on kind.

## Files touched

```
app/Services/PersonStats.php                            (new)
database/migrations/…_add_stats_to_level_things_table.php (new)
app/Models/LevelThing.php                    cast, stats() resolver
app/Services/LevelPayload.php                forEngine + forEditor
app/Services/LevelWriter.php                 write the column
app/Http/Requests/UpdateLevelMapRequest.php  rules + after()
resources/js/types/game.ts                   Stats, LevelThing.stats, Level.playerStats
resources/js/lib/editor/map.ts               newPerson default
resources/js/components/editor/inspector.tsx the override block
```

## Tests

- `tests/Unit/PersonStatsTest.php` — every key of `LevelAssets::HEIGHTS` has a block; every
  block has exactly the seven attributes; every value is 1..10; every block sums to
  `BUDGET`; `for()` on an unknown sprite returns the neutral block rather than throwing.
- `tests/Feature/LevelEditorTest.php` — a person thing round-trips an override; omitting
  `stats` stores null; a six-key block is rejected; a misspelled key is rejected; a value of
  11 is rejected; a Prop with stats is rejected.
- `tests/Feature/ExplorePageTest.php` — `forEngine` ships resolved stats on people and a
  `playerStats` block; a thing with no override gets its person's numbers.

## Verification

1. `composer test`, then `npm run types:check`.
2. `php artisan test --compact --filter=PersonStats`.
3. `php artisan tinker --execute 'dump(app(App\Services\PersonStats::class)->for("luna"));'`
4. In the editor, select a person, tick "Override stats", change one number, save, reload —
   the override survives; untick it and confirm the payload goes back to the inherited block.

## Deliberately not done

Named so the next pass does not have to rediscover the boundary: no derived values (carry
weight, speed, reach); no `StatAtLeast` on `ConditionType` or `ChangeStat` on `EffectType`;
no mutable current-value store — `game_states` is untouched, and where a stat that changes
during play would live is an open question, not an oversight. `PersonStats::STARTING` is
starting values only.
