---
paths:
  - 'resources/js/lib/engine/**'
  - 'resources/js/lib/engine/sprite-*.ts'
  - resources/js/lib/engine/portals.ts
  - resources/js/lib/engine/hands.ts
  - resources/js/lib/engine/portal-surface.ts
  - resources/js/lib/engine/sectors.ts
  - resources/js/lib/engine/build-level.ts
---

# Engine

## First-person engine conventions
World units are metres: +X east, +Z south, +Y up. Angles are authored in degrees (0 faces -Z, 90 faces +X) and converted once at the boundary — the camera yaw and thing meshes negate them, the collision box angle does not.

Surfaces are built twice: a near-black solid so walls occlude, plus bright lines on top. Texturing later means giving the solid a map and dropping the lines, not changing geometry.

Collision is 2D only (circle vs segment vs rotated box) and has no swept test, so it relies on RUN_SPEED * MAX_FRAME_SECONDS staying under 2 * PLAYER_RADIUS. Raising the speed cap or the frame ceiling without raising the radius lets the player walk through walls.

## Mirrors and the player sprite
A wall run with `is_mirror` becomes a three.js Reflector instead of a drawn surface. It always turns to face the middle of the level, since authored wall runs have no inside/outside of their own.

The player's body is a sprite quad that is invisible in the main pass — `showPlayerInMirrors` in level-viewport makes it visible only for the length of each mirror's own render, and hides the other mirrors during that pass so coplanar mirrors cannot recurse into each other.

The atlas rows are indexed by where the viewer stands relative to the body, not by the names in the sprite README — the sheets are named for how the body is turned, which is the opposite. Because the body always faces where the camera looks, the back rows are only reachable once something other than the player is doing the looking.

A mirror reverses everything it shows, the sprite included, so `faceViewer` takes a `throughMirror` flag: it picks the cell for the opposite angle and draws it reversed, and the mirror's own reversal cancels both out. Without it the reflection shows the correct pose painted the wrong way round.

## Whether a wall is solid belongs to the boundary, not to one room
`blocks` is stored per side (one row per `level_sector_edges`), but the two sides of a shared wall must agree or the level is broken: the blocking room draws a full-height wall with a collider while the open room draws nothing, so a doorway stays shut from one side only.

`build-level.ts` therefore treats an edge as solid when either side blocks (`edge.from.blocks || edge.beyondFrom?.blocks`), and `edgesOf` in sectors.ts hands back `beyondFrom` for that. The map editor's inspector sets the flag on both sides at once (`updateEdge` in lib/editor/map.ts, via `twinEdge`); the wall texture and the mirror flag stay per-side.

Watch for authoring that sets only one side: `LevelStarter` walls its room with `blocks = true` while `newSector` in the editor leaves rooms non-blocking, so a wall between them starts out solid and needs one toggle to open.

## Portals are linked wall pairs; mirrors are rendered in rounds
A portal is two edges sharing a `portal_link` name (pairing is by name, not id, because `LevelWriter` rebuilds every edge row on save). `createPortals`/`crossPortal` in engine/portals.ts turn a crossing into a rigid transform — offset along the wall, heading and speed all carried through the turn between the two mouths — because a drawn-through portal needs the same transform for its camera. Do not replace it with a jump to a fixed spot.

A mouth is an opening: build-level.ts draws no wall and adds no collider for the face that names the link (the room behind that wall keeps its own — see "A portal mouth is one-sided" below), and the viewport asks `crossPortal` BEFORE the "never step off the floor plan" guard, since the crossing step lands outside every sector until it is carried through. A link named other than exactly twice is ignored and stays an ordinary wall. Splitting or carving a wall clears its link — half a wall is not the wall its partner was paired with. Both mouths should be the same length or the player can arrive past the end of the far one.

Mirrors facing each other work because `prepareMirrors` runs every mirror's pass several rounds (MIRROR_BOUNCES, capped by MIRROR_RENDER_BUDGET) instead of hiding the other mirrors: each round puts the previous round's reflections inside this one's. Reflector hides itself, so nothing feeds back into its own target.

## A portal's pane and the player's crossing must share one transform
`turnBetween(entryNormal, exitNormal)` in engine/portals.ts is the single source of the angle a portal turns whoever goes through it. `crossPortal` (the walk) and `buildPortalPane` in build-level.ts (the camera that draws the far side) both call it. Never re-derive it in one place only: a pane showing one thing while the walk arrives at another is the one way a portal reads as broken.

The pane is a `createPortalSurface` mesh (engine/portal-surface.ts), drawn by a camera whose matrixWorld is `through * camera.matrixWorld`, where `through = T(exitCentre) * Ry(turn) * T(-entryCentre)`. That advances yaw by the turn and leaves pitch alone, with no roll. Its near plane is tilted onto the exit mouth (Lengyel oblique projection, as three's Reflector does it) so whatever stands between the camera and that mouth is clipped instead of drawn across the view, and the partner pane is hidden during the render because the camera stands behind it.

Panes and mirrors share the rounds loop in `prepareReflections`, so a portal seen through a portal, or a mirror seen in a mirror, gains a bounce per round.

## A portal pane samples by screen position, and the camera matrix must be fresh
Two traps that both render as "the portal shows only sky":

1. `camera.updateMatrixWorld(true)` must be called at the end of the player step. Mirrors and portal panes derive their cameras from `camera.matrixWorld`, and they run BEFORE `renderer.render`, which is where three would otherwise get round to computing it. Without it the portal camera lands at the portal's translation instead of the player's transformed position.

2. The pane samples its target by the fragment's own screen position (`gl_Position` biased into [0,w] in the vertex shader), NOT by three's Reflector-style textureMatrix. Both cameras share one projection and the portal maps one frustum onto the other, so a ray through a screen point arrives through that same point. Reflector can use the far camera's projection only because reflecting leaves the mirror's plane where it was; a portal moves its pane elsewhere, so that would read the far view at a place the pane is not.

Also: a pane is RECESSED behind its mouth (`PORTAL_RECESS`), not inset toward the room like an ordinary wall. Standing proud makes the pane cover more of the screen than the mouth does and its rim reads the sky the tilted near plane left outside the opening — a bright one-pixel line around the portal.

## Portal recursion: a target per bounce, filtered by room
Each pane holds `PORTAL_BOUNCES + 1` render targets, one per depth, and `show(depth)` picks which the pane displays. `deepen()` in engine/reflections.ts draws depth-first — the panes visible from this pane's own camera are drawn one level in FIRST, then this pane. One target per surface cannot do this: a nested draw would overwrite the view the player is meant to see.

Two things that are load-bearing:
- Recursion only follows panes whose `home` room is this pane's `onto` room. A frustum knows nothing of walls, so without the filter every pane in the level that fell in the cone got drawn and the depth budget went on rooms that are not through this portal at all.
- At the deepest level the panes are hidden rather than shown, because what they would show is the very target about to be written, and a texture cannot be read and written in one pass. The corridor therefore ends in a dark opening.

Mirrors still use three's Reflector with a single target, so a mirror facing a mirror shows it empty. Converting mirrors onto PortalSurface (a mirror is a portal whose transform is a reflection) is what would fix that.

## Mirrors are panes too: one surface type, two ways of aiming the camera
three's `Reflector` is gone. A mirror and a portal are both `createPortalSurface` in engine/portal-surface.ts, differing only in the `aim` and `viewerAt` callbacks build-level.ts hands them: a portal carries the viewpoint through to the far mouth, a mirror reflects it in the wall. They share the per-depth targets, the oblique near-plane tilt, the recursion in `deepen()`, and the budget — so a mirror facing a mirror nests as deep as a portal does.

Two traps, both of which render as garbage rather than as an error:

- Reflecting a POINT in a plane is `reflect(p - centre) + centre`. Do not negate. `Vector3.reflect` is for directions and is already the mirrored one; negating as well leaves the point on the side it started, so the mirror camera never gets behind the glass and you see sky.
- A mirror's camera is built with position + `lookAt` + reflected up, not with a reflection matrix. A matrix with a flip in it reverses the winding of every triangle and turns one-sided surfaces (the sky sphere is BackSide, the panes are FrontSide) inside out.

Because of that, a mirror's pane reads its target through the far camera's projection (`READ_BY_FAR_CAMERA`), while a portal's reads by screen position. Reflecting leaves the mirror's plane fixed so both are right for a mirror; only screen position is right for a portal.

## A portal pane sits exactly in its mouth and reads a texel or two inside
`PORTAL_RECESS` is 0: the pane is coplanar with the mouth, unlike an ordinary wall which gets `WALL_INSET` toward its own room. Set the pane forward and its rim reads the far view outside the opening; set it back and walking past at an angle shows daylight through the gap it leaves behind the wall.

The pane is also built `WALL_INSET * 2` narrower than the mouth, because the walls either side were themselves nudged into the room — at full width the pane hangs a centimetre past their faces and that sliver reads outside the opening.

What is left is sub-pixel: where the pane's edge falls the wrong side of a pixel, the shader would read the sky the tilted near plane left outside the mouth, giving a bright hairline that flickers as the player walks. The vertex shader therefore pulls the read `EDGE_BIAS_TEXELS` in towards the middle of the pane, measured in texels of the target (via the `paneTexels` uniform) rather than as a fraction of the pane — a fraction of a distant pane is a fraction of a pixel and does nothing.

## There is no one rule for which drawing faces which way — there is a table per person
This replaces three sections that used to sit here, which between them gave two different mirroring rules and a third note superseding one of them. All three described a rule that does not exist — none matches the code, so an agent reading top to bottom got the wrong answer twice. What follows is read off `ORDERS` in `sprite-direction.ts`, which is the only source, and is pinned by `tests/Unit/SpriteDirectionTest.php`.

Each person has **eight** drawings, not five: four rows of a cardinal sheet and four of a diagonal one. `ORDERS` names, for 0° 45° 90° 135° 180° 225° 270° 315°, which sheet and row to show — `c2` is cardinal row 2, `d1` diagonal row 1, and a leading `~` means flipped left to right. The angle is where the **viewer** stands relative to the body, not the way the body is turned; the sprite sheets are named for the latter, which is the opposite.

The sheets were drawn one at a time and were not drawn to one order. Paul's diagonals run backwards against Wade's; Krystal's cardinals run backwards against Paul's. Any rule of the form "mirror these three angles" is therefore wrong for somebody, which is what put the old sections here.

**Mirroring is a last resort, not an economy, and it is per person.** Four of the six use none at all. A flipped person wears their watch on the wrong wrist and leads with the other foot, and where a flipped view meets an unflipped one of the same body the walk visibly changes step. It is used only where a sheet has no drawing for a direction, because two of its cells were drawn facing the same way and left another way round the body unpainted:

- Wade and Luke: diagonal row 0 was drawn facing the same way as row 3, so neither sheet has a 45°. It is flipped from their 315°.
- William: rows 0 and 1 repeat his 315° and 225°, so both views turned towards his right are flipped from the two turned to his left.

Nobody's 270° is mirrored — it is a cardinal row like any other. A new person gets Paul's order until somebody checks their sheets with `public/sprite-directions.html`, and it will be wrong as often as it is right, so check rather than leave them on the fallback.

## Walking into a portal: the pane must survive the near plane
Two things go wrong in the last few centimetres before a portal, and both look like the level has fallen away.

`hug()` on a portal surface squares the pane up to the screen once the eye is within `PANE_CLEARANCE` of it, then puts it back. Without it the camera's `NEAR_PLANE` clips the pane and you see straight past the opening, which has nothing behind it but sky. Covering the screen is honest at that range: a two-metre mouth fills the whole view from closer than about 70cm. It costs nothing because a portal pane reads the far view by screen position, so the picture does not move when the surface does. Call it AFTER the pane passes and only for the player's camera — a pane held in front of the player's face must not turn up in another pane's view. Mirrors never hug; they are solid walls with colliders.

`CLIP_MINIMUM` in portal-surface.ts drops the oblique near-plane tilt when the portal camera is nearly touching the plane it would be clipped against. Tilting onto a plane the camera is already on squeezes the depth range to nothing and everything distant falls out — the far end of the room beyond the portal goes black. Nothing can occupy that last centimetre, so there is nothing for the tilt to do.

## A portal pane only hugs the near plane when the player is looking at it
`hug()` needs both tests: the eye within `PANE_CLEARANCE` of the pane AND the camera actually facing it (`look.dot(faceNormal) < 0`). Distance alone is not enough, and the failure only shows up while walking through rather than standing at the mouth.

`crossPortal` puts the player down `CLEARANCE` (2cm) inside the far room, which is right against that room's own pane, walking away from it. On distance alone that pane is squared up over the player's face for the first few steps, and what it holds is the view from behind the mouth they just came out of — open air, so a screenful of sky. That is the flash people report when walking through a portal, as distinct from the sky you can sit and stare at from the near side.

To reproduce either without pointer lock, move the level's spawn: the near side is `mouth + 1cm` facing the mouth, the far side is where `crossPortal` lands you (for the demo's long-hall, x 21 z -0.07) facing away.

## Release a hugged portal pane before the next frame's passes
`hug()` leaves the pane squared up to the screen at the player's face, and it stays there until something puts it back. `prepareReflections` therefore calls `release()` on every portal at the top of each frame, BEFORE any pane is drawn.

Without it the pane is still parked in front of the player while the render passes run, and every other pane's camera sees a wall-sized sheet hanging in the middle of the room — which is what makes a portal show the sky, or anything else, when the player stands on a boundary. The pane cannot simply be released after the main render, because the main render is the last thing the frame does.

So the order within a frame is: release everything, run the passes deepest-first, `show(0)`, then hug for the player's camera alone.

## The deepest portal pass hides the sky as well as the panes
At `depth >= allowed` the panes are taken out of the view (they would otherwise read the very target being written) — and the sky group has to go with them. A portal mouth is an opening with nothing behind it, so leaving the sky up puts a bright patch of daylight exactly where the next opening should be, at the end of the corridor. That reads as a hole in the illusion far more than darkness does. `prepareReflections` takes the sky object for this and restores its visibility straight after the pass.

Targets are made on demand (`targetAt`), not up front: `PORTAL_BOUNCES` is 8, so a pane that is never seen through another would otherwise hold nine render targets of several megabytes for depth it never reaches. Depth costs nothing until it is used.

`PORTAL_RENDER_BUDGET` bounds the passes per frame, so raising the bounces cannot on its own cost frame rate — it runs out of budget instead, and the tunnel ends shallower.

## A room open to the sky still needs a lid
`is_sky` means no ceiling texture, not no ceiling. `buildSkyCeiling` puts a flat over the sector at its ceiling height with `colorWrite: false` — it writes depth and paints nothing, so the pixels keep whatever the sky dome laid down and everything beyond is cut away. It is not added to `targets`, so the look-at ray passes through it.

Without one, a sky sector is a room with a hole in the roof: sight-lines run out over its walls into whatever else is on the plan. In a level using the Doom trick that is the floor above, sitting right next door in x/z — walk into the yard and you can see the bedrooms.

Draw order matters and is why `SKY_CEILING_ORDER` is -0.5: the sky dome is at -1 and lays down no depth of its own (`depthWrite: false`), the lids go next, and the rooms are at 0. Put the lids after the rooms and the rooms are already painted before anything hides them.

## A ceiling is turned over by reversing its winding, never by rotating it the other way
A floor and a ceiling are the same polygon: `shapeOf` lays the sector out flat and `buildFlat` rotates it a quarter turn about x. Done for both, that leaves a ceiling's normal pointing **up**, exactly like a floor's — free while nothing is lit and every surface is `DoubleSide`, and fatal the moment anything is, because every ceiling in the level then lights as though it were the floor.

**Do not fix it by rotating the other way.** Keeping the polygon where it is while turning its normal over is a reflection, not a rotation, and no rotation can do it: the shape is drawn in a local x/y plane and the quarter turn sends local y to world z, so at `rotation.x = -π/2` world z is `−y` and at `+π/2` it is `+y` — and since `shapeOf` writes each corner as `(x, −z)`, flipping that sign puts every corner at `+z`. The whole room is mirrored in z. The ceiling comes to sit over a floor plan that is not the one underneath it — which reads as rooms subtly not lining up, and nothing about the normal looks wrong while you hunt for it.

`faceDownwards` in `build/flats.ts` reverses the winding of every triangle and recomputes the normals from that instead. The polygon does not move a millimetre, and the front of the face becomes the one underneath — which is what a ceiling drawn `FrontSide` will want when the lighting work arrives, since `DoubleSide` is what is hiding this today. The lid over a room open to the sky is turned as well: it paints nothing and cannot be lit, but a surface that reports which way it faces should not be the one that lies about it.

Pinned by `tests/Unit/FlatNormalsTest.php`, which checks all four parts — floor up, ceiling down, the winding agreeing with the normal attribute, and the ceiling's corners still standing over the floor's.

## Walls are drawn longer than they are, to close the notch at a corner
`buildWall` builds its quad `WALL_INSET * 2` longer than the wall's own length. Every wall is nudged `WALL_INSET` into its own room to stop coplanar faces fighting, and that nudge pulls the two walls at a corner apart from each other: they stop short and leave a notch of about a centimetre with nothing behind it. Stare into a corner and there is daylight in it. Overlapping them by what they were nudged closes it, and the overlap is buried inside the corner. Tile the UVs and the wireframe grid with the drawn length, not the real one, or the texture scale drifts.

Not to be confused with the near plane, which reaches 0.093m from the eye at 75° and 16:9. Corner penetration is not what puts you inside a wall.

**How close the solver lets you get to a wall is not a constant, and no number for it belongs in this file.** A figure of 0.28m used to sit here — "no closer than 0.28m even in a 12-degree wedge" — and it was false: it was one measurement of one approach, written down as a floor. Measured across a sweep, the clearance falls away with the angle of the corner and also depends on how the player arrives at it. Wedges wider than about 60° settle at a full `PLAYER_RADIUS`; sharper than that it is less, and walking straight into a 12° wedge at running speed settles closer than a radius. In a very sharp wedge there is nowhere to put a circle that is a radius from both walls anywhere near the apex — at 3° it would have to stand thirteen metres back — so the solver does not settle the player at all: it pushes them out through the corner.

The numbers live in the wedge sweep in `tests/Unit/CollisionLimitsTest.php`, which fails when they move — it sweeps partial moves as well as full ones, because the case that needs all twelve `RESOLVE_PASSES` is a move that stops the player *inside* the corner rather than one that shoves them out of it. Read them there. A figure copied into prose here is a figure that goes quietly wrong the next time `RESOLVE_PASSES` or `PLAYER_RADIUS` is touched, and being wrong is worse than being absent: the last one was quoted back as a guarantee. Same disease as the three sprite-direction sections above, in a smaller dose.

`RESOLVE_PASSES` in collision.ts is 12 rather than 3 all the same — two walls at a sharp angle need several goes, each pass only halving what is left, and at 3 the player noticeably sank into acute corners.

## A pane's view has to redraw whatever a doorway lets into it
`PortalSurface.onto` is a list of rooms, not one room: the room the pane looks into, plus whatever can be seen from there through an open doorway. `build-level` works it out from the shared edges (open on both sides, the same OR rule the walls use) and `deepen` recurses into any pane whose `home` is in that list.

With a single room the filter was too tight. A mirror one doorway on from a portal's exit never got redrawn for that view, so it kept whatever was last put in it and its reflection sat frozen while the player moved. The same gap is why a mirror could show a stale portal.

One hop only. Two would be more correct and costs another fan-out of passes against `PORTAL_RENDER_BUDGET`; if something two rooms away is visibly stale, that is the knob, not a bug.

## A sky lid is only shown to somebody standing in its own room
`BuiltLevel.skyLids` carries each lid with the slug of the room it covers, and the viewport shows only the one whose room the player is standing in (`sectorAt`).

A lid writes depth, so it hides whatever is behind it from wherever it is seen — not just from inside. Two sky rooms whose floor plans overlap in x/z will otherwise cut each other's rooms away and leave the sky showing through from next door. That is not hypothetical: william-level has `room` (lid at 3m) sitting inside the footprint of `room-2` (ceiling 13m).

The cost is that looking into a sky room from an adjacent one no longer gets the lid, so sight-lines can run out over its walls again from there. Fixing that properly needs the lid confined to the room's own footprint in the depth buffer per viewer, which a single depth-only pass cannot express. Take the trade unless it shows.

## The depth buffer is logarithmic, so every shader must write depth itself
The renderer runs with `logarithmicDepthBuffer: true`. The far plane is worked out per level (extent + tallest thing + tallest ceiling, floor of `FAR_PLANE`), so a level with somebody a hundred metres tall opens up far enough to see all of them — and spread evenly there would not be enough depth left to tell two walls a centimetre apart, which is what `WALL_INSET` makes of every wall.

Anything with a shader of its own has to write the same depth three's own materials do, or it z-fights and sinks through the world. Both custom materials carry the chunks: `logdepthbuf_pars_vertex` and `logdepthbuf_vertex` (after `gl_Position` is set), `logdepthbuf_pars_fragment` and `logdepthbuf_fragment` (first thing in `main`). That is the portal pane in portal-surface.ts and the motes in spells.ts. Write another and it needs them too.

The oblique near plane still works: clipping is done in clip space and the tilt leaves `w` alone, so only the depth written changes. The cost is the early depth test, which is not free but is cheaper than shimmering walls.

## Sky walls, and the player's hands
`level_sector_edges.is_sky` makes a wall show the sky: build-level draws it as a `colorWrite: false` quad, the same trick as the lid over a room open to the sky, stood upright. The dome is drawn first without writing depth, so those pixels keep the sky and whatever stands beyond is cut away. It keeps its collider — a sky wall is still a wall, so the level cannot be walked out of. Sky walls join `skyLids`, so they are only shown to somebody standing in that wall's own room.

`hands.ts` hangs two painted cards off the camera: open while walking, fists while running, swinging with the same tally of metres that picks the sprite's walk frame. Two things it depends on:

- The camera is added to the scene. A child of something outside the scene graph is never drawn.
- `hands.object.visible = false` around `refreshReflections`, or the hands turn up floating in every mirror and portal — a mirror already shows the player's whole body.

`SKIN` holds a colour per person, read off the middle of the skin showing in their own front-facing sprite rather than picked by eye. Anybody new gets `DEFAULT_SKIN` until measured.

## Walls only overhang where they turn a corner
Every wall is nudged `WALL_INSET` into its own room, which pulls corners apart, so `buildWall` (`build/walls.ts`) draws each wall past its ends to close the notch. It must only do that at a real corner: where a wall carries straight on into another one in the same plane facing the same way — a long side split by carving, or by a doorway opposite, whether or not the halves belong to the same room — the overhang put two faces in one plane and they flickered. Level 8 had 51 such strips, some 15 m tall.

`carriedOn` in `build/topology.ts` decides this over the whole level, keyed by corner + direction + inward normal. A per-sector check is not enough: the wall that carries on is often the next room's. Pinned by `tests/Unit/WallOverhangTest.php`, which also asserts no two coplanar faces overlap.

## A portal mouth is one-sided, and the room behind it keeps its wall
`portalLinkOf(edge)` reads the link off *either* face of a wall, so build-level can recognise the far face; `namesPortal(edge)` says which face the author actually set it on. Only that face is a mouth (pane, no wall, no collider). The far face builds its wall as normal.

The trap: a collider is a line on the floor plan and a line has no sides, so the far face's collider used to seal the mouth from the front — level 8's staircase was unreachable from both directions. Hence `SegmentCollider.facing`: the far face's collider only pushes back from its own room. Standing exactly on a one-sided line counts as the open side, because from the solid side you are stopped a whole radius short and can never reach it.

Opening *both* faces instead (an earlier attempt) is worse: it takes the wall away from the room behind, which in level 8 was the ground-floor room under the stairs — a four-metre hole, and anyone walking into it teleported. Do not go back to it.

`build-level` counts distinct walls per link (`boundaryKey`), not faces, so a link still needs exactly two walls. Pinned by `tests/Unit/PortalBoundaryTest.php` and `tests/Unit/OneSidedColliderTest.php`.

## The hand sheets disagree about which hand they draw
Each `{who}-hands-sheet.png` is 1774×887: two 887² cells, open hand then fist, one hand per cell, palm to the viewer and fingers up. The engine draws that card on its own side and mirrors it for the other.

Which hand it is varies by person — the sheets were generated one at a time. Measured off the artwork (which way the thumb pulls the silhouette off the fingers' centre): Paul's is a **right** hand, the other five are **left**. That is the `DRAWN` table in hands.ts. Get it wrong and both hands are wrong at once, since they are mirror images.

Krystal's and Luke's thumbs are almost tucked out of sight, so those two readings are the weakest; suspect them first if a wand turns up in the wrong hand. Pinned by `tests/Unit/HandsTest.php`. Same shape of problem as `ORDERS` in sprite-direction.ts — do not assume one order for all six.

## Hand art: one file per pose, and handedness is per person AND per pose
The engine loads four cards per person — `{who}-edge-open.png` (walking), `{who}-edge.png` (running/gripping), `{who}-back.png` (reaching) and `{who}-back-fist.png` (gripping something in reach) — 887² each. It draws one card as made and mirrors it for the other hand, so which side gets the unmirrored one decides both.

The rule is thumbs inward. The art does not agree with itself: measured off the PNGs (the side carrying the finger outlines; the thumb is the other), Paul's and Wade's fists face the opposite way to their own open hands, and William's pair face the opposite way to everyone else's.

**The back cards disagree differently, and uniformly.** All six `-back.png` have the thumb on the left; all six `-back-fist.png` have it on the right. So every person's open back-of-hand and their own fist face opposite ways — which is not the per-person pattern the edge cards show, and cannot be derived from it. Hence `DRAWN` is `Record<sprite, Record<pose, 1|-1>>` with a row per pose and `scale.x` is set every frame in `update()`, not once at build. Pinned by `tests/Unit/HandsTest.php`.

**How to measure, because two obvious ways are wrong.** Silhouette width finds the *fingers*, not the thumb: edge on, the fingers stick out past the thumb tucked behind them, and taking the wider side for the thumb reproduces 3 of the 12 known answers, which is chance. Which side reaches *higher* does better — fingers are taller than a thumb, 11 of 12 — but collapses on the fists, where the margin falls to nought to five pixels of noise. The twelve back-card readings were settled by looking at the cards.

`public/sprites/hands` holds thirty-six files: `-edge`, `-edge-open`, `-back`, `-back-fist`, `-palm` and `-palm-fist` for the six of them, plus `overlays/`. The engine wires four of the six; `-palm` and `-palm-fist` are drawn and unused. Naming an earlier version of this note listed — `-hands-sheet`, `-open`, `-fist`, `-views-sheet` — is gone from the repo. Same trap as `ORDERS` in sprite-direction.ts: never assume one orientation across the six sheets.

## A pane must hide the room behind its far mouth, not trust the clip plane
The pane's camera stands in the room behind its far mouth, so that whole room is between the camera and the opening — the wall across the mouth, the walls meeting it at the corners, the floor, the ceiling. The tilted near plane (Lengyel) is supposed to cut all of it, but `CLIP_BIAS` leaves a couple of centimetres of slack at the plane, and every wall is drawn `WALL_INSET` past its own corners, so geometry touching the mouth leaks through — as the back of a wall filling the portal, or a sliver down the edge.

`PortalSurface.behind` is that room's meshes, hidden during `render` alongside `partner`. `standingIn()` in build-level picks them from `drawnByRoom`, keeping only what is on the camera's side within `WALL_INSET * 2`, so a room that genuinely wraps past its own mouth's plane (a mouth set in a notch) keeps the parts that should show.

`drawnByRoom` and `remember()` live on the build's scene (`build/scene.ts`), made before any builder runs, so every builder can call `remember()` whenever it likes. They used to be `const`s inside `buildLevel` declared above `buildWall`/`buildFlat`; put lower down, that was a temporal dead zone crash at runtime which tsc would not catch. The split took the trap away — do not put them back inside a function.

## hug() must check the eye is in the opening, not just near its plane
A mouth is a rectangle in a wall, not the whole wall. `hug()` used to test only `|distance to the pane's plane| < clearance`, so it pulled the pane across the whole view **anywhere along that wall's infinite plane** — however far to one side the opening was.

Level 8 has the stairs portal at x 70–72 in the wall z = −18, and the same wall carries a 10 m doorway between room 28 and room 30. Walking through that doorway brought the eye within 12 cm of z = −18, so the pane was slapped over the screen showing the far camera's view of nothing — which read as the sky, a black screen, or "a wall that extends wall 4 of room 29" depending on where you stood. That was the border flicker, and it had nothing to do with which sector the eye was in.

`hug()` now also measures the eye along the pane's own across and up axes (from `restTurn`) and refuses unless it is inside `size/2 + clearance` on both. Pinned by `tests/Unit/PortalBoundaryTest.php`. Any future "the whole view is wrong near a wall" is worth checking here first.

## The wall beside a mouth must reach the top of it
A mouth covers the height of the room that owns it, and that room's floor can sit well above the floor of the room on the other side of the wall — a landing at the top of a staircase, over the room below. The band between the lower room's ceiling and the mouth's own floor belongs to neither, and used to be drawn by nobody.

So the far face builds `sector.floorHeight` → `max(sector.ceilingHeight, beyond.ceilingHeight)`, not to its own ceiling. Pinned by `tests/Unit/MouthSealTest.php`, which also checks it stays one surface rather than two stacked in the same plane.

It shows worst through the portal: inside `CLIP_MINIMUM` of a mouth the tilted near plane is dropped, and the pane's camera then sees straight out through the band — sky above and below the far room, exactly when the pane is hugged across the whole screen. **Do not** try to keep the tilt through that range by pushing the plane forward instead of dropping it: it was tried, and it wedges the GPU hard enough that the page stops painting while its scripts carry on. Close the geometry instead.

## Regenerated edge hands are consistently left-handed
All six `-edge-open` and `-edge` cards were regenerated independently as LEFT hands from the fixed real character references. In `DRAWN`, both `walk` and `run` therefore map to `-1` for every person. The existing back/back-fist mapping remains independently measured.

## Open edge hands use the right-side orientation
The regenerated `-edge-open` cards look correct only when their unmirrored drawing is assigned to the right side (`DRAWN.walk = 1`) for all six people. Their edge-on fists remain assigned left (`DRAWN.run = -1`). This visual engine mapping takes precedence over the handedness wording used during generation.

## Open edge orientation is per person
For regenerated `-edge-open` cards, the unmirrored side is Paul/Wade/Luke = left (`walk: -1`) and Krystal/Luna/William = right (`walk: 1`). All regenerated edge fists currently use left (`run: -1`). These values were checked in-game; do not infer them from prompt handedness.

## Open edge orientation is per person
For regenerated `-edge-open` cards, the in-game checked unmirrored side is Paul/Wade/William = left (`walk: -1`) and Krystal/Luna/Luke = right (`walk: 1`). All regenerated edge fists currently use left (`run: -1`). Do not infer these values from prompt handedness.

## Judge renders with ?scan, not browser screenshots
requestAnimationFrame does not fire in a browser tab that is not foregrounded — and automation (Chrome MCP, headless) tabs usually are not. A screenshot of a level then shows a frame whose render loop never ran, which reads as every mirror/portal black for a reason unrelated to any bug. This cost real time and produced a false F-08 reproduction that had to be retracted. To verify a rendering change, use ?scan: it draws its own frames on a fixed timestep (SCAN_FRAMES via drawFrame in level-viewport.tsx) and reads the buffer back, so it is immune to the tab-focus trap. Add &panes to read what each portal/mirror pane drew at every recursion depth. Screenshots are fine only as confirmation when they show real content; a black result from one proves nothing.

## A ?scan cannot judge whether a mirror looks right
?scan forces the probe backdrop, and in a fully mirrored room every ray legitimately ends in backdrop — so ?scan reads a WORKING infinity mirror as sky and cannot tell it from a broken one. And &panes drawn:1 / drawnTo:N means a target HAS a picture, not that the picture is correct. ?scan is sound for portals and for proving geometry did not move; it is NOT evidence a mirror is fixed. Mirror correctness is judged by a human looking at the real (non-debug) render, with the tab foregrounded. Do not close a mirror bug on scan evidence.

## How deep a chain of panes goes is decided by the opening, not by a budget
`aperture.ts` carries a screen rectangle down the recursion in `reflections.ts`: the whole screen at the top, intersected with each pane's own projected rectangle at every level. A branch ends when the rectangles stop overlapping, or when what is left is under `APERTURE_FLOOR` of the screen.

This replaced a frustum test, and the difference is the whole reason a room of four mirrored walls could not be made deep. A frustum is the entire screen, so every pane saw every other one at every level and the tree branched by three per bounce — sixteen bounces asks for 43 million passes against a budget of 96. The budget then decided *which* branch got the depth rather than how deep the room went, and it decided by position in an array. That is why a perfectly symmetric room came out with one wall deep and three shallow, which no geometry can produce and which Paul found with four captures ninety degrees apart from one spot.

A pane is a hole, not a screen. Two mirrors facing each other keep nearly all of the opening per bounce and run the full `PORTAL_BOUNCES`; a mirror off to one side is a sliver through the first bounce and nothing through the second, so that chain ends itself. Measured in `hall-of-mirrors` from the middle: the straight chain keeps 31% of the screen at the first bounce and 0.17% at the eighth without ever closing, while `north>east>north` closes at the third.

**Screen rectangles compose only because a pane samples by screen position.** The target's screen and the pass that displays it are the same screen, so nesting is intersection. None of this would be true of a `textureMatrix` read.

**A mirror's rectangle is flipped inside its own target** (`flipAcross`). The camera carries a left-for-right turn to stay right-handed, so the picture is drawn flipped and the shader flips it back. Miss this and every chain through a mirror hunts for its reflections down the wrong side of the view — which prunes the branches that were really there and keeps the ones that were not.

**`apertureOf` cuts the box at the near plane edge by edge, and that is load-bearing.** The first version gave up and answered "the whole screen" whenever one corner sat behind the camera. A mirror's camera stands *behind* its own wall and the side walls of the room run past it in both directions, so in a room of mirrors nearly every candidate straddles the camera — and with no rectangle to intersect there is no pruning at all. Measured with the brake off: 42,857 passes to reach nine levels that way, against 662 to reach sixteen with edge clipping. A mesh it cannot measure at all still answers "the whole screen", which is right: too generous costs a pass, too mean loses a reflection silently.

Uncapped, `hall-of-mirrors` costs 662 passes for all sixteen levels and the mirrored octagon 980, and the count per level climbs 4, 5, 8, 12, 17, 23 — near enough linear, which is the ring of virtual rooms the method of images predicts.

## How deep is one number for the whole frame, and it settles
`reach` in reflections.ts is the depth every branch gets, carried between frames and moved one level at a time to hold the cost near `PORTAL_RENDER_BUDGET` (a pass count) and `PANE_MILLISECONDS` (the frame's own wall clock). Being **one** number is the point: a room that cannot afford sixteen levels goes shallower everywhere at once.

The budget used to gate the recursion directly, as a running counter checked at every node. Depth-first, that is an ordering and not a budget: the corridor straight ahead is walked first and drills to `PORTAL_BOUNCES`, and by the time the recursion unwinds to the branches beside it there is nothing left, so they get no kids — and a pane with no kids draws a room with no mirrors in it. Paul: *I can see many mirrors straight ahead, but reflections to the side are showing as walls.* Measured at his spot: 8 of the 12 passes at the first bounce rendered bare walls, 125 of 230 over the frame. **Do not put a per-node budget check back.**

Two things about the controller that were got wrong first: it must start low and climb (starting at `PORTAL_BOUNCES` spends the worst frame on the frame that is also building every texture), and it must only grow when the frame came in **comfortably** under, not merely under — a level costs about a fifth more than the one before it, so growing at the threshold oscillates across it every frame. It did, between nine levels and ten.

Both `PORTAL_RENDER_BUDGET` and `PANE_MILLISECONDS` exist because they bound different things. The count bounds draw calls and memory and is predictable. The clock is what fits the machine: `scaleAt` shrinks a deep target to an eighth so it costs almost no pixels, but it costs a whole scene traversal like any other pass, and that is the part that adds up over six hundred of them.

## No pane ever shows a picture taken from a camera other than the one looking at it
The single rule the pane renderer keeps, and three separate things used to break it. It sounds like a tautology and is not, because a pane samples by screen position: a picture drawn from the wrong viewpoint is not merely stale, it is a different view of the room pasted onto a wall at the wrong angle. Down a corridor of portals two adjacent viewpoints are nearly the same picture and it passes; between two mirrors at right angles it does not, and Paul's word for it both times was *super stretched*.

What broke it:
1. Every pane in the level was shown one level in whether or not this pass had drawn it there. `deepen` now keeps the kids it actually drew and shows only those.
2. At the last bounce a mirror was handed the level *above* it, so the image would fold into itself. That picture is one reflection further out. **A mirror now comes out of the picture instead**, at every depth, and the wall `buildWall` puts a hair behind it is drawn.
3. The cheap pass for a pane the player cannot see showed every other mirror at level 0 — the player's own view — inside a pass drawn from somewhere else. Invisible in a square room where every wall is in view; 24 wrong showings a frame in Paul's mirrored octagon.

**Hiding mirrors at the end was shipped once before and reverted**, because Paul said *i am not seeing a seamless infinite room, i see many walls*. He was right and the walls were not the fault: the tree was starved by the draw budget and ended at the first or second bounce, so the walls landed where he was looking. It is only safe with the opening test above deciding where a chain stops. Do not put the fold-into-itself ending back without also putting the budget starvation back.

A **portal** is the opposite case at every one of these points and must stay in the picture: there is no wall behind a mouth, only the room it opens onto, so taking it out ends a corridor in a hole to the sky.

Pinned by `tests/Unit/MirrorRoomTest.php`, which runs a frame in a square room and in an octagon and audits every showing against the camera the displaying pass aimed. **Compare cameras by identity, never by recomputing the matrix**: `aim` decomposes and re-inverts, so sixteen levels down a chain a camera differs from a recomputation of itself in the third decimal, which reads as ten broken reflections that are not broken.

## APERTURE_FLOOR is the lever for depth, not a safety valve
Raising it from 0.005 to 0.01 (about 20px at 1080p) **more than doubled how deep the illusion goes** while costing a third of the passes. Measured in `hall-of-mirrors`, uncapped: floor 0.005 → 521 passes, 14 bounces; 0.01 → 488 passes, 23; 0.02 → 193 passes, 24; 0.04 → 82 passes, 12.

The reason is where the passes go. At the fourteenth bounce that room drew 68 passes and about four of them were the corridor straight ahead — the only ones big enough on screen to see. The other sixty-four were side chains already a few pixels wide, paid for out of the same frame. Cutting them sooner hands the budget to the chain the eye is following. Too far the other way and side chains end while still plainly visible, and the depth falls with them.

With the floor at 0.01, `PORTAL_BOUNCES` stopped being how deep a mirror goes and became a ceiling: `hall-of-mirrors` reaches 23 bounces and stops on its own, the octagon 33, and raising the constant to 48 or 64 changes nothing. Paul's *I am still seeing walls far off in the reflections* was the old sixteen — an 8m room at sixteen bounces is still about 2% of the screen tall, and only past twenty-three does the opening close.

## The frame depth must move slowly, or every reflection blinks at once
`reach` is one number for the level, so moving it shifts where **every** chain ends simultaneously — and the end of a chain is a wall. Paul: *the walls flicker, they all do not show every frame.*

The first controller grew whenever a frame came in under three quarters of its allowance and shrank the moment it went over, on a lightly smoothed one-frame measurement. One more level costs under a tenth at these depths, so it sat on the line and crossed it every frame. What it needs is all of: heavy smoothing (0.9/0.1), a dead band, and a run of frames on one side before anything moves — `IMPATIENCE` 6 frames to go shallower, `PATIENCE` 30 to go deeper. Quick down and slow up, because one level too deep costs frame rate and one level too shallow costs a little distance at the back of a reflection.

Widening the dead band is *not* the fix and costs real depth: growing only below half the allowance capped the room nine levels short. The counters are what stop the bobbing.

Pinned by `it holds the depth still once it has found it` in MirrorRoomTest, which runs 200 frames after it has settled and asserts the draw count never changes. Any test touching this must run enough frames to settle — 900 from a standing start, since `reach` begins at 2 and climbs one level per 30 frames.

## A chain is bounded by every opening along it, including the first
`deepen` clips the running aperture against the pane's **own** outline at each level (`narrow(shown, apertureOf(pane.partner ?? pane.mesh, inner))`), not just against what the parent passed down. The top-level call starts from `WHOLE_SCREEN`, because that is what the *player* can see rather than what the mirror can, so without this the first bounce accepted any pane anywhere on screen whether or not it was inside the mirror being looked into — and it went down the chain carrying an opening in the wrong place.

The symptom is a pane a level or two in with a **large** opening and no candidates overlapping it at all, so it draws a room with no mirrors and reads as a wall.

**A square room cannot find this.** Measured byte for byte, clipping to the outline changes nothing there: the opposite wall's image exactly fills the mirror showing it, so the candidates already lie inside the outline. In the mirrored octagon one bare pass covered a quarter of the screen at the first bounce, and bare wall came to 16–43% of the view depending on where you stood. With the clip: 2%, worst patch 0.1%, and every spot reaches full depth instead of stopping between 17 and 23. Any future work on the recursion must be checked in the octagon as well as the square room.

Measure `partner`, not `mesh`. A mirror's camera stands behind its own glass so its outline in its own target is itself, and `buildMirrorPane` sets `partner` to its own mesh — so this reads correctly for both kinds. A portal's camera stands at the **far** mouth, and it is that mouth the view is bounded by.

## A depth the level has failed at is never offered again
`ceiling` in reflections.ts. Once a frame goes over budget, `reach` drops a level and **that level stops being on offer** — the climb can reach one below it and no further.

Patience does not fix oscillation, it only sets the period. Paul, standing perfectly still in the middle of his four-mirror room: *the walls still flicker when the user is not moving.* Every chain in a level ends at the same depth, so moving `reach` moves every ending at once, and that is every reflection in the room changing together about twice a second.

**That report contradicted a measurement here that found zero movement over 239 frames with the camera fixed, and the measurement was what was wrong.** Its stub panes rendered nothing, so a pass cost no time, so the clock half of the controller never fired — it measured a machine on which the budget can never bind, which is not a machine anybody plays on. `tests/Unit/PaneDepthTest.php` burns real time per pass with noise on top, and that is the only way this class of fault shows. **Any harness for the depth controller must cost time, or it is measuring nothing.** With a realistic cost the old controller swung between six levels and seven on six frames of 199.

The ceiling lifts again, or a player walking out of a hall of mirrors into a corridor would be held at the hall's depth for the rest of the level — but only on evidence the room has really changed: cost under **half** its allowance for three seconds running (`ROOMIER`). Ordinary noise cannot look like that.

Also ruled out cheaply and worth not re-checking: `input.running()` reports the shift key, not motion, and it feeds only the hand pose. It never reaches the camera or the pane pipeline, so the run state cannot affect what the mirrors draw.

## The depth must climb fast and settle slow
`reach` starts at 2. Half a second of patience per level is right for holding it still and hopeless for arriving at it: thirty frames a level is **fifteen seconds** to reach the twenty-odd a mirror room affords. Over that ramp in `hall-of-mirrors`, bare wall covers 20.7% of the screen on the first frame, 12.4% after a second, 2.9% after five and 1.1% once settled — so a player spends the whole ramp looking at a room with walls in it, which is what Paul reported after the flicker was fixed.

Nothing is known about what a room costs until a frame has gone over budget, so until then there is nothing to be careful of: climb a level a frame (`hasBeenOver` is false) and find the ceiling in well under a second. After the first overrun, `PATIENCE` applies. A room that never goes over never becomes patient and simply climbs to `PORTAL_BOUNCES`, which is correct — it can afford it.

## Where a chain of reflections stops, the room stands there — not a wall
`build/images.ts` hangs a reflected copy of each mirror's own room behind it (`pane.image`), hidden until a pass takes that mirror out of the picture. `prepareReflections` shows it exactly where it hides the pane.

A chain has to end somewhere, and a pane cannot show a level nobody drew without showing a picture taken from the wrong viewpoint, which is the one thing this renderer will not do. What is behind a mirror otherwise is the wall it hangs on. Paul, once everything else was fixed: *no black mirrors or stretching, only bare walls where mirrors should be.*

Neither obvious answer works. **Depth does not fix it**: the levels run until the openings close on their own — 23 in his 8 m room — and there is still a wall at the end, a little smaller. A corridor of mirrors does not shrink to nothing. **Fading does fix it and is ruled out**, twice and explicitly: a mirror that loses light is not what he asked for.

A mirror's image of a room is a *real place* — the method of images, the same fact the mirror camera is built from — so a reflected copy of the room's geometry standing at that image is not a stand-in for the continuation, it is the continuation. Correct from every camera at every depth, because the virtual cameras do all the work and this is geometry standing where geometry belongs. It costs no passes: depth costs a render per level per pane, this costs one draw of cloned meshes in the passes where a mirror has come out.

Load-bearing details:
- `buildMirrorImages` runs **last** in `buildLevel`. A room's image is a copy of everything that room drew, and the last of that is not there until the last edge is built.
- The reflection comes from `reflectionIn` in mirrors.ts, shared with the mirror camera. If the two ever disagree, the room beyond the glass is not the room the glass shows.
- **Sky lids are excluded.** A lid paints nothing and writes depth; a copy of one hangs a hole in the image with everything past it cut away.
- `clone()` shares geometry and material, so an image costs objects and not buffers — and retexturing a room retextures its reflections with nothing to keep in step.
- **Never shown for the pane being drawn.** Its own image sits between its camera and the glass, and the tilted near plane is only mostly able to cut it away — the same slack that made `behind` necessary.
- It buys one level, not infinity: the copy has no mirrors in it. What makes that worth having is where the level is bought — at the very back, where "more room" versus "a slab of plaster" is the whole difference.

## Hysteresis on the opening, because one threshold cannot be steady for a moving viewer
`APERTURE_HOLD` gives the opening test two lines: a chain already followed last frame carries on until it is at half the floor, a new one must reach the full floor to start. `followed` in reflections.ts is the frame number per pane per depth that remembers which.

Without it an opening drifting across a single line is followed, dropped and followed again frame to frame, and because the end of a chain is a wall that reads as a wall blinking on and off. Paul, running through the middle of his four-mirror room: *the far walls in the reflection flickering, the walls show sometimes.* Measured at his exact spot and heading: standing still nothing moves at all over four seconds; running, panes crossed the line 0.3 times a frame. With two lines, 0.1. **Reproduce this by moving the camera, never standing still** — a still-camera measurement says everything is perfect, and it is the one this bug hides from.

Depth now depends slightly on what ran last frame, so the symmetry test asserts the four walls are within a level of each other rather than identical.

## The redundancy is real and is NOT safely exploitable — do not try again
Reflections compose into a group, and in a room of two pairs of **parallel** walls the pairs commute: left-then-front and front-then-left are the same element, so they stand the camera in the same place. Measured at Paul's spot, **31–33% of every pass in a four-mirror room re-drew a picture that pane's target already held**, and skipping those subtrees took a frame from 528 passes to 293. It looks like free depth. It is not.

**A pane's picture is not a function of the viewpoint alone.** What goes into it is the set of panes this pass drew one level in, and that set comes from the *aperture*, which is clipped by every mirror along the chain — so two routes to the same place arrive with different openings. If the narrow chain draws first, the wide chain finds "this viewpoint" in the target and takes a picture with the panes outside the narrow sliver missing. Paul, within one build: *I think I see more empty walls now.*

Both repairs were measured and both are worse than not doing it:
- **Require the stored opening to contain the asking one.** Correct, and saves exactly nothing — two chains' openings overlap without either containing the other, so the hit rate goes to zero and the frame is identical to no caching at all.
- **Redraw for the union of the two openings.** Correct, and actively worse: widening apertures grows the tree. Measured at his spot — 531 passes against 520, depth 16 against 20, and bare wall 2.9% against 1.4%.

The measurement that hid the fault is worth knowing about too: the bare-wall metric counts passes where *every* pane is hidden, and this bug produces passes where *some* pane is wrongly hidden. It read as an improvement (1.4% → 0.45%) while the picture got worse. He saw it; the number did not.

If anyone attacks this again, the exploitable form is temporal rather than spatial — a reflection twenty levels down changes very little between frames — and it has not been tried.

## Superseded: the redundancy worth taking (reverted, see above)
Reflections compose into a group, and in a room of two pairs of **parallel** walls the two pairs commute — left-then-front and front-then-left are the same group element, so they put the camera in the same place and draw the same picture. `written`/`writtenOn` in reflections.ts keep the viewpoint each pane's target was last drawn from, and `deepen` returns immediately when the target already holds this viewpoint's picture, skipping the whole subtree: a pane's picture is decided entirely by the viewpoint, so if the target has it, everything that made it has already run.

Measured at Paul's spot in a settled frame: **31–33% of passes in a four-mirror room re-drew a picture the target already held**, and skipping the subtrees took the frame from 528 passes to 293 and from 514 to 261 — over 40%, because a skip takes descendants with it.

**Worth exactly zero in the mirrored octagon, measured.** That is the same fact from the other side: no two of its walls are parallel, so no two generators commute and every chain really is a different place to stand. Do not expect this to help a room without parallel walls.

Two things it must do and one it must not:
- Compare against the **most recent** write, not merely some write this frame — another chain may have drawn that pane at that depth from elsewhere in between.
- Keep sixteen numbers and compare them, rather than hashing, so a collision cannot quietly hand a pane the wrong room. Tolerance 1e-5, because the same viewpoint reached by two routes has been through two chains of decompose-and-invert.
- **Within one frame only.** Across frames the camera can be still while the people in the room are not, and a reflection skipping its redraw because the viewer had not moved would be a frozen one.

Note for tests: this made `MirrorRoomTest`'s camera-identity audit wrong, because the skip matches viewpoints by *value* on purpose. That audit now compares matrices with the same tolerance the renderer uses.

## Tests about the depth controller must not assert what the machine decides
`reach` is held against the frame's own wall clock, so anything asserting a particular depth, or that the depth never changes, is asserting something about the machine and will pass alone and fail in the full suite — where six hundred other tests are on the same cores. This has now bitten twice.

Assert the invariant instead of the value:
- **Never climbs back** to a depth already given up on (`ups === 0`). Going shallower is the controller working; climbing back is the swing Paul saw.
- Depth at least 3, not at least 8 — enough to say a room of mirrors got a corridor rather than a single bounce.
- The exact "never moves at all" claim belongs only in `PaneDepthTest`, where the cost per pass is set deliberately rather than observed.

## There is no draw budget, and there must not be one
Paul: *what happens when we remove the budget? safety for the engine should be the level designer's job.* What bounds a frame is `aperture.ts` and nothing else — a branch ends where its reflection stops overlapping the one showing it — with `PORTAL_BOUNCES` (48) as a backstop the geometry reaches first: measured, the square room's chains close at 38 and the octagon's at 33, and raising the constant to 64 changes nothing.

**Both budgets that came before were sources of the faults they were meant to prevent**, and each was found by him rather than by a measurement here:

- A running count of passes spent depth-first is an *ordering*. The corridor is walked first and drills to the bottom; the branches beside it meet an empty purse and draw a room with no mirrors in it. 8 of the 12 passes at the first bounce rendered bare walls.
- One depth for the whole frame, moved between frames to hold the cost near a target, fixes that and buys a swing: every chain ends at that depth, so moving it moves every ending at once. That is *the walls flicker*, and then — after patience was added — *the walls still flicker when the user is not moving*, because a controller that can climb back to a depth it has already failed at oscillates whatever its patience.

What removing it costs, measured in his four-mirror room: 636 passes a frame against 520, for depth 38 against 20 and slightly less bare wall. Full depth on the **first** frame with no ramp — the old controller took fifteen seconds to climb, and over that ramp bare wall covered a fifth of the screen on the first frame and a twentieth after five seconds, which is what a player actually walked in on.

The property worth protecting: **nothing that varies between frames while the room does not may reach how deep a chain goes.** That is why the flicker cannot come back. `PaneDepthTest` swings the frame cost by a factor of forty and asserts the depth does not move at all.

A consequence to expect rather than fix: with nothing capping every chain to one number, four walls of a room settle at *different* depths — 38, 37, 36, 35 from an off-centre spot — because each corridor is a different length. That is the geometry talking, and the old uniform cap was hiding it. `MirrorRoomTest` asserts the depths are within a factor of two of each other, which still catches the real failure (one wall with a corridor, the others with a single bounce) and no longer asserts a budget that is gone.

## A pane pass draws its own window, not the whole frustum
Each pass renders only the rectangle its picture will be read back through, into a target sized for that rectangle at screen density, with the projection cropped to match (`crop` in portal-surface.ts, `window` threaded through `deepen`/`render`/`show`). A pane covering 21×12 pixels gets a 21×12 target at any depth.

**What this replaced, and why `scaleAt` was wrong in principle.** The old rule halved a target every couple of levels down to a sixteenth, reasoning that a distant reflection is a few pixels across and need not be drawn at screen size. That is right for a pane that reads its target *projectively* — mapping the whole target onto the whole pane. This one reads by **screen position**, so the pane's own shrinking and the target's shrinking compound into a plain magnification. Worked out for a four-mirror room at 1080p:

| depth | pane on screen | texels it read | magnified |
| --- | --- | --- | --- |
| 12 | 53×30 px | 6 | ×16 |
| 20 | 32×18 px | 2.2 | ×16 |
| 30 | 21×12 px | **1.0** | ×16 |

Paul: *walls with distorted or stretched images far from the camera into the mirror.* A 21×12 patch drawn from one texel is a flat smear. Removing the draw budget took depth from 20 to 38 and put far more of the picture into that regime, which is why it appeared when it did.

It costs **less** memory, not more — the old scheme paid full screen size for the first three levels of every pane whatever they covered. Roughly 22MB across a four-mirror room against ~128MB.

Load-bearing details:
- Sizes are rounded up to powers of two. The window moves with the player and a render target that resizes is a render target that reallocates.
- Cropping touches rows 0 and 1 of the projection; Lengyel's oblique tilt replaces row 2. They never meet, so the order is free.
- The **read mapping is affine and composed on the CPU** (`show`), not branched on in the shader: undo the displaying pass's crop, apply the mirror's left-for-right turn, apply the target's crop. The old `mirrored` uniform is gone — a flip is a scale of −1 in that same mapping.
- `show` must be told the window of the pass about to display the pane. Its default is the whole view, which is right only for the player's own pass.

## Two ways the window change can halt the page, both now guarded
Cropping a projection onto a window divides by that window's span, and the openings handed down the recursion **have no floor under them**: `narrow` returns a rectangle whenever two overlap at all, so a chain grazing the edge of a mirror produces a span of a millionth and a crop factor in the millions. The result is a projection whose numbers no longer mean anything, and the symptom is not a wrong picture — it is the page ceasing to paint while its scripts carry on. Paul: *the game crashed... looks like the game halts at some point.* Same failure this engine met once before from pushing a clip plane forward. `NARROWEST` (0.02 NDC, about 20px) widens a window about its own middle before anything divides by it; pinned by `PaneWindowTest`.

The second way is `setSize`, which throws a texture away and makes another. The window moves with the player, so a target size tracking it exactly reallocates every frame for every level of every pane — hundreds of textures a frame, which a driver will not forgive. Target sizes therefore **grow and never shrink**, and are rounded to powers of two on top of that.

`APERTURE_FLOOR` is also a playability lever and not only a quality one: with targets sized to their opening, raising it costs nothing in sharpness and cuts passes hard. Measured in a four-mirror room — 0.01: 693 passes, depth 38. 0.015: 390 passes, depth 25. 0.02: 249 passes, depth 18. 0.03: 139 passes, depth 12. It sits at 0.015, which is deeper *and* cheaper than the draw-budget era ever managed (depth 20 at 520 passes).
