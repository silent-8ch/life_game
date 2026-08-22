---
paths:
  - 'resources/js/lib/editor/**'
---

# Editor

## Map editor: corners are shared by position
A room is a polygon of corners, and two rooms are joined because they name the same corner coordinates — never by an id. So dragging a corner in the map view calls `moveCorner`, which moves every point at that position in every room; moving only the clicked one would silently tear a doorway apart.

`LevelWriter` rebuilds the `level_vertices` rows on every save, keyed by rounded coordinates, so the sharing survives a round trip. Anything that edits geometry should go through these helpers rather than mutating a sector's points directly.

New rooms are wound so the signed area is positive (`windingOf`), because the engine uses the winding to work out which side of a wall faces into the room.

## Rooms never overlap: closing a drawn room carves it out of the others
Closing a shape in the map editor calls `carveRooms` (carve.ts), which subtracts the new room from every room it overlaps using `polygon-clipping`, then runs `weldCorners`.

A sector is one closed loop with no holes, so a room drawn wholly inside another leaves a ring the model cannot hold; that remainder is cut into horizontal slabs and each slab becomes its own room (box-in-box gives four). A room completely covered by the new one is deleted.

`weldCorners` inserts a corner wherever one room's corner lands partway along another's wall. Without it a cut leaves a T-junction: the rooms touch but do not name the same pair of corners, so the engine never sees a doorway (corners are shared by position, never by id).

Carved edges that survive from an old wall keep its texture and its solid/mirror flags; edges made by the cut start open and untextured, so the new boundary is a doorway by default.
