---
paths:
  - 'database/seeders/data/**'
---

# Data

## Levels people drew are left as drawn, wade-wade-wade included
Paul's final ruling: do not delete or "fix" rooms in levels drawn in the editor — new-level, new-level-for-children, william-level, wade-wade-wade, will-world, will. This has been re-litigated twice and is settled.

That covers the two zero-height rooms specifically: new-level/room-11 (floor 10, ceiling 10) and wade-wade-wade/room, which is that level's ONLY sector — deleting the degenerate room there deletes the level. Both are named exceptions in tests/Feature/LevelGeometryTest.php so a THIRD flat room fails the build rather than joining them quietly.

Authored levels are different: level-8's room-11 and room-12 were carve leftovers and were removed. The test is height (ceiling equals floor), never area — an area threshold flagged 15 sectors of which 13 were load-bearing wall slivers, and missed both real defects.
