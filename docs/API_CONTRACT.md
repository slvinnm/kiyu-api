# KIYU API Contract

Frontend integration reference for the KIYU backend. Every endpoint, its auth
context, request shape and response shape.

- **Base URL:** `http://api.kiyu.test/api` locally (`API_BASE_URL` already ends
  with `/api`). Production: the deployed API host + `/api`.
- **Content type:** JSON in and out. Send `Accept: application/json` and
  `Content-Type: application/json`.
- **Auth:** `Authorization: Bearer <sanctum-token>` on every protected call.
  Each token is scoped to exactly one application context (`ctx:*`); a token from
  another context is rejected.
- **Origin:** browser calls must send the frontend `Origin` matching the context.
  The context is resolved from `Origin`, not from the API host.
- **Facility:** there is **no facility header or middleware**. Pass `facility_id`
  in the query string (GET) or JSON body (mutations) when the account can reach
  more than one facility. If the account can reach exactly one active facility it
  is used automatically. Export/download endpoints may return a binary body.

---

## 1. Errors

All errors are JSON:

```json
{ "message": "Human readable", "error_type": "STABLE_CODE", "errors": { "field": ["..."] } }
```

`errors` is present only on `422` validation failures.

| HTTP | Meaning | `error_type` examples |
|---:|---|---|
| 401 | Missing/invalid token | — |
| 403 | Wrong context / role / tenant / facility / feature not owned | `ENTITLEMENT_REQUIRED`, `WRONG_TENANT_ORIGIN`, `FEATURE_NOT_AVAILABLE` |
| 404 | Not found, or wrong-context feature unreleased | `FEATURE_NOT_AVAILABLE` |
| 409 | Business conflict (capacity, invalid state) | `CAPACITY_EXCEEDED`, `INVALID_BILLING_STATE` |
| 422 | Validation | `INVALID_CREDENTIALS`, `ADDON_NOT_ELIGIBLE`, `facility_id` |
| 429 | Throttled / quota exhausted | `QUOTA_EXCEEDED` |

Feature denial is mapped centrally: unreleased → 404 `FEATURE_NOT_AVAILABLE`,
not entitled / dependency missing → 403 `ENTITLEMENT_REQUIRED`, capacity → 409
`CAPACITY_EXCEEDED`, quota → 429 `QUOTA_EXCEEDED`.

---

## 2. Application contexts

The API is one central host. Each route belongs to exactly one frontend context,
mounted under its own prefix. The browser `Origin` must match the context.

| Context | Prefix | Origin | Allowed role(s) | Token ability |
|---|---|---|---|---|
| Public | `/api/public` | `kiyu.test` | none | — |
| Customer | `/api/customer` | `{tenant}.kiyu.test` | `customer` | `ctx:customer` |
| App | `/api/app` | `app.kiyu.test` | `tenant_admin` | `ctx:app` |
| Tenant | `/api/tenant` | `{tenant}.kiyu.test` | `facility_admin`, `operator`, `staff` | `ctx:tenant` |
| Portal | `/api/portal` | `portal.kiyu.test` | `platform_admin` (`tenant_id = NULL`) | `ctx:portal` |
| Webhooks | `/api/webhooks` | — | provider signature | — |

`Origin` is a context selector, never authentication: role, tenant membership,
facility access and policy checks always run in addition.

**User payload** (`data.user`) returned by `me`/`login`:

```json
{
  "id": 1, "name": "…", "email": "…", "role": "tenant_admin", "tenant_id": 5,
  "tenant": { "id": 5, "name": "…", "slug": "…", "status": "ACTIVE", "timezone": "Asia/Jakarta" },
  "facilities": [{ "id": 9, "name": "…", "code": "MAIN" }],
  "facility_ids": [9], "station_id": null, "profile": null
}
```

---

## 3. Authentication

Login is per context and returns a context-scoped token plus the user payload.
`POST` bodies are JSON. All login failures return `422 INVALID_CREDENTIALS`
(unknown email, wrong password, inactive tenant, wrong role alike).

### App (`tenant_admin`) — origin `app.kiyu.test`

| Method | Path | Body | Notes |
|---|---|---|---|
| POST | `/api/app/auth/signup` | `{organization_name, slug?, timezone?, plan_code?, owner_name, owner_email, owner_password}` | SaaS signup; creates tenant + owner + first facility + TRIALING subscription. Throttled 3/60. |
| POST | `/api/app/auth/login` | `{email, password}` | Returns `{ token, context:"app", user }`. |
| GET | `/api/app/auth/me` | — | Current user. |
| POST | `/api/app/auth/logout` | — | Revokes the current token. |

### Tenant (`facility_admin|operator|staff`) — origin `{tenant}.kiyu.test`

| Method | Path | Body | Notes |
|---|---|---|---|
| POST | `/api/tenant/auth/login` | `{email, password}` | Account tenant must match the origin; else `403 WRONG_TENANT_ORIGIN`. |
| POST | `/api/tenant/auth/invitations/accept` | `{token, name, password, password_confirmation}` | Guest; single-use hashed invite token. |
| GET | `/api/tenant/auth/me` | — | Current user. |
| POST | `/api/tenant/auth/logout` | — | Revoke token. |

### Portal (`platform_admin`) — origin `portal.kiyu.test`

| Method | Path | Body | Notes |
|---|---|---|---|
| POST | `/api/portal/auth/login` | `{email, password}` | Platform accounts only, `tenant_id = NULL`. No signup. |
| GET | `/api/portal/auth/me` | — | Current user. |
| POST | `/api/portal/auth/logout` | — | Revoke token. |

### Customer (`customer`) — origin `{tenant}.kiyu.test`

| Method | Path | Body | Notes |
|---|---|---|---|
| POST | `/api/customer/auth/register` | `{name, email, password, password_confirmation}` | Only if tenant allows public registration. Creates a `customer` account. |
| POST | `/api/customer/auth/login` | `{email, password}` | Account tenant must match the origin. |
| GET | `/api/customer/auth/me` | — | Current user. |
| POST | `/api/customer/auth/logout` | — | Revoke token. |

---

## 4. Public (no auth) — origin `kiyu.test`

| Method | Path | Notes |
|---|---|---|
| GET | `/api/public/tenants/{slug}` | `200` active tenant public fields; `403` unavailable; `404` unknown. Never exposes owner/private data. |

---

## 5. Customer self-service — auth `ctx:customer`, role `customer`, tenant origin

Own data only; the customer id comes from the authenticated account.

| Method | Path | Notes |
|---|---|---|
| GET | `/api/customer/profile` | Own `customers` record. |
| POST | `/api/customer/profile` | Create own record `{name, phone?, email?, address?}`. |
| PUT | `/api/customer/profile` | Update own record. |
| GET | `/api/customer/queue-sessions` | Own visits (paginated). |
| GET | `/api/customer/queue-sessions/{id}` | Own visit detail. |
| POST | `/api/customer/bookings` | Book an online visit `{department_id, idempotency_key?}`. Facility derived from the department. |

---

## 6. App — auth `ctx:app`, role `tenant_admin`

The tenant is always the authenticated account's tenant. Facility-scoped
sections take `facility_id` (query for GET, body for writes) or use the single
active facility.

### Dashboard & organization

| Method | Path | Notes |
|---|---|---|
| GET | `/api/app/dashboard` | Org overview `?facility_id=&date=`. Counts, channels, subscription, usage. |
| GET | `/api/app/facilities` | List facilities. |
| POST | `/api/app/facilities` | Create `{name, code, address?, phone?, timezone?, is_active?}` (capacity-gated). |
| GET | `/api/app/facilities/{id}` | Facility with departments + stations. |
| PUT | `/api/app/facilities/{id}` | Update (same fields, all optional). |

### Users, assignments, invitations

| Method | Path | Notes |
|---|---|---|
| GET | `/api/app/users` | List `?search&sort&direction&page&per_page&role&facility_id&station_id&department_id`. |
| POST | `/api/app/users` | Create `{name, email, password, role, station_id?, department_id?, facility_ids?[]}`. |
| GET | `/api/app/users/{id}` | Detail. |
| PUT | `/api/app/users/{id}` | Update (same fields, all optional). |
| DELETE | `/api/app/users/{id}` | Delete (not self, not last admin). |
| GET | `/api/app/users/{userId}/facilities` | Assignments. |
| POST | `/api/app/users/{userId}/facilities` | Assign `{facility_id}`. |
| DELETE | `/api/app/users/{userId}/facilities/{facilityId}` | Unassign. |
| GET | `/api/app/invitations` | List. |
| POST | `/api/app/invitations` | Create `{email, role, facility_ids?[]}`; returns one-time `token`. |
| DELETE | `/api/app/invitations/{id}` | Revoke. |

### Facility configuration (pass `facility_id`)

| Method | Path | Notes |
|---|---|---|
| GET | `/api/app/departments` | `?facility_id&search&sort&page&per_page&…` |
| GET | `/api/app/departments/options` | Lightweight choices. |
| POST | `/api/app/departments` | `{facility_id, code, name, is_active?, display_in_kiosk?}` |
| GET | `/api/app/departments/{id}` | Detail with stations + workflows. |
| PUT | `/api/app/departments/{id}` | Update. |
| GET | `/api/app/departments/{id}/stations` | Stations of department. |
| GET | `/api/app/departments/{id}/workflows` | Workflows of department. |
| GET | `/api/app/stations` | `?facility_id&department_id&type&…` |
| GET | `/api/app/stations/options` | Choices `?facility_id&department_id&type`. |
| POST | `/api/app/stations` | `{facility_id, department_id, name, code, type, queue_prefix?, is_active?}` |
| GET | `/api/app/stations/{id}` | Detail. |
| PUT | `/api/app/stations/{id}` | Update. |
| GET | `/api/app/stations/{id}/queue` | Live ticket queue snapshot. |
| GET | `/api/app/workflows` | `?facility_id&department_id&…` |
| GET | `/api/app/workflows/statistics` | `?facility_id`. |
| POST | `/api/app/workflows` | `{facility_id, department_id, name, is_active?}` |
| GET | `/api/app/workflows/{id}` | Detail with steps. |
| POST | `/api/app/workflows/{id}/steps` | `{facility_id, station_id, name, sequence, is_active?, is_optional?, can_skip?}` |
| PUT | `/api/app/workflow-steps/{id}` | Update step (`facility_id`). |
| DELETE | `/api/app/workflow-steps/{id}` | Delete step (`facility_id` query). |

### Monitoring (read-only, org-wide)

| Method | Path | Notes |
|---|---|---|
| GET | `/api/app/queue/monitor` | `?facility_id&station_id&department_id`. |
| GET | `/api/app/queue-sessions` | `?facility_id&department_id&status&date&…` |
| GET | `/api/app/queue-sessions/statistics` | `?facility_id&date`. |
| GET | `/api/app/queue-sessions/{id}` | Detail. |
| POST | `/api/app/queue-sessions/{id}/cancel` | `{reason?}`. |
| GET | `/api/app/customers` | `?search&phone&…` |
| GET | `/api/app/customers/statistics` | Totals. |
| GET | `/api/app/customers/{id}` | Detail. |
| GET | `/api/app/customers/{id}/queue-sessions` | Visits. |
| GET | `/api/app/audit-logs` | `?search&sort&…` |

### Reports (each gated by its own entitlement)

All accept `?facility_id&from&to`. Each route requires its `report_*`
entitlement (see §12) and the feature to be released.

| Method | Path | Entitlement | Response `data` |
|---|---|---|---|
| GET | `/api/app/reports/summary` | `report_queue_overview` | alias of `queue-overview` |
| GET | `/api/app/reports/queue-overview` | `report_queue_overview` | `{period, queue_sessions, queue}` |
| GET | `/api/app/reports/daily-queue` | `report_daily_queue` | `{period, days:[{date,total,completed}]}` |
| GET | `/api/app/reports/visit-details` | `report_visit_details` | `{period, visits:[…]}` (max 500) |
| GET | `/api/app/reports/department-performance` | `report_department_performance` | `{period, departments:[{department_id,department,total,completed,completion_rate}]}` |
| GET | `/api/app/reports/waiting-time` | `report_waiting_time` | `{period, average_wait_minutes, served_tickets, never_called_tickets}` |
| GET | `/api/app/reports/service-duration` | `report_service_duration` | `{period, average_minutes, measured_tickets}` |
| GET | `/api/app/reports/queue-trends` | `report_queue_trends` | `{period, days:[{date,tickets}]}` |
| GET | `/api/app/reports/peak-hours` | `report_peak_hours` | `{period, hours:[{hour,tickets}]}` (24 entries) |
| GET | `/api/app/reports/cancellation-no-show` | `report_cancellation_no_show` | `{period, cancelled, skipped, no_show, completed, denominator, cancellation_rate}` |
| GET | `/api/app/reports/staff-performance` | `report_staff_performance` | `{period, staff:[{user_id,user,completed}]}` |
| GET | `/api/app/reports/facility-comparison` | `report_facility_comparison` | `{period, facilities:[{facility_id,facility,total,completed,average_wait_minutes}]}` |
| GET | `/api/app/reports/queue-sessions/export` | `report_queue_overview` + `report_export` | CSV stream |
| POST | `/api/app/reports/queue-sessions/export-file` | `report_queue_overview` + `report_export` | `{format:"xlsx"|"pdf"}` → `{path,format,download_url,expires_in_days}` |
| GET | `/api/app/reports/download` | `report_queue_overview` + `report_export` | `?path=` binary download |

### Billing (tenant self-service)

| Method | Path | Notes |
|---|---|---|
| GET | `/api/app/billing/subscription` | `{subscription, is_effective, entitlements}`. |
| POST | `/api/app/billing/subscription` | Switch plan `{plan_code, billing_interval:"MONTHLY"|"YEARLY", currency?}` → `PENDING_PAYMENT` checkout. Trial is signup-only; `trial_days` is ignored. |
| POST | `/api/app/billing/subscription/cancel` | Cancel at period end. |
| GET | `/api/app/billing/entitlements` | Per-feature objects (see §12). |
| GET | `/api/app/billing/plans` | Catalogue `{plans:[…], current_plan_id, subscription_status}`. |
| POST | `/api/app/billing/pay` | Start a charge → `{transaction_id, redirect_url, token}`. |
| GET | `/api/app/billing/payments` | Paginated payment history. |
| GET | `/api/app/billing/payments/{id}` | Payment + line items. |
| GET | `/api/app/billing/addons` | Add-ons the plan may buy. |
| POST | `/api/app/billing/addons` | Buy `{addon_id, quantity?}` → pending purchase. |
| POST | `/api/app/billing/addons/{id}/cancel` | Cancel at period end. |

---

## 7. Tenant workspace — auth `ctx:tenant`, roles `facility_admin|operator|staff`, tenant origin

Roles noted per endpoint. Facility comes from `facility_id` or the single active
facility; a user only reaches assigned facilities.

### Guest, tenant-origin-bound (`tenant.origin`)

| Method | Path | Notes |
|---|---|---|
| GET | `/api/tenant/public/queue` | Signage read model (device/public). |
| GET | `/api/tenant/public/departments/active` | Kiosk department list. |
| GET | `/api/tenant/public/tracking/{token}` | Visit tracking by token. |
| POST | `/api/tenant/devices/kiosk/pair` | `{pairing_code}` → device credential (shown once). |
| POST | `/api/tenant/devices/display/pair` | `{pairing_code}`. |
| POST | `/api/tenant/intake/kiosk` | **Device required** (`X-Kiosk-Token`). `{department_id, idempotency_key?}`. |
| GET | `/api/tenant/intake/online-lookup/{identifier}` | **Device required.** Booking preview. |
| POST | `/api/tenant/intake/online-checkin` | **Device required.** `{booking_identifier}`. |

Device endpoints send `X-Kiosk-Token` (kiosk) or `X-Display-Token` (display); the
device pins tenant + facility. No user token.

### Workspace (authenticated)

| Method | Path | Roles | Notes |
|---|---|---|---|
| GET/POST | `/api/tenant/auth/me`, `/logout` | all | Session. |
| POST | `/api/tenant/intake/manual` | facility_admin, operator | `{facility_id, department_id, priority?, idempotency_key?}` |
| POST | `/api/tenant/intake/online` | facility_admin, operator | `{facility_id, customer_id, department_id, priority?, idempotency_key?}` |
| GET | `/api/tenant/queue-acquisitions` | facility_admin, operator | Paginated. |
| GET | `/api/tenant/queue-acquisitions/{id}` | same | Detail. |
| POST | `/api/tenant/queue-acquisitions/{id}/cancel` | same | |
| GET | `/api/tenant/reception/queue` | facility_admin, operator | `?facility_id&department_id`. |
| POST | `/api/tenant/reception/call-next` | same | `{facility_id, department_id, station_id?}` |
| POST | `/api/tenant/reception/complete-registration` | same | `{facility_id, acquisition_id, customer_id}` |
| POST | `/api/tenant/reception/{ticketId}/recall\|skip\|no-show` | same | |
| POST | `/api/tenant/queue/call-next` | facility_admin, operator, staff | `{facility_id, station_id}` |
| GET | `/api/tenant/tickets` | all | `?facility_id&…&search&sort` |
| GET | `/api/tenant/tickets/statistics` | all | |
| GET | `/api/tenant/tickets/{id}` | all | |
| GET | `/api/tenant/tickets/{id}/events` | all | Ticket event log. |
| POST | `/api/tenant/tickets/{id}/start\|hold\|resume\|recall\|complete\|skip\|no-show\|cancel` | all | |
| POST | `/api/tenant/tickets/{id}/transfer` | **staff** | `{target_station_id, reason?}` |
| GET | `/api/tenant/service-transfers` | facility_admin, staff | |
| GET | `/api/tenant/service-transfers/{id}` | same | |
| POST | `/api/tenant/service-transfers` | **staff** | |
| GET | `/api/tenant/queue-sessions` | all | `?facility_id&…` |
| GET | `/api/tenant/queue-sessions/statistics` | all | |
| GET | `/api/tenant/queue-sessions/{id}` | all | |
| GET | `/api/tenant/queue-sessions/{id}/acquisition` | all | |
| PATCH | `/api/tenant/queue-sessions/{id}/priority` | all | `{priority:1|2|3}` |
| POST | `/api/tenant/queue-sessions/{id}/cancel` | all | `{reason?}` |
| GET/POST/PUT | `/api/tenant/customers…` | all | List `/{id}`/`/{id}/queue-sessions`, create, update. |
| GET | `/api/tenant/departments`,`/options`,`/{id}`,`/{id}/stations`,`/{id}/workflows` | all | `?facility_id`. |
| POST/PUT | `/api/tenant/departments…` | **facility_admin** | |
| GET | `/api/tenant/queue/monitor` | all | `?facility_id&station_id&department_id`. |
| GET | `/api/tenant/stations` (`/options`,`/{id}`,`/{id}/queue`) | all | |
| POST/PUT | `/api/tenant/stations…` | **facility_admin** | |
| GET | `/api/tenant/workflows…` | all | |
| POST/PUT/DELETE | `/api/tenant/workflows…`,`/workflow-steps/{id}` | **facility_admin** | |
| GET/POST/DELETE | `/api/tenant/kiosk-devices…`,`/{id}/pairing-code` | **facility_admin** | `?facility_id`; capacity-gated. |
| GET/POST/DELETE | `/api/tenant/display-devices…`,`/{id}/pairing-code` | **facility_admin** | |
| POST | `/api/tenant/whatsapp/messages` | facility_admin, operator | `feature:whatsapp_notifications` |

### Reports (same per-report contract as §6, under `/api/tenant/reports/*`)

Every report path in §6 is mirrored: `/api/tenant/reports/{queue-overview,
daily-queue, visit-details, department-performance, waiting-time,
service-duration, queue-trends, peak-hours, cancellation-no-show,
staff-performance, facility-comparison, summary}`, plus exports and download.
Roles: `facility_admin` (and `operator` for read reports); export/download
require `report_export`.

---

## 8. Portal — auth `ctx:portal`, role `platform_admin`, `tenant_id = NULL`

Platform administration only; no tenant impersonation.

| Method | Path | Notes |
|---|---|---|
| GET | `/api/portal/dashboard` | Platform metrics. |
| GET | `/api/portal/tenants` | List `?search&status&…`. |
| POST | `/api/portal/tenants` | Provision a tenant. |
| GET | `/api/portal/tenants/{id}` | Detail (owner, facilities, subscription). |
| POST | `/api/portal/tenants/{id}/suspend` | `{reason}`. |
| POST | `/api/portal/tenants/{id}/reactivate` | |
| GET | `/api/portal/subscriptions`, `/{id}` | Read-only. |
| GET | `/api/portal/payments`, `/{id}`, `/payments-webhooks` | Monitoring. |
| GET/POST | `/api/portal/plans` | Catalogue. |
| GET/PUT | `/api/portal/plans/{id}` | Detail/update. |
| POST | `/api/portal/plans/{id}/prices` | `{billing_interval, amount, currency}` — new version, old deactivated. |
| PUT | `/api/portal/plans/{id}/entitlements` | `{entitlements:[{feature_code, limit?}]}` |
| GET/POST | `/api/portal/addons` | Add-on catalogue. |
| GET/PUT | `/api/portal/addons/{id}` | Detail/update. |
| POST | `/api/portal/addons/{id}/prices` | New price version. |
| PUT | `/api/portal/addons/{id}/entitlements` | `{entitlements:[{feature_code, limit?}]}` |
| PUT | `/api/portal/addons/{id}/eligibilities` | `{plan_ids:[…]}` |
| GET | `/api/portal/reports/revenue`, `/growth`, `/subscriptions` | Platform reports. |
| GET | `/api/portal/features` | Entire feature registry + release state. |
| PATCH | `/api/portal/features/{code}/release` | `{is_feature_released, release_note?}` — audited. |
| GET | `/api/portal/audit-logs` | Platform audit. |
| GET/PUT | `/api/portal/settings` | Registration policy, reserved subdomains. |

---

## 9. Webhooks (no user auth)

| Method | Path | Notes |
|---|---|---|
| POST | `/api/webhooks/payments/{provider}` | Provider signature-verified, idempotent. Never call from a browser. |

---

## 10. Conventions

- **Pagination:** list endpoints accept `search`, `sort`, `direction`, `page`,
  `per_page` (clamped 1..100). Response: `{data:[…], current_page, last_page,
  per_page, total, …}`. Unknown sort columns are ignored.
- **Dates:** report `from`/`to` are tenant-local calendar dates (`YYYY-MM-DD`),
  converted to the tenant timezone server-side.
- **Ids:** path ids are integers (`whereNumber`); a wrong-context or wrong-tenant
  id returns `404`.

---

## 11. Feature codes

Reports (boolean, each gate one endpoint): `report_queue_overview`,
`report_daily_queue`, `report_visit_details`, `report_department_performance`,
`report_waiting_time`, `report_service_duration`, `report_queue_trends`,
`report_peak_hours`, `report_cancellation_no_show`, `report_staff_performance`,
`report_facility_comparison`, and `report_export`.

Core/notification: `queue_management`, `whatsapp_notifications`,
`whatsapp_messages_per_period` (quota).

Capacity (additive): `max_facilities`, `max_staff`, `max_kiosk_devices`,
`max_display_devices`.

Every feature also has a global release switch (`is_feature_released`). A
released=false feature is denied in every context even if the plan grants it.

---

## 12. Entitlement response shape

`GET /api/app/billing/entitlements` returns one object per feature, distinct
`released` vs `entitled` vs `available`:

```json
{
  "data": {
    "report_peak_hours": {
      "released": true, "available": true, "entitled": true,
      "sources": ["plan"], "type": "boolean", "reason_code": "ALLOWED"
    },
    "max_facilities": {
      "released": true, "available": true, "entitled": true,
      "sources": ["plan"], "type": "capacity",
      "limit": 3, "used": 1, "remaining": 2, "reason_code": "ALLOWED"
    }
  }
}
```

A feature is only `available` when released **and** entitled (and any dependency
present). `sources` is `plan`, `addon`, or both. Use this payload to drive menu
visibility and disable/hide features.