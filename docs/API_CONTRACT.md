# KIYU API Contract

Base: `http://api.kiyu.test/api` locally (the frontend `API_BASE_URL` includes
`/api`). JSON everywhere. Authenticated requests send
`Authorization: Bearer <sanctum-token>`. Facility-scoped routes accept
`X-Facility-Id` when the account can reach more than one facility.

Errors are JSON `{ "message": string, "error_type"?: string }` with a matching
HTTP status (`401` unauthenticated, `403` forbidden/wrong-context, `404` not
found, `409` business conflict, `422` validation, `429` throttled).

## Authentication — `/auth`

| Method | Path | Notes |
|---|---|---|
| POST | `/auth/login` | body `{email, password, context?}`; `context` ∈ `platform_portal`/`tenant_workspace`; wrong role for context → `403 WRONG_APPLICATION_CONTEXT` |
| POST | `/auth/signup` | Public SaaS signup: `{organization_name, slug?, timezone?, plan_code?, owner_name, owner_email, owner_password}`; creates tenant + owner + facility + TRIALING subscription. Throttled 3/60. |
| POST | `/auth/register` | Tenant-scoped client self-registration (`public-tenant`); only creates `client` |
| POST | `/auth/register/invitation` | Redeem an invitation token |
| GET | `/auth/me` | Current user (includes `tenant` block, `facility_ids`) |
| POST | `/auth/logout` | Revoke current token |

`data.user` shape: `{id, name, email, role, tenant_id, tenant:{id,name,slug,status,timezone}|null, facility_ids, station_id, profile}`.

## Public — no auth

| Method | Path | Notes |
|---|---|---|
| GET | `/public/tenant/{slug}` | 200 active (public fields only), 403 unavailable, 404 unknown |
| GET | `/public/queue` | Signage read model (`public-tenant` locator) |
| GET | `/departments/active` | Active departments for kiosk |
| GET | `/queue-sessions/{token}/track` | Public tracking by token |
| POST | `/kiosk/pair`, `/display/pair` | Device pairing (code → credential) |
| POST | `/webhooks/payments/{provider}` | Payment provider webhook (idempotent) |

## Tenant workspace — `auth:sanctum` + `tenant` + `context:tenant_workspace`

Roles shown per endpoint. All queries tenant-scoped.

- Intake: `POST /intake/manual` (tenant_admin/facility_admin/operator); `POST /intake/kiosk`, `/intake/online*` (device/public).
- Reception: `GET /reception/queue`, `POST /reception/call-next`, `/complete-registration`, `/{ticket}/recall|skip|no-show` (operator, facility_admin, tenant_admin).
- Queue processing: `POST /queue/call-next`; `POST /tickets/{id}/start|hold|resume|recall|complete|skip|no-show|cancel` (operator/staff/admin). `POST /tickets/{id}/transfer` → **staff only**.
- Service transfers: `GET /service-transfers`, `GET /service-transfers/{id}` (tenant_admin/facility_admin/staff); `POST /service-transfers` → **staff only**.
- Queue sessions: `GET /queue-sessions`, `/statistics`, `/{id}`, `PATCH /{id}/priority`, `POST /{id}/cancel`.
- Customers: `GET /customers`, `/statistics`, `/{id}`, `/{id}/queue-sessions`; clients see only their own.
- Config: `departments`, `stations`, `workflows`, `workflow-steps`, `users`, `users/{id}/facilities`, `invitations` (role-gated).
- Reports: `GET /reports/summary`; export endpoints (`feature:advanced_reports`).
- Devices: `kiosk-devices`, `display-devices` (tenant_admin/facility_admin; quota-gated).
- Billing: `GET/POST /billing/subscription`, `POST /billing/subscription/cancel`, `POST /billing/pay` (tenant_admin).

## TenantAdmin Portal — `auth:sanctum` + `tenant` + `context:platform_portal` + `role:tenant_admin`

Base `/portal`. Same controllers as the tenant API; the frontend calls tenant-
shaped paths and the API client prefixes `/portal`.

- `GET /portal/dashboard?facility_id=&date=` — org overview.
- `GET/POST/PUT /portal/facilities`; `users`, `users/{id}/facilities`, `invitations`.
- Facility-scoped config behind `X-Facility-Id`: `departments`, `stations`, `workflows`, `reports/*`.
- Org monitoring: `GET /portal/queue/monitor`, `/queue-sessions*`, `/customers*`, `/audit-logs`.
- Billing: `/portal/billing/*`.

## Platform administration — `auth:sanctum` + `platform` + `context:platform_portal`

Base `/platform`. `platform_admin` only.

| Area | Endpoints |
|---|---|
| Dashboard | `GET /platform/dashboard?from=&to=` (tenant/subscription counts, MRR, collection, failures, device usage) |
| Tenants | `GET /platform/tenants` (paginated/filter), `POST`, `GET /{id}`, `POST /{id}/suspend`, `POST /{id}/reactivate` |
| Subscriptions | `GET /platform/subscriptions`, `GET /{id}` (read-only; activation via webhook) |
| Payments | `GET /platform/payments`, `GET /{id}`, `GET /platform/payments-webhooks` |
| Plans | `GET/POST /platform/plans`, `GET/PUT /{id}`, `POST /{id}/prices`, `PUT /{id}/entitlements` |
| Reports | `GET /platform/reports/revenue`, `/growth`, `/subscriptions` |
| Support | `GET/POST /platform/support-access`, `POST /{id}/end` (reason + expiry required) |
| Audit | `GET /platform/audit-logs` |
| Settings | `GET/PUT /platform/settings` (registration policy + reserved subdomains) |

Dashboards and reports are computed from real rows. MRR is normalised recurring
revenue from ACTIVE subscriptions; it is not the same as cash collected.

## Pagination

List endpoints accept `search`, `sort`, `direction`, `page`, `per_page` and
return Laravel pagination: `{current_page, data, last_page, per_page, total, …}`.
`per_page` is clamped to 1..100. Unknown sort columns are ignored.
