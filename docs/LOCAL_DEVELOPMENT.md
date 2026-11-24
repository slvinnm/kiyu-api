# KIYU Local Development

Windows + Laragon (nginx) + Node. One Laravel API, one Next.js server, multiple
hostnames. See `app-kiyu/docs/LOCAL_DEVELOPMENT.md` for the frontend/nginx side.

## Backend (`C:\laragon\www\kiyu`)

```bash
composer install
cp .env.example .env
php artisan key:generate
php artisan migrate --seed
php artisan serve            # or let Laragon's nginx serve api.kiyu.test
```

`.env` essentials:

- `APP_URL=http://api.kiyu.test`
- `DB_CONNECTION=mysql`, `DB_DATABASE=kiyu`
- `CORS_ALLOWED_ORIGINS=http://kiyu.test,http://app.kiyu.test`
- `CORS_ALLOWED_ORIGIN_PATTERNS=#^http://[a-z0-9-]+[.]kiyu[.]test$#`

Payment (`MIDTRANS_*`) and WhatsApp (`WHATSAPP_*`) credentials live only in
`.env`; they are never exposed through the platform settings UI.

## Frontend (`C:\laragon\www\app-kiyu`)

```bash
npm install
npm run dev        # dotenvx run -- next dev  (port 3000, proxied by nginx)
npm run test       # vitest
npm run typecheck  # tsc --noEmit
npm run build
```

`.env.local` essentials: `AUTH_SECRET` (signs the session cookie),
`API_BASE_URL=http://api.kiyu.test/api`, `ROOT_DOMAIN=kiyu.test` (production
`kiyu.id`), `NEXT_PUBLIC_API_BASE_URL`, Reverb `NEXT_PUBLIC_REVERB_*`.

## Hosts

| URL | Application |
|---|---|
| `http://kiyu.test` | Marketing |
| `http://app.kiyu.test` | Platform Portal (platform_admin, tenant_admin) |
| `http://{tenant}.kiyu.test` | Tenant Workspace (facility_admin, operator, staff, client) |
| `http://api.kiyu.test` | Laravel API |

Reserved labels (`kiyu`, `app`, `api`, `www`, `admin`, `support`, `docs`,
`status`, `static`, `assets`) are never tenant slugs. An unknown slug is a real
404; a suspended tenant is 403.

## Seed data

`php artisan db:seed` creates plans (`TRIAL`, `STANDARD`, `MULTI_FACILITY`) and a
demo tenant `kiyu-demo` with a kiosk token. Sign a new organization up at
`app.kiyu.test/signup` to exercise the self-service trial flow.

## Tests

```bash
php artisan test          # Pest feature/unit suite
```

Database backup before any destructive reset:

```bash
mysqldump -u root -h 127.0.0.1 --single-transaction --set-gtid-purged=OFF kiyu > storage/backups/kiyu-backup.sql
```
