# API Route Migration Manifest

The API was reorganised from one mixed `routes/api.php` into six context files.
This is a **hard cut**: old paths return 404, there are no compatibility aliases.

## Context prefixes added

| Prefix | Context |
|---|---|
| `/api/public/*` | Public marketing data |
| `/api/customer/*` | Customer self-service (`ctx:customer`) |
| `/api/app/*` | Customer SaaS / TenantAdmin (`ctx:app`) |
| `/api/tenant/*` | Operational workspace (`ctx:tenant`) |
| `/api/portal/*` | KIYU platform administration (`ctx:portal`) |
| `/api/webhooks/*` | Payment provider callbacks |

## Old → new

| Old path | New path |
|---|---|
| `POST /api/auth/login` | `POST /api/app/auth/login`, `/api/tenant/auth/login`, `/api/portal/auth/login`, `/api/customer/auth/login` |
| `POST /api/auth/signup` | `POST /api/app/auth/signup` |
| `POST /api/auth/register` | `POST /api/customer/auth/register` |
| `POST /api/auth/register/invitation` | `POST /api/tenant/auth/invitations/accept` |
| `GET /api/auth/me`, `POST /api/auth/logout` | per context: `/api/{app,tenant,portal,customer}/auth/{me,logout}` |
| `GET /api/public/tenant/{slug}` | `GET /api/public/tenants/{slug}` |
| `GET /api/public/queue` | `GET /api/tenant/public/queue` |
| `GET /api/departments/active` | `GET /api/tenant/public/departments/active` |
| `GET /api/queue-sessions/{token}/track` | `GET /api/tenant/public/tracking/{token}` |
| `POST /api/kiosk/pair`, `/api/display/pair` | `POST /api/tenant/devices/{kiosk,display}/pair` |
| `POST /api/intake/kiosk|online-lookup|online-checkin` | `POST /api/tenant/intake/...` |
| `POST /api/intake/manual` | `POST /api/tenant/intake/manual` |
| `POST /api/intake/online` | `POST /api/tenant/intake/online` (staff); `POST /api/customer/bookings` (customer) |
| `/api/queue-acquisitions`, `/reception`, `/tickets`, `/service-transfers`, `/queue-sessions`, `/customers`, `/departments`, `/stations`, `/workflows`, `/workflow-steps`, `/kiosk-devices`, `/display-devices`, `/whatsapp/messages`, `/reports/*` | `/api/tenant/...` (operational) |
| `/api/users`, `/facilities`, `/users/*/facilities`, `/invitations`, `/audit-logs`, `/billing/*`, `/queue/monitor`, `/queue-sessions*`, `/customers*`, `/reports/*` | `/api/app/...` (TenantAdmin) |
| `/api/portal/*` (old TenantAdmin portal) | `/api/app/*` |
| `/api/platform/*` | `/api/portal/*` |
| `POST /api/platform/tenants/{tenant}/manage/*` | **removed** (no impersonation) |
| `/api/platform/support-access*` | **removed** (records preserved) |
| `POST /api/webhooks/payments/{provider}` | unchanged |

## Role changes

- `tenant_admin` may no longer call any `/api/tenant/*` route; it manages its
  organization from `/api/app/*`.
- `client` is renamed `customer` and may no longer authenticate into App, Tenant
  staff workspace, or Portal; it uses `/api/customer/*`.
- Device runtime and pairing live under `/api/tenant/*` (facility_admin +
  device credential).

## Token changes

- Tokens are minted per context with exactly one `ctx:*` ability. Wildcard `*`
  and ability-less tokens are rejected on protected routes.
- **All pre-cutover personal access tokens stop working.** Deploy backend and
  frontend together and have users re-login once.

## Entitlement codes

Report gates move from the aggregate `basic_reports`/`advanced_reports` to
per-report codes (`report_queue_overview`, `report_daily_queue`, …). Exports
require the report code **and** `report_export`.