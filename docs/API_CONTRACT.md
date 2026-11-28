# KIYU API Contract

Base: `http://api.kiyu.test/api` locally (the frontend `API_BASE_URL` includes
`/api`). JSON everywhere. Authenticated requests send
`Authorization: Bearer <sanctum-token>`. Facility-scoped routes accept
`facility_id` (in the body or query) when the account can reach more than one facility. There is no facility header/middleware: the facility is taken from the request or resolved implicitly when the caller reaches exactly one active facility.

Errors are JSON `{ "message": string, "error_type"?: string }` with a matching
HTTP status (`401` unauthenticated, `403` forbidden/wrong-context, `404` not
found, `409` business conflict, `422` validation, `429` throttled).

## Application contexts

The API is served from one central host. Every route belongs to exactly one
frontend application context, mounted under its own prefix:

| Context | Prefix | Origin | Role(s) | Token ability |
|---|---|---|---|---|
| Public | `/api/public` | `kiyu.test` | none | — |
| Customer | `/api/customer` | `{tenant}.kiyu.test` | `customer` | `ctx:customer` |
| App | `/api/app` | `app.kiyu.test` | `tenant_admin` | `ctx:app` |
| Tenant | `/api/tenant` | `{tenant}.kiyu.test` | `facility_admin`, `operator`, `staff` | `ctx:tenant` |
| Portal | `/api/portal` | `portal.kiyu.test` | `platform_admin` (`tenant_id = NULL`) | `ctx:portal` |
| Webhooks | `/api/webhooks` | — | provider signature | — |

`Origin` is a context **selector**, not authentication. A protected request must
carry a `ctx:*` token for its route's context (a wildcard `*` or ability-less
token is rejected) **and** arrive from the matching browser `Origin`, **and**
pass the role, tenant-membership, facility and policy checks. For the Tenant and
Customer contexts the validated `Origin` slug must equal the authenticated
user's tenant slug (or the device's registered tenant).

`data.user` shape: `{id, name, email, role, tenant_id, tenant:{id,name,slug,status,timezone}|null, facility_ids, station_id, profile}`.

## Authentication

Login is per context; each returns a context-scoped token.

| Context | Endpoints |
|---|---|
| App | `POST /api/app/auth/signup` (SaaS signup), `POST /api/app/auth/login`, `GET /api/app/auth/me`, `POST /api/app/auth/logout` |
| Tenant | `POST /api/tenant/auth/login`, `POST /api/tenant/auth/invitations/accept` (guest, tenant-origin bound), `GET /api/tenant/auth/me`, `POST /api/tenant/auth/logout` |
| Portal | `POST /api/portal/auth/login`, `GET /api/portal/auth/me`, `POST /api/portal/auth/logout` (no signup) |
| Customer | `POST /api/customer/auth/register`, `POST /api/customer/auth/login`, `GET /api/customer/auth/me`, `POST /api/customer/auth/logout` |

Invalid credentials, wrong account status and wrong role/context all return
`422 INVALID_CREDENTIALS`. A valid account signing into the wrong tenant origin
returns `403 WRONG_TENANT_ORIGIN`.

## Public — no auth

| Method | Path | Notes |
|---|---|---|
| GET | `/api/public/tenants/{slug}` | 200 active (public fields only), 403 unavailable, 404 unknown |

## Customer — `ctx:customer`, `role:customer`, tenant-origin bound

Own data only (the `customers.id` is taken from the authenticated account).

| Method | Path | Notes |
|---|---|---|
| GET | `/api/customer/profile` | Own customer record |
| POST / PUT | `/api/customer/profile` | Create / update own record |
| GET | `/api/customer/queue-sessions` | Own visits |
| GET | `/api/customer/queue-sessions/{id}` | Own visit detail |
| POST | `/api/customer/bookings` | Book an online visit for self |

## App — `ctx:app`, `role:tenant_admin`

Organization-wide management. Tenant is the authenticated account's tenant.

- `GET /api/app/dashboard`; `facilities` (`GET/POST/GET{id}/PUT{id}`); `users` + `users/{id}/facilities` + `invitations`.
- Facility-scoped config: `departments`, `stations`, `workflows`, `workflow-steps` (pass `facility_id`).
- Monitoring: `GET /api/app/queue/monitor`, `/queue-sessions*`, `/customers*`, `/audit-logs`.
- Reports, one endpoint per entitlement under `/api/app/reports/*`: `queue-overview` (`report_queue_overview`), `daily-queue` (`report_daily_queue`), `visit-details` (`report_visit_details`), `department-performance` (`report_department_performance`), `waiting-time` (`report_waiting_time`), `service-duration` (`report_service_duration`), `queue-trends` (`report_queue_trends`), `peak-hours` (`report_peak_hours`), `cancellation-no-show` (`report_cancellation_no_show`), `staff-performance` (`report_staff_performance`), `facility-comparison` (`report_facility_comparison`). Exports + `download` also require `feature:report_export`.
- Billing extras: `GET /api/app/billing/payments`, `GET /api/app/billing/payments/{id}`; `GET/POST /api/app/billing/addons`, `POST /api/app/billing/addons/{id}/cancel`.
- Billing: `GET/POST /api/app/billing/subscription`, `POST /api/app/billing/subscription/cancel`, `GET /api/app/billing/entitlements`, `GET /api/app/billing/plans`, `POST /api/app/billing/pay`.

## Tenant — `ctx:tenant`, roles `facility_admin|operator|staff`, tenant-origin bound

Guest, tenant-origin-bound:

| Method | Path | Notes |
|---|---|---|
| GET | `/api/tenant/public/queue` | Signage read model |
| GET | `/api/tenant/public/departments/active` | Kiosk departments |
| GET | `/api/tenant/public/tracking/{token}` | Visit tracking by token |
| POST | `/api/tenant/devices/kiosk/pair`, `/api/tenant/devices/display/pair` | Device pairing |
| POST | `/api/tenant/intake/kiosk`, `/online-checkin`; `GET /intake/online-lookup/{id}` | Device intake (kiosk credential) |

Authenticated:

- Intake: `POST /api/tenant/intake/manual`, `POST /api/tenant/intake/online` (facility_admin/operator).
- Reception: `GET /reception/queue`, `POST /reception/call-next`, `/complete-registration`, `/{ticket}/recall|skip|no-show` (facility_admin/operator).
- Queue: `POST /queue/call-next`; `POST /tickets/{id}/start|hold|resume|recall|complete|skip|no-show|cancel`; `POST /tickets/{id}/transfer` (staff).
- Service transfers, queue sessions, customers, departments/stations/workflows, users/facility assignments/invitations: as before, roles enforced per route.
- Devices: `kiosk-devices`, `display-devices` (facility_admin; quota-gated).
- Reports: the same per-report endpoints under `/api/tenant/reports/*` (each gated by its own `report_*`); exports + `download` require `report_queue_overview` **and** `report_export`.
- WhatsApp: `POST /api/tenant/whatsapp/messages` (`feature:whatsapp_notifications`).

## Portal — `ctx:portal`, `role:platform_admin`, `tenant_id = NULL`

Base `/api/portal`. Platform administration only; no tenant impersonation.

| Area | Endpoints |
|---|---|
| Dashboard | `GET /portal/dashboard` |
| Tenants | `GET /portal/tenants`, `POST`, `GET /{id}`, `POST /{id}/suspend`, `POST /{id}/reactivate` (suspend requires `reason`) |
| Subscriptions | `GET /portal/subscriptions`, `GET /{id}` |
| Payments | `GET /portal/payments`, `GET /{id}`, `GET /portal/payments-webhooks` |
| Plans | `GET/POST /portal/plans`, `GET/PUT /{id}`, `POST /{id}/prices`, `PUT /{id}/entitlements` |
| Add-ons | `GET/POST /portal/addons`, `GET/PUT /{id}`, `POST /{id}/prices`, `PUT /{id}/entitlements`, `PUT /{id}/eligibilities` |
| Features | `GET /portal/features`, `PATCH /portal/features/{code}/release` |
| Reports | `GET /portal/reports/revenue`, `/growth`, `/subscriptions` |
| Audit / Settings | `GET /portal/audit-logs`; `GET/PUT /portal/settings` |

The old `/platform/tenants/{tenant}/manage/*` impersonation group and the
`/portal/support-access*` grant endpoints are removed; historical
`support_accesses` records and their audit trail are preserved.

## Webhooks — provider signature

| Method | Path | Notes |
|---|---|---|
| POST | `/api/webhooks/payments/{provider}` | Signature-verified, idempotent; not user-authenticated |

## Pagination

List endpoints accept `search`, `sort`, `direction`, `page`, `per_page` and
return Laravel pagination: `{current_page, data, last_page, per_page, total, …}`.
`per_page` is clamped to 1..100. Unknown sort columns are ignored.