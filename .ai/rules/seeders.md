---
paths:
  - 'database/seeders/**'
---

# Seeders

## Adding a new adventure
Each adventure is a `Game` row; scenes and items are scoped by `game_id` and their slugs are only unique within a game. To add one, write a seeder that `use`s `Database\Seeders\Concerns\AuthorsGames` (game/scene/item/hotspot/interaction helpers), set `starting_scene_id` on the game once its first scene exists, then register the seeder in `DatabaseSeeder`. No engine code needs to change — interactions are data, not PHP.

## Authoring a first-person level
A game is first-person when it has a `starting_level_id`, and point-and-click when it has a `starting_scene_id`; GameController::show picks the page from that. Levels are authored with the level/wall/thing helpers in the AuthorsGames concern.

**Authored levels use the helpers. An exported one is JSON beside its seeder and says so at its top.** Seventy-five rooms and four hundred walls as literal PHP arrays is several thousand lines nobody can review a change to, so `LevelEightSeeder` reads `data/level-8.json` and walks it. That is the exception and not the pattern: level 8 exists in this repo because nearly every rule in `engine.md` cites it and none of them could be reproduced without it. New levels are authored, not exported — if you find yourself reaching for JSON to write something that does not already exist, you want the helpers.

Doorways and windows are gaps between wall runs, not properties of a wall: author the runs either side, add a low run for a sill and a high run for a lintel, then put a thing in the hole. Mark a lintel not solid — collision ignores height, so a solid one blocks the doorway underneath it.
