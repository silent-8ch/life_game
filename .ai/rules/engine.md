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
Each pane holds `PORTAL_BOUNCES + 1` render targets, one per depth, and `show(depth)` picks which the pane displays. `deepen()` in level-viewport draws depth-first — the panes visible from this pane's own camera are drawn one level in FIRST, then this pane. One target per surface cannot do this: a nested draw would overwrite the view the player is meant to see.

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

## Mirror paired sprite directions in UVs
Sprite atlases use five canonical views. Render 225°, 270°, and 315° by reusing the 135°, 90°, and 45° cells with a negative horizontal texture repeat; keep front and back unmirrored.

## Mirror paired sprite directions in UVs
Sprite atlases use five canonical views. The diagonal artwork's handedness is opposite the viewer-angle sign: render 45° and 135° mirrored, but 225° and 315° unmirrored. Render 270° by mirroring the 90° cardinal cell; keep front and back unmirrored.

## Diagonal handedness correction
This supersedes the earlier `Mirror paired sprite directions in UVs` note that said 225°/315° are mirrored. Correct mapping: 45°/135° mirrored; 225°/315° unmirrored; only the 270° cardinal side is mirrored.

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

## Walls are drawn longer than they are, to close the notch at a corner
`buildWall` builds its quad `WALL_INSET * 2` longer than the wall's own length. Every wall is nudged `WALL_INSET` into its own room to stop coplanar faces fighting, and that nudge pulls the two walls at a corner apart from each other: they stop short and leave a notch of about a centimetre with nothing behind it. Stare into a corner and there is daylight in it. Overlapping them by what they were nudged closes it, and the overlap is buried inside the corner. Tile the UVs and the wireframe grid with the drawn length, not the real one, or the texture scale drifts.

Not to be confused with the near plane: measured, the near plane reaches 0.093m from the eye at 75° and 16:9, while the collision solver settles the player no closer than 0.28m to a wall even in a 12-degree wedge. Corner penetration is not what puts you inside a wall.

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
Every wall is nudged `WALL_INSET` into its own room, which pulls corners apart, so `buildWall` draws each wall past its ends to close the notch. It must only do that at a real corner: where a wall carries straight on into another one in the same plane facing the same way — a long side split by carving, or by a doorway opposite, whether or not the halves belong to the same room — the overhang put two faces in one plane and they flickered. Level 8 had 51 such strips, some 15 m tall.

`carriedOn` in build-level.ts decides this over the whole level, keyed by corner + direction + inward normal. A per-sector check is not enough: the wall that carries on is often the next room's. Pinned by `tests/Unit/WallOverhangTest.php`, which also asserts no two coplanar faces overlap.

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
The engine loads `{who}-edge-open.png` (walking) and `{who}-edge.png` (running/gripping) — 887² each, one hand seen edge on. It draws one card as made and mirrors it for the other hand, so which side gets the unmirrored one decides both.

The rule is thumbs inward. The art does not agree with itself: measured off the PNGs (the side carrying the finger outlines; the thumb is the other), Paul's and Wade's fists face the opposite way to their own open hands, and William's pair face the opposite way to everyone else's. Hence `DRAWN` is `Record<sprite, Record<pose, 1|-1>>` and `scale.x` is set every frame in `update()`, not once at build. Pinned by `tests/Unit/HandsTest.php`.

Superseded and now unused: `{who}-hands-sheet.png`, `-open`, `-fist`, `-back`, `-views-sheet`. Same trap as `ORDERS` in sprite-direction.ts — never assume one orientation across the six sheets.

## A pane must hide the room behind its far mouth, not trust the clip plane
The pane's camera stands in the room behind its far mouth, so that whole room is between the camera and the opening — the wall across the mouth, the walls meeting it at the corners, the floor, the ceiling. The tilted near plane (Lengyel) is supposed to cut all of it, but `CLIP_BIAS` leaves a couple of centimetres of slack at the plane, and every wall is drawn `WALL_INSET` past its own corners, so geometry touching the mouth leaks through — as the back of a wall filling the portal, or a sliver down the edge.

`PortalSurface.behind` is that room's meshes, hidden during `render` alongside `partner`. `standingIn()` in build-level picks them from `drawnByRoom`, keeping only what is on the camera's side within `WALL_INSET * 2`, so a room that genuinely wraps past its own mouth's plane (a mouth set in a notch) keeps the parts that should show.

`drawnByRoom` must be declared **above** `buildWall`/`buildFlat` — they call `remember()` during the first build pass, and declaring it lower down is a temporal dead zone crash at runtime that tsc will not catch.

## hug() must check the eye is in the opening, not just near its plane
A mouth is a rectangle in a wall, not the whole wall. `hug()` used to test only `|distance to the pane's plane| < clearance`, so it pulled the pane across the whole view **anywhere along that wall's infinite plane** — however far to one side the opening was.

Level 8 has the stairs portal at x 70–72 in the wall z = −18, and the same wall carries a 10 m doorway between room 28 and room 30. Walking through that doorway brought the eye within 12 cm of z = −18, so the pane was slapped over the screen showing the far camera's view of nothing — which read as the sky, a black screen, or "a wall that extends wall 4 of room 29" depending on where you stood. That was the border flicker, and it had nothing to do with which sector the eye was in.

`hug()` now also measures the eye along the pane's own across and up axes (from `restTurn`) and refuses unless it is inside `size/2 + clearance` on both. Pinned by `tests/Unit/PortalBoundaryTest.php`. Any future "the whole view is wrong near a wall" is worth checking here first.

## The wall beside a mouth must reach the top of it
A mouth covers the height of the room that owns it, and that room's floor can sit well above the floor of the room on the other side of the wall — a landing at the top of a staircase, over the room below. The band between the lower room's ceiling and the mouth's own floor belongs to neither, and used to be drawn by nobody.

So the far face builds `sector.floorHeight` → `max(sector.ceilingHeight, beyond.ceilingHeight)`, not to its own ceiling. Pinned by `tests/Unit/MouthSealTest.php`, which also checks it stays one surface rather than two stacked in the same plane.

It shows worst through the portal: inside `CLIP_MINIMUM` of a mouth the tilted near plane is dropped, and the pane's camera then sees straight out through the band — sky above and below the far room, exactly when the pane is hugged across the whole screen. **Do not** try to keep the tilt through that range by pushing the plane forward instead of dropping it: it was tried, and it wedges the GPU hard enough that the page stops painting while its scripts carry on. Close the geometry instead.

## Footsteps and room tone are counted in metres, and every audio file is optional
`engine/audio.ts` owns all sound. Footsteps fire off the same `walked` tally that picks the sprite's walk frame and swings the hands, not off a timer: `STEP_METRES` is half of hands.ts's `STRIDE` (0.55 m) and `STEP_OFFSET` is a quarter of it, so a foot lands where the swing is at its extreme. Change `STRIDE` in hands.ts and this has to change with it, or a step is heard between the strides it belongs to. `createPace` is deliberately pure arithmetic so the timing is testable (tests/Unit/FootstepsTest.php); it returns 0 rather than a burst when `walked` goes backwards, which is what a wizard's recall does.

No audio file is committed. Everything resolves by name under `public/audio` — `steps/{surface}.mp3` (surface from `surfaceOf`, floor texture keywords → one of `SURFACE_SOUNDS`) and `ambience/{name}.mp3` (a sector's nullable `ambience` column) — and a name that 404s marks itself dead on the first failure and is never asked for again. A level with no sound must behave exactly as it did before, with nothing in the console.

Nothing plays before the player has interacted: `audio.start()` hangs off pointer lock (or the tap that starts the level on a phone) in level-viewport, never earlier. There is no settings UI in the game, so mute is the N key plus a touch button, remembered in localStorage under `life-game:muted`. M was already the wizard's mark.
