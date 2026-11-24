# KIYU API

Central Laravel API for the KIYU queue-management SaaS. One backend serves the
marketing site, the Platform Portal (`app.kiyu.test`), every tenant workspace
(`{tenant}.kiyu.test`), and device clients (kiosk/display).

## Docs

- [docs/ARCHITECTURE.md](docs/ARCHITECTURE.md) — hosts, roles, auth contexts, authorization layers
- [docs/DATABASE_DESIGN.md](docs/DATABASE_DESIGN.md) — tables and integrity rules
- [docs/API_CONTRACT.md](docs/API_CONTRACT.md) — endpoints and payloads
- [docs/LOCAL_DEVELOPMENT.md](docs/LOCAL_DEVELOPMENT.md) — local setup
- [docs/MIGRATION_PLAN.md](docs/MIGRATION_PLAN.md) — baseline and destructive-reset procedure

## Quick start

```bash
composer install
cp .env.example .env && php artisan key:generate
php artisan migrate --seed
php artisan test
```

The API is served at `http://api.kiyu.test/api` locally. See
`docs/LOCAL_DEVELOPMENT.md`.

## Tests

```bash
php artisan test
```

The suite covers authentication, application-context isolation, tenancy,
facility authorization, queue lifecycle, service transfer (staff-only), device
quotas, billing, platform administration, and public signup.
