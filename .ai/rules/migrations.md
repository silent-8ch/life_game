---
paths:
  - 'database/migrations/**'
---

# Migrations

## The app runs on MySQL and the tests run on SQLite
`.env` is MySQL (`life_game`); `phpunit.xml` is SQLite `:memory:` so the gate stays fast and needs no server. That gap is real and has already bitten twice, so before finalising a migration run `composer run test:mysql` — it makes `life_game_testing` and runs the whole suite against MySQL.

Two differences found so far, both worth knowing:

1. **MySQL will not drop an index a foreign key needs.** SQLite will not drop a column an index names, so a migration that drops a composite index to free a column passes on SQLite and fails on MySQL. MySQL does not need the drop at all — removing the column rewrites the index down to the remaining columns. Branch on `Schema::getConnection()->getDriverName()`.
2. **MySQL's `json` column reorders an object's keys; SQLite keeps the text.** So assert on JSON-backed attributes with `toEqual`, never `toBe` — key order is not part of what the value means.

Use `php artisan db:copy` to move rows between connections. It fills tables that already exist and never touches `migrations`, so run `migrate` on the target first.
