---
paths:
  - 'app/Services/**'
---

# Services

## Effect slugs resolve within one game
`EffectApplier` resolves every item/scene/hotspot slug against `$state->game_id`, so two games may reuse slugs. An unknown slug throws `RuntimeException` rather than failing quietly — authoring typos should break loudly in tests. Hotspot slugs are per scene; prefix with `scene-slug/` to target another scene in the same game.

## A map save owns the people and furniture as well as the shape
`LevelWriter::save()` now replaces `level_things` wholesale from `$map['things']`, the same way it replaces the sectors. The editor sends the full set every time, and `UpdateLevelMapRequest` marks `things` as `present` — so a save that omits the key is rejected rather than quietly emptying the level of its people.

Nothing outside a level points at a thing by id, so rebuilding the rows loses nothing. Slugs must be unique within the level and an actor must name a sprite; both are checked in the request's `after()` hook.

`LevelAssets::HEIGHTS` is the one place a person's height is written down, and `household()` is everyone but `LevelAssets::PLAYER`. `LevelStarter` stands them around the starter room when a level is created. The seeders still carry their own literals; if you touch heights, check `TechDemoLevelTest` and `resources/js/pages/editor/level.tsx`, which both pin the same numbers.

## Who the player is belongs to the level, and they are one of the six
`levels.player_sprite` names the sheet the player is drawn from, picked in the map editor's level panel and defaulting to `LevelAssets::PLAYER` ('paul'). Nothing in the engine decides it any more — `sprite-actor.ts` exports `HEIGHTS` and `DEFAULT_PLAYER_HEIGHT` instead of a hardcoded PLAYER_SPRITE, and `level-viewport` reads the name and height off the level.

`LevelAssets::HEIGHTS` now covers all six, Paul included, and `household()` returns all six rather than everyone-but-Paul. A level can therefore have Paul wandering about while you are playing as him — that is deliberate. `LevelStarter` populates a new level with all six.

Two copies of the heights exist by necessity, one either side of the wire: `LevelAssets::HEIGHTS` for the people a level places, and `HEIGHTS` in engine/sprite-actor.ts for the player, whose height comes from the level's choice rather than from a thing standing in a room. Change one and change the other; `NewLevelTest` pins the order.

## A level thing owns interactions, and a map save rebuilds them
`interactions` now has two nullable owners, `hotspot_id` and `level_thing_id`, exactly one set. `InteractionResolver::resolve()` takes `Hotspot|LevelThing`; `EffectApplier` throws if a hotspot-targeting effect (reveal/hide) sits on an interaction with no hotspot.

`LevelWriter::writeThings()` deletes and recreates a level's things on every save, so their interactions go with them. The editor therefore has to send the full tree every time — `things.*.interactions` in the map payload — or they are gone. Item slugs in `requiredItem` and in give/remove effects are resolved against the level's own game and validated in `UpdateLevelMapRequest::checkItemsExist()`.

`LevelPayload::forEngine()` ships only `verbs` per thing: `[{verb, item}]`, deduped. Conditions and effects go out solely from `forEditor()`, which the map editor alone gets. Never widen that — the player has no business seeing what unlocks a door.

## Starting stats live in PersonStats, and a person's block is all-or-nothing
`PersonStats::STARTING` is the one place a person's starting SPECIAL block is written down, the same way `LevelAssets::HEIGHTS` is for their height, and it must cover every key of `HEIGHTS` (pinned by `PersonStatsTest`). The seven names are mirrored on the other side of the wire in `resources/js/types/game.ts` (`Stats`) and `components/editor/inspector.tsx` (`ATTRIBUTES`); change one and change the others.

`level_things.stats` is a per-person override: null means "use the sprite's block", and a value must be all seven keys — there is no partial merge. `LevelThing::stats()` resolves it. `forEngine` ships the resolved block per actor plus a top-level `playerStats`; `forEditor` replaces `stats` with the raw override and adds `inheritedStats`, because the editor sends `things.*.stats` straight back and a resolved block there would silently turn every person into an override.

Nothing reads the numbers yet. No derived values, no condition or effect type, no mutable store — that boundary is deliberate.
