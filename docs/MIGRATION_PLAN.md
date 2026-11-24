# KIYU Migration Plan

## Baseline strategy

The development database contained only test data, so the approved strategy is a
**fresh baseline**: the schema is rebuilt to the target shape in one coherent
migration set. Production will never receive a destructive reset; it receives
forward migrations.

## Current state

- Migrations:
  - `0001_01_01_000000_create_users_table` — framework tables (password resets, sessions)
  - `0001_01_01_000002_create_jobs_table` — queue/cache support
  - `2026_10_28_051217_create_personal_access_tokens_table` — Sanctum
  - `2026_10_28_060000_create_kiyu_domain_schema` — the consolidated domain schema
  - `2026_11_25_000001_create_support_accesses_table`
  - `2026_11_25_000002_create_platform_settings_table`
  - `2026_11_25_000003_relax_plan_price_uniqueness` — deactivate-not-mutate price history

## Destructive reset (local only) — procedure

1. Verify the target: `php artisan tinker --execute="echo config('database.connections.mysql.database');"`
   must print `kiyu` on `127.0.0.1`.
2. Back up:
   `mysqldump -u root -h 127.0.0.1 --single-transaction --set-gtid-purged=OFF kiyu > storage/backups/kiyu-<date>.sql`
3. Verify the backup restores into an isolated database (create
   `kiyu_backup_verify`, import, compare table/row counts, drop it).
4. `php artisan migrate:fresh --seed`
5. Do this only against the disposable local database, never staging/production.

## Production

- Forward-only migrations. Never edit a migration already deployed.
- Additive changes first; consolidate only in a disposable baseline.
- Financial history (`payments`, `billing_webhook_events`) is protected by
  restrict-delete foreign keys and is never cascade-deleted.

## Rollback

Each migration has a `down()`. For a price-index change, re-adding the old
four-column unique index is only valid when no superseded rows coexist; a
production rollback must deactivate/clean duplicates first.
