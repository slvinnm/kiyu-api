# KIYU SaaS Multi-Tenancy & Database Migration

## Source of Truth for the Implementation Agent

**Document status:** Approved product and architecture decisions; implementation specification  
**Prepared:** 22 November 2026  
**Scope:** Convert KIYU from a single-clinic application into a tenant-isolated clinic SaaS without destroying or misassigning existing data.

> This document defines the intended target architecture and migration invariants. It is not evidence that the migration has already been implemented. The agent must inspect the actual local checkout, current Git branch, uncommitted changes, database engine/version, and live schema before writing migrations. The repository snapshot above is a reference point, not a substitute for inspecting the working tree.

---

## 1. Mission

Transform KIYU into a multi-tenant SaaS product for clinics using one shared application and one shared relational database, with strict logical data isolation between clinic organizations.

The first SaaS release must let a clinic operate independently: create its organization, configure its facility, departments, stations and workflows, manage staff, register patients, and run the full patient-flow/queue process without manually editing the database.

KIYU is being positioned initially as a **patient-flow and queue-management platform**. Do not represent this migration as making KIYU a complete electronic medical record (RME) replacement.

### Non-goals for this migration

- Do not introduce a cross-clinic/global patient identity system.
- Do not introduce multi-organization user memberships.
- Do not implement full multi-branch user permissions in V1.
- Do not build a complete billing/payment gateway as a prerequisite for tenant isolation.
- Do not redesign the existing queue/workflow domain unless a change is required for tenant integrity.
- Do not rewrite the Next.js frontend as part of a backend database migration unless the current frontend contract makes a corresponding update necessary.

---

## 2. Approved product decisions — do not reinterpret

These decisions are the source of truth for the implementation.

| Concern | Approved decision |
|---|---|
| Target market | Clinics |
| Tenant definition | One clinic organization is one `tenant` |
| User membership | One user belongs to exactly one tenant. No `tenant_memberships` pivot in V1. |
| Patient data | Isolated per tenant. A clinic owns its own patient records. |
| Patient sharing | No patient record is shared across tenants. No global patient matching or master patient index. |
| Facility / branch | A `tenant` owns one or more `facilities`. V1 onboarding creates exactly one facility, but the schema must not prohibit additional facilities later. |
| Future facility access | Multi-branch user access is deferred. When required, add an explicit tenant-scoped facility-access pivot/assignment (for example, `facility_user`); do not add multi-tenant user memberships. |
| Department ownership | A department belongs to a facility. |
| Visit ownership | A visit belongs to one tenant and one facility. |
| Patient ownership | A patient belongs to a tenant, not to a facility, so the patient can be seen at another facility belonging to the same tenant in a future multi-branch release. |
| User department/station assignment | Keep the current `department_id` and `station_id` assignments for V1. They must resolve to the user's tenant. |
| Patient login | A patient account is tenant-scoped like any other tenant user. There is no shared login identity spanning clinics. |
| Public tenant resolution | Public requests must resolve a tenant from a trusted tenant locator (for example, a tenant slug/domain); kiosk operations must resolve it from a registered device credential. Never trust a request-body `tenant_id`. |
| Platform administration | Platform-level accounts, if introduced, must be explicitly separated from tenant users and must not gain access merely because `tenant_id` is null. |

### Consequences that are intentional

1. A patient may have a separate patient record at each clinic. This is deliberate data isolation, not a duplication bug.
2. The existing globally unique `users.email` remains global in V1. Since one user can belong to only one tenant and login currently uses email alone, the same email cannot be registered as a second user account in another tenant. Do not quietly change the login model to tenant-scoped email uniqueness.
3. Patients are shared across facilities **inside their own tenant**, not across tenants.
4. A tenant can eventually add branches without moving patient ownership from one facility to another.
5. A referral may target another facility belonging to the same tenant, but a cross-tenant referral is not allowed by this design.

---

## 3. Repository baseline confirmed during preparation

The following facts were checked against `main` at commit `7ba2e5bb66ef536db68ffddc13116302676be60d`. Recheck them against the actual working tree before coding.

### 3.1 Existing tables and relevant schema

| Table | Confirmed current structure / behavior | Required SaaS change |
|---|---|---|
| `users` | Global unique `email`; `role`; nullable `department_id`; nullable `station_id`. `User` has one optional `Patient`. | Add `tenant_id` and `account_type`; preserve `email` global uniqueness and current tenant roles. Validate department/station assignments against the same tenant. |
| `patients` | Nullable `user_id`; globally unique `mrn`; nullable globally unique `nik`; patient demographic/contact fields. `Patient` belongs to `User` and has many visits. | Add mandatory `tenant_id`; change MRN/NIK uniqueness to tenant scope. Ensure linked user belongs to the same tenant. |
| `departments` | Globally unique `code`; `name`; `is_active`; `display_in_kiosk`. | Add mandatory `facility_id`; make department code unique within a facility. Preserve `is_active` and `display_in_kiosk`. |
| `stations` | Belongs to department; globally unique `code`; type, queue prefix and active flag. | Keep facility ownership derivable through department. Change station-code uniqueness to be scoped to its owning department, after verifying no external consumer relies on global code uniqueness. |
| `workflows` | Belongs to department. | Facility is derived through the department. Validate department and station relationships remain within the correct facility. |
| `workflow_steps` | Belongs to workflow and station; unique `(workflow_id, sequence)`; optional/repeatable/skip/condition fields. | Keep existing workflow-step uniqueness. Validate the workflow and its step station resolve to the same tenant/facility. Do not remove current workflow semantics. |
| `visits` | Nullable `patient_id`; `department_id`; `workflow_id`; globally unique `visit_number`; globally unique `visit_token`; status/channel/timestamps and online booking key. | Add mandatory `tenant_id` and `facility_id`; scope visit-number uniqueness to facility; keep `visit_token` globally unique. |
| `visit_counters` | Globally unique `counter_date`; `last_sequence`. | Add mandatory `facility_id`; unique `(facility_id, counter_date)`. |
| `queue_counters` | Unique `(station_id, counter_date)`. | Keep this structure. A station resolves to its facility through its department. Validate station ownership before incrementing. |
| `queue_acquisitions` | Belongs to visit and department; contains idempotency key, channel and status. | Keep tenant/facility derivable from visit and department. Every acquisition must reference a visit and department within the same tenant/facility; scope idempotency lookups to the current tenant. |
| `queue_tickets` | Belongs to visit, workflow step and station; may reference a previous ticket and transfer station. | Keep tenant/facility derivable from visit. Validate the visit, workflow step and station all resolve to the same tenant/facility. Respect transfer behavior. |
| `queue_events` | Belongs to queue ticket; has a string `user_id`, event type/status and JSON payload. | Keep tenant/facility derivable through the ticket and visit. Validate any actor user belongs to that tenant. |
| `referrals` | Links source visit, target department and optional target visit. | Ensure source visit, target department and target visit all belong to the same tenant. Target facility may differ within that tenant. |
| `audit_logs` | `user_id`, `entity_type`, `entity_id` are stored as strings; metadata JSON. | Add nullable `tenant_id` for tenant-scoped auditing and platform/system events. Backfill legacy records only when ownership is known. |

### 3.2 Important existing model behavior to preserve

- `Visit` already has both `queueAcquisition(): HasOne` (latest acquisition) and `queueAcquisitions(): HasMany` (all acquisitions). Preserve both unless a separate domain review proves a change is needed.
- `Visit.visit_token` already exists in the create-visits migration and is globally unique. Do not create a second token column, remove it, or replace it with a sequential ID.
- `User` has `patient(): HasOne`; `Patient` has `user(): BelongsTo`. Preserve this optional relationship and ensure both records share the same tenant.
- `Department` already has `display_in_kiosk`. Do not regress the kiosk visibility behavior while adding tenant scoping.
- `QueueTicket` broadcasts queue updates after commit. Tenant isolation must also be applied to broadcast channels and payloads, not only HTTP queries.
- `SequenceService::generateVisitNumber()` currently keys `VisitCounter` only by date. This must become facility-aware.
- `SequenceService::generateQueueNumber(Station $station)` currently keys by station and date. Keep the station/date counter key, but ensure the station is authorized for the current tenant/facility.
- `AuthController::register()` currently creates a user with role `patient` but does not itself create a `Patient` row. Do not assume patient-profile creation already happens there. Preserve or deliberately redesign this behavior with tests.

### 3.3 Existing public routes are a tenancy-critical gap

The current `routes/api.php` exposes these routes without `auth:sanctum`:

- `POST /api/intake/kiosk`
- `POST /api/intake/online`
- `GET /api/intake/online-lookup/{identifier}`
- `POST /api/intake/online-checkin`
- `GET /api/public/queue`
- `GET /api/departments/active`
- `GET /api/visits/{token}/track`
- `POST /api/auth/register`

These routes are currently not shown as tenant-resolved in the inspected route file. Adding tenant columns while leaving these routes global is **not** a completed multi-tenant implementation.

### 3.4 Existing implementation files that must be reviewed

At minimum, review and update the relevant paths in the current checkout:

- `app/Models/User.php`
- `app/Models/Patient.php`
- `app/Models/Department.php`
- `app/Models/Station.php`
- `app/Models/Workflow.php`
- `app/Models/WorkflowStep.php`
- `app/Models/Visit.php`
- `app/Models/VisitCounter.php`
- `app/Models/QueueCounter.php`
- `app/Models/QueueAcquisition.php`
- `app/Models/QueueTicket.php`
- `app/Models/QueueEvent.php`
- `app/Models/Referral.php`
- `app/Models/AuditLog.php`
- `app/Services/SequenceService.php`
- `app/Services/IntakeService.php`
- `app/Services/ReceptionService.php`
- `app/Services/ClinicalQueueService.php`
- `app/Services/WorkflowService.php`
- `app/Services/ReferralService.php`
- `app/Services/VisitService.php`
- `app/Services/AuditService.php`
- `app/Http/Controllers/Api/AuthController.php`
- `app/Http/Controllers/Api/IntakeController.php`
- `app/Http/Controllers/Api/DepartmentController.php`
- `app/Http/Controllers/Api/StationController.php`
- `app/Http/Controllers/Api/WorkflowController.php`
- `app/Http/Controllers/Api/UserController.php`
- `app/Http/Controllers/Api/PatientController.php`
- `app/Http/Controllers/Api/VisitController.php`
- `app/Http/Controllers/Api/QueueAcquisitionController.php`
- `app/Http/Controllers/Api/ReceptionController.php`
- `app/Http/Controllers/Api/ClinicalQueueController.php`
- `app/Http/Controllers/Api/TicketController.php`
- `app/Http/Controllers/Api/ReferralController.php`
- `app/Http/Controllers/Api/PublicQueueController.php`
- `app/Http/Controllers/Api/AuditLogController.php`
- `routes/api.php`, middleware registration, broadcast channel definitions and `QueueBroadcast` implementation
- factories, seeders, policies, request validators, feature tests and any scheduled jobs

Do not assume this list is exhaustive. Search the entire repository for direct model queries, raw SQL, `DB::table()`, route model binding, events, listeners, jobs, cache keys, broadcast channels and public API routes.

---

## 4. Target data model

### 4.1 `tenants` — clinic organization

Create a `tenants` table with at least:

| Column | Requirement |
|---|---|
| `id` | Primary key |
| `name` | Required clinic/organization name |
| `slug` | Required, globally unique, stable public identifier |
| `status` | Required; use a controlled set such as `ACTIVE`, `SUSPENDED`, `PENDING`, `CANCELLED` |
| `billing_email` | Nullable |
| `contact_name` | Nullable |
| `contact_email` | Nullable |
| `contact_phone` | Nullable |
| `timezone` | Required; default may be `Asia/Jakarta`, but allow per-tenant configuration |
| timestamps | Required |

Do not use the tenant's display name as its unique key. The slug must be stable and unique. A tenant status must be checked before allowing authenticated operational access.

Do not physically cascade-delete a tenant and all its clinic data. Prefer suspension/archive semantics. Destructive tenant deletion requires a separate retention/export/approval policy.

### 4.2 `facilities` — physical clinic location

Create a `facilities` table with at least:

| Column | Requirement |
|---|---|
| `id` | Primary key |
| `tenant_id` | Required FK to `tenants` |
| `name` | Required |
| `code` | Required; unique within tenant |
| `address` | Nullable |
| `phone` | Nullable |
| `timezone` | Nullable; otherwise inherit tenant timezone |
| `is_active` | Required boolean, default true |
| timestamps | Required |

Add unique `(tenant_id, code)` and an index on `tenant_id`.

**Do not add a unique constraint on `facilities.tenant_id`.** V1 onboarding creates one facility, but the database must permit multiple facilities per tenant later.

A facility is not a tenant. Do not name this table `tenants` or attach all patient records to a facility.

### 4.3 `users`

Add:

- `tenant_id` FK, nullable only for explicitly designated platform accounts.
- `account_type`, required, with controlled values `TENANT` and `PLATFORM`.

Backfill every existing user as `account_type = TENANT` and assign the legacy tenant. Existing `role` values stay as they are, including `patient`; do not replace or rename the current role system as part of this migration.

Rules:

- `account_type = TENANT` requires a non-null `tenant_id`.
- `account_type = PLATFORM` requires a null `tenant_id`.
- A platform account must not be treated as a clinic admin merely because its `tenant_id` is null.
- Existing clinic staff/admin users and patient users are all `TENANT` users.
- No platform account needs to be created during the legacy backfill.
- Tenant operational endpoints must reject platform accounts unless a deliberate platform-specific guard and permission path is implemented.
- `department_id` and `station_id`, when present, must belong to the same tenant as the user. If both are set, validate their relationship according to current assignment rules.
- Never accept `tenant_id` or `account_type` from normal user create/update request payloads. Derive them from the authenticated tenant context and controlled provisioning flows.
- Keep `users.email` globally unique in V1.

If the selected database supports CHECK constraints, the agent may enforce the `account_type`/`tenant_id` pairing in the database after verifying the exact engine/version. Application validation remains mandatory. Do not add an unverified database-specific constraint.

### 4.4 `patients`

Add mandatory `tenant_id` FK.

Change uniqueness from global to tenant-scoped:

- unique `(tenant_id, mrn)`
- unique `(tenant_id, nik)`; nullable NIK values must remain allowed

Preserve current `user_id`, demographics, contact fields and optional profile relationship.

Rules:

- The patient belongs to one tenant, not one facility.
- A linked `user_id`, when present, must point to a `TENANT` user in the same tenant.
- Patient lookup, search, update, delete, statistics and visit history must always be tenant-scoped.
- NIK is sensitive personal data. Never expose it through public queue, kiosk preview, visit tracking or ordinary staff payloads unless an authorized workflow specifically requires it.
- Do not introduce cross-tenant deduplication, matching or a global patient table.

### 4.5 `departments`

Add mandatory `facility_id` FK.

Change `code` uniqueness to unique `(facility_id, code)`.

Tenant is derived from `department.facility.tenant_id`; do not add a duplicate `tenant_id` to departments unless a measured query or integrity requirement justifies that denormalization.

Preserve `is_active` and `display_in_kiosk`. Tenant scoping must not cause inactive or kiosk-hidden departments to reappear in public department options.

### 4.6 `stations`

Keep `department_id`; facility and tenant are derived through department.

Change the current global station-code unique index to unique `(department_id, code)`. This permits different clinics/departments to use ordinary local codes such as `REG` or `DR` while preventing duplicate codes inside one department.

Before changing the index, search the codebase and frontend contract for consumers that assume a station code is globally unique. Update those consumers to use an ID or a department-scoped code. Do not leave a globally unique station-code index in place if it prevents two tenants from configuring the same normal station codes.

### 4.7 `workflows` and `workflow_steps`

No direct `tenant_id` or `facility_id` is required initially; ownership is derived through `workflow.department.facility.tenant`.

Preserve unique `(workflow_id, sequence)` and all existing workflow-step attributes.

Enforce that a workflow, its department and every referenced step station belong to the same tenant/facility. Whether a step station must belong to the exact same department or merely the same facility must be checked against current production data and workflow behavior before enforcing that narrower rule.

### 4.8 `visits`

Add mandatory:

- `tenant_id` FK to `tenants`
- `facility_id` FK to `facilities`

Replace global `visit_number` uniqueness with unique `(facility_id, visit_number)`.

Keep `visit_token` globally unique. This token is used as an opaque visit-tracking credential and must remain unique across all tenants/facilities.

Rules:

- `visit.tenant_id` must equal `visit.facility.tenant_id`.
- `visit.department_id` must belong to `visit.facility_id`.
- `visit.workflow_id` must belong to the visit's department under current workflow ownership rules.
- If `patient_id` is non-null, the patient must belong to `visit.tenant_id`.
- An anonymous/walk-in visit with null `patient_id` remains valid.
- A referral-generated visit uses the tenant of the source visit and the facility of the target department.
- Do not expose or return `visit_token`, `online_active_key`, patient NIK or internal clinical notes through public API responses. The tracking route may accept the token as a bearer credential but must return only the existing approved patient-safe tracking information.

### 4.9 `visit_counters` and `queue_counters`

Add `facility_id` to `visit_counters` and replace unique `counter_date` with unique `(facility_id, counter_date)`.

Change `SequenceService::generateVisitNumber()` to require or resolve a verified `Facility` and lock/increment the counter by both `facility_id` and `counter_date`. Update all callers, including intake and referral creation.

Retain the current visit-number format unless product requirements require a visible facility prefix. The uniqueness scope is the facility, so the same formatted number may exist at another facility. Any UI or support workflow that identifies a visit across facilities must use tenant/facility context or the opaque `visit_token`, not assume `visit_number` is globally unique.

Keep `queue_counters` unique `(station_id, counter_date)`. Before incrementing, ensure the station belongs to the current facility/tenant through its department.

### 4.10 Child tables: derive ownership, do not duplicate blindly

Do not automatically add `tenant_id` to every table. The initial design derives ownership through the parent relationships:

- `queue_acquisitions` → visit and department
- `queue_tickets` → visit; station and workflow step
- `queue_events` → queue ticket → visit
- `referrals` → source visit, target department and optional target visit
- `workflow_steps` → workflow and station
- `stations` → department
- `workflows` → department
- `queue_counters` → station → department

For every create/read/update/delete operation, validate the full relationship chain. A valid foreign key to an ID is not enough: the referenced row must be in the same tenant/facility context.

`queue_acquisitions.idempotency_key` currently has a normal index, not a unique constraint. Keep its behavior unless separately redesigned, but change every idempotency lookup to include the current tenant/facility through the related visit. The same idempotency key used by two different tenants must never return or conflict with the other tenant's acquisition.

### 4.11 `referrals`

Referrals are tenant-local. Enforce:

- source visit belongs to tenant T;
- target department belongs to tenant T;
- target visit, when present, belongs to tenant T;
- target visit belongs to the target department;
- target visit's patient, when present, is the same tenant-owned patient as the source visit where the current referral flow expects continuity.

The target department may belong to another facility within tenant T. Do not require source and target facility to be identical. Cross-tenant referral support is a separate, explicit product feature and is out of scope.

### 4.12 `audit_logs`

Add nullable `tenant_id` FK and index it.

- Tenant-originated operations must write the current tenant ID.
- Platform/system operations may have null tenant ID.
- Existing audit rows may be backfilled to the legacy tenant only after confirming that the legacy database represented one tenant. If ownership is ambiguous, do not guess; report the rows and leave them unresolved for an explicit mapping decision.
- Preserve the existing string `user_id`, `entity_type` and `entity_id` unless a separate audit-schema change is approved.
- Avoid storing unnecessary NIK, credentials, visit tokens, raw patient records or sensitive clinical details in audit metadata.

### 4.13 `kiosk_devices` — required for trusted kiosk operations

Create a table with at least:

| Column | Requirement |
|---|---|
| `id` | Primary key |
| `tenant_id` | Required FK |
| `facility_id` | Required FK; must belong to the tenant |
| `name` | Required device label |
| `credential_hash` | Required, unique hash of a high-entropy random device token |
| `is_active` | Required boolean |
| `last_seen_at` | Nullable timestamp |
| `expires_at` | Nullable timestamp, if credential expiry is supported |
| timestamps | Required |

Show the raw device credential only once when provisioning. Store only a secure hash. Support revocation/rotation. A kiosk request must resolve its tenant and facility from the authenticated device credential, not from a client-supplied tenant/facility ID.

---

## 5. Billing model — separate from operational tenant migration

Billing must not be mixed into patient-flow services. It is a separate SaaS domain. Create these tables in a later phase or in the same release only if the core tenant foundation is already stable and tested.

### `plans`

- `id`
- globally unique `code`
- `name`
- nullable `description`
- `is_active`
- timestamps

### `plan_prices`

- `id`
- `plan_id`
- `billing_interval` (for example `MONTHLY` or `YEARLY`)
- `amount` as integer minor units / smallest currency unit; never floating point
- `currency` as ISO-style three-letter code
- optional `provider` / provider price reference
- `is_active`
- timestamps

Use a uniqueness rule that matches actual price dimensions, at minimum plan + interval + currency (and provider if multiple providers/prices are supported). Do not overwrite old prices when a plan price changes; create a new price row to preserve historical subscription/payment meaning.

### `plan_entitlements`

- `id`
- `plan_id`
- `feature_code`
- JSON `value`
- timestamps
- unique `(plan_id, feature_code)`

Entitlements should describe capabilities/limits. Avoid scattering plan-name checks throughout controllers.

### `subscriptions`

- `id`
- `tenant_id`
- `plan_id`
- nullable `plan_price_id`
- `status`
- `billing_interval`
- nullable `provider`
- nullable provider customer/subscription identifiers
- nullable `current_period_start`, `current_period_end`, `trial_ends_at`, `cancelled_at`
- `cancel_at_period_end` boolean
- timestamps

Preserve subscription history. Enforce at most one effective active/trial subscription per tenant through a transactional service that locks the tenant row. Do not assume a partial unique index is portable.

### `payments`

- `id`
- `subscription_id`
- `provider`
- nullable provider payment/transaction identifier
- integer `amount`
- `currency`
- `status`
- nullable `paid_at`
- nullable JSON metadata containing only necessary billing data
- timestamps

Add a unique provider/reference constraint when a provider reference exists. Do not store payment secrets or unrelated patient/clinical information.

### `billing_webhook_events`

- `id`
- `provider`
- `event_id`
- `event_type`
- JSON payload (store only what is required and apply retention controls)
- processing status, received/processed timestamps, nullable error detail
- unique `(provider, event_id)` for idempotent processing

Webhook handlers must verify provider signatures before processing, be idempotent and never trust the payload as proof of payment without provider verification.

### Billing rollout rule

Do not begin with a payment gateway. First make tenant onboarding and the clinic's complete operational workflow function correctly. A trial or manually activated subscription is sufficient for an initial pilot. Payment integration follows after product and tenant isolation are proven.

---

## 6. Tenant resolution and security rules

### 6.1 Authenticated requests

For a normal authenticated tenant user:

1. Authenticate using the existing authentication mechanism.
2. Load the user's `tenant_id` from the database.
3. Reject the request if the account is not a tenant account, has no tenant, or the tenant is suspended/inactive.
4. Establish a request-scoped `TenantContext` containing the resolved tenant.
5. Resolve the facility from a validated facility locator or the tenant's single active facility in V1.
6. Scope every tenant-owned query and every referenced foreign key to this context.

Never use `tenant_id` from request JSON, query strings, ordinary headers, route parameters or frontend state as authorization. For authenticated requests, the authenticated user record is authoritative. A public tenant slug can identify a public tenant context but cannot grant membership or staff access.

### 6.2 Tenant context lifecycle

Introduce a dedicated `TenantContext` (or equivalent) rather than passing arbitrary tenant IDs through unrelated service methods.

- Bind it with a request/job-safe scoped lifecycle, not a process-wide singleton that can leak between requests.
- Clear/reset it at the end of the request lifecycle.
- Queue jobs must carry the required tenant/facility identifiers and explicitly rehydrate context when they run.
- Long-lived workers (including Octane/Reverb/queue workers if used) must not retain the previous request's tenant context.
- Services may accept an explicit `Facility`/tenant object when that makes ownership clearer; never accept an unverified numeric ID.

A global Eloquent scope may be added as defense in depth, but it is **not** the security boundary. Raw queries, `withoutGlobalScopes()`, relationship mistakes, jobs, broadcasts and public endpoints can bypass global scopes. Explicit tenant checks and authorization are mandatory.

### 6.3 Public route resolution

Choose and document one canonical public tenant locator after inspecting the actual Next.js frontend and deployment/domain setup (for example, tenant subdomain/custom domain or a tenant slug in the public URL). The slug is a public identifier, not an authorization credential.

Use these rules:

| Public route / capability | Required resolution |
|---|---|
| Kiosk intake | Registered kiosk device credential → tenant + facility |
| Public queue display | Tenant locator + facility; return only public queue data |
| Active kiosk departments | Tenant locator/device facility; preserve `is_active` and `display_in_kiosk` filters |
| Online booking | Tenant locator; resolve the patient inside that tenant; resolve department/workflow inside the selected facility |
| Online booking lookup/check-in | Tenant/facility context or matching device context; lookup identifiers only within that scope |
| Patient registration | Tenant locator; create a `TENANT` user assigned to that tenant; never create an unscoped public patient user |
| Visit tracking | Existing high-entropy global `visit_token` as a bearer credential; return only that visit's approved patient-safe tracking data |

Do not leave a global kiosk intake route that accepts any department ID and creates visits without a trusted facility context.

### 6.4 Foreign-key validation and IDOR prevention

A request containing `patient_id`, `department_id`, `station_id`, `workflow_id`, `visit_id`, `ticket_id`, `acquisition_id` or `referral_id` must resolve the row inside the current tenant/facility context.

Do not rely on validators such as `exists:departments,id` alone. Use a tenant-constrained query/validation rule, then re-check the relationship in the domain service before mutation.

For example, a valid department ID from another tenant must be rejected even though that department exists in the database.

### 6.5 Tenant and facility status

- Suspended tenants must not access tenant operational endpoints.
- Inactive facilities must not accept new intake or queue work.
- Existing historical visits remain readable to authorized tenant staff subject to retention policy.
- Do not delete records simply because a tenant/facility is suspended.

### 6.6 Reverb/broadcast isolation

Inspect the actual Reverb channel definitions and `QueueBroadcast` implementation.

- Public queue channels may expose only the minimal public queue projection and must be bound to the correct tenant/facility/department.
- Private staff channels must authorize the authenticated user against the tenant and facility of the channel resource.
- A guessed numeric department/station ID must not authorize subscription.
- Never broadcast patient NIK, phone, email, clinical notes, internal payloads or other tenant data to public queue channels.
- Include tenant/facility in cache keys, event routing keys and any shared in-memory identifiers where collisions or leakage are possible.

### 6.7 Other isolation surfaces

Tenant isolation must cover all of the following, not just database controllers:

- API reads and writes
- statistics, exports, reports and search
- validation rules and route model binding
- scheduled commands and background jobs
- Reverb channels/events
- cache keys and locks
- file/object storage paths and download authorization
- rate limits and abuse controls
- audit logs
- notification recipients and email content
- logs/error reporting where tenant data is attached

---

## 7. Migration strategy — safe, staged and data-preserving

The implementation agent must not jump directly into editing migrations. Follow these phases in order.

### Phase 0 — preflight and baseline

1. Inspect `git status`, current branch, `HEAD`, staged/unstaged changes and untracked files. Do not overwrite the user's work.
2. Confirm whether local `main` is behind/ahead of the inspected commit. Inspect any newer local changes, particularly `visit_token` and other current work.
3. Inspect `composer.json`, `.env.example`, `config/database.php`, migration status, database engine/version and actual production database version. Do not assume MySQL capabilities without checking.
4. Run the existing test suite before changes and record failures. Do not silently attribute pre-existing failures to the migration.
5. Inspect all migration files, model relations, factories, seeders, indexes, foreign keys, route definitions, services and controllers. Check actual index names using the database metadata (for MySQL, `SHOW INDEX` / `information_schema.statistics`). Never guess an index name from Laravel's naming convention.
6. Take and verify a restorable database backup before applying any production schema/data migration.
7. Run read-only data profiling: table counts; distinct department/facility candidates; users, patients, departments, visits, queue counters, acquisitions, tickets, referrals and audit-log relationships; duplicate candidate scoped keys; invalid existing cross-relationships.
8. Confirm whether the existing database represents exactly one clinic organization. If more than one clinic's data is already mixed in the same database and there is no reliable mapping, **STOP** and request an explicit mapping. Do not assign all rows to one tenant just to make the migration pass.

Deliver a concise preflight report before destructive/contracting changes. It must include current DB engine/version, migration status, table counts, existing global unique indexes, relationship inconsistencies and whether one-tenant backfill is safe.

### Phase 1 — expand schema (backward-compatible)

Create new tables and add nullable columns without dropping existing indexes:

1. Create `tenants`.
2. Create `facilities` with `tenant_id` FK.
3. Add nullable `users.tenant_id` and nullable `users.account_type` initially if needed for existing rows; add indexes/FKs.
4. Add nullable `patients.tenant_id`.
5. Add nullable `departments.facility_id`.
6. Add nullable `visits.tenant_id` and `visits.facility_id`.
7. Add nullable `visit_counters.facility_id`.
8. Add nullable `audit_logs.tenant_id`.
9. Create `kiosk_devices`.
10. Create billing tables only if the implementation branch explicitly includes the separate billing phase.

Do not drop/replace old global unique indexes in this phase. Do not make new ownership columns non-null before backfill and verification.

### Phase 2 — explicit legacy tenant/facility bootstrap and backfill

Do not guess the actual clinic name, slug, address or facility code. Obtain them from the owner/deployment configuration.

Implement a dedicated idempotent Artisan command (or an equally auditable one-time operation) to provision the legacy tenant/facility and backfill existing rows. Recommended interface:

```bash
php artisan tenancy:backfill-legacy \
  --tenant-name="<confirmed clinic organization name>" \
  --tenant-slug="<confirmed-unique-slug>" \
  --facility-name="<confirmed primary facility name>" \
  --facility-code="<confirmed facility code>" \
  --dry-run
```

Dry-run must be the default. Require an explicit `--apply` flag for writes. The exact CLI interface may differ, but the safety properties below are mandatory.

Backfill behavior when one legacy clinic is confirmed:

1. Create or resolve exactly one legacy tenant using the explicitly supplied stable slug.
2. Create or resolve one primary facility under that tenant using the supplied facility code.
3. Set all existing tenant user accounts to `account_type = TENANT` and the legacy `tenant_id`.
4. Set every existing patient's `tenant_id` to the legacy tenant.
5. Set every existing department's `facility_id` to the primary facility.
6. Set each existing visit's `tenant_id` to the legacy tenant and its `facility_id` from the visit's department/facility relationship. Do not blindly copy a constant if the relationship says otherwise.
7. Set existing visit counters to the legacy facility. Preserve `last_sequence`; do not reset sequence counters.
8. Assign audit logs to the legacy tenant only if the one-tenant history has been confirmed. Leave ambiguous/platform/system logs null and report them.
9. Verify user → department/station assignments resolve to the same tenant.
10. Verify patient → linked user tenant consistency.
11. Verify visit → patient, department, workflow consistency.
12. Verify acquisition → visit/department consistency.
13. Verify ticket → visit/workflow-step/station consistency, including transfers.
14. Verify referral source, target department and target visit belong to the same tenant; target facility may differ within that tenant.
15. Verify queue counters resolve to stations and facilities.

Safety requirements for the backfill operation:

- Idempotent: rerunning it must not create duplicate tenants/facilities or overwrite a conflicting assignment.
- If a row already has a different tenant/facility, stop and report; never silently reassign it.
- If more than one plausible tenant is found, stop and require a mapping file/explicit resolution.
- Do not truncate, recreate, delete or regenerate patients, visits, tickets, event history, counters or tokens.
- Preserve all existing IDs and timestamps unless there is a documented reason to alter them.
- Use transactions and chunking appropriate to the actual dataset size; do not load all patient/visit rows into memory.
- Produce a summary of rows updated, already correct, unresolved and rejected.
- Include automated tests for dry-run, apply, rerun/idempotency, conflict detection and relationship validation.

### Phase 3 — verify and contract constraints

After backfill, run explicit validation queries/tests and ensure no unowned tenant data remains except intentionally nullable platform/system records.

Then, in a separate migration/release step:

1. Make `users.tenant_id` non-null for `account_type = TENANT`. It may remain nullable only for `account_type = PLATFORM`.
2. Make `users.account_type` non-null and backfill existing records as `TENANT`.
3. Make `patients.tenant_id` non-null.
4. Make `departments.facility_id` non-null.
5. Make `visits.tenant_id` and `visits.facility_id` non-null.
6. Make `visit_counters.facility_id` non-null.
7. Keep `audit_logs.tenant_id` nullable for legitimate system/platform entries.
8. Replace global unique indexes with scoped indexes using exact names verified from the actual database:
   - `facilities`: unique `(tenant_id, code)`
   - `departments`: unique `(facility_id, code)`
   - `stations`: unique `(department_id, code)`
   - `patients`: unique `(tenant_id, mrn)` and `(tenant_id, nik)`
   - `visits`: unique `(facility_id, visit_number)`
   - `visit_counters`: unique `(facility_id, counter_date)`
   - Keep `users.email` globally unique.
   - Keep `visits.visit_token` globally unique.
   - Keep `queue_counters(station_id, counter_date)` unique.
   - Keep `workflow_steps(workflow_id, sequence)` unique.
9. Add/adjust supporting indexes for tenant/facility filtered lists and joins based on the actual query plan and workload.
10. Recheck all foreign keys and delete semantics.

Do not remove a legacy unique index until the new scoped index can be created and existing data has passed duplicate checks. Do not assume default Laravel index names. Query actual index metadata and use exact names.

**Important FK caveat:** Composite foreign keys can strengthen tenant/facility consistency, but they require matching unique parent keys and careful handling of nullable columns and `ON DELETE` behavior. In particular, a composite FK with `ON DELETE SET NULL` can attempt to null every child column in the FK, including a non-null `tenant_id`/`facility_id`. Do not add composite FKs by guess. Use service-layer consistency checks as mandatory protection; add composite constraints only where they are compatible with the current delete semantics and verified database engine/version.

### Phase 4 — implement tenant-aware application behavior

Only enable normal traffic after the application enforces the tenant rules in Section 6.

Implement:

- tenant/facility models and relationships;
- `TenantContext` and tenant/facility resolution middleware;
- tenant status checks;
- tenant-scoped query helpers/policies/validators;
- tenant-safe public route resolution;
- device credential authentication for kiosk operations;
- tenant-scoped services and idempotency checks;
- facility-aware visit numbering;
- tenant-safe user/patient management;
- tenant-safe referrals;
- audit logging with tenant ID;
- Reverb channel authorization and public payload filtering;
- tenant-aware jobs, cache keys, exports and storage paths.

Do not claim multi-tenancy is complete merely because models have a `tenant()` relationship or a global scope.

### Phase 5 — onboarding and operational readiness

A tenant onboarding flow must provision, in a single safe transaction or recoverable workflow:

1. Tenant record.
2. Initial facility.
3. Initial tenant admin user.
4. Initial subscription/trial state if applicable.
5. A configurable department/station/workflow setup path (default templates may be seeded, but they must be owned by that tenant/facility).

The tenant admin must be able to invite/create staff, configure departments/stations/workflows and operate the clinic without direct SQL or manual DB edits.

Production must not get a hardcoded global "default clinic" tenant from `DatabaseSeeder`. A development/demo seeder may create a clearly marked demo tenant/facility. The legacy backfill command is separate from normal new-tenant onboarding.

---

## 8. Application behavior that must change

### 8.1 `SequenceService`

Change visit-number generation to be facility-aware. The counter lock key must include `facility_id` and `counter_date`. Update every caller:

- `IntakeService` (online and physical/kiosk/manual intake)
- `ReferralService` (target visit creation)
- any other direct call found by repository-wide search

Queue-number generation stays station/date scoped but must validate the station's owning tenant/facility.

### 8.2 `IntakeService`

- Resolve tenant/facility from the authenticated tenant context or trusted kiosk device context.
- Confirm the selected department belongs to that facility.
- For online booking, confirm the patient belongs to the same tenant.
- Write `tenant_id` and `facility_id` to the new visit.
- Generate visit number using the facility counter.
- Scope all idempotency-key lookups to the current tenant via the acquisition's visit.
- Scope online booking lookup/check-in by the resolved tenant/facility; do not search the entire database by visit number or `online_active_key`.
- Keep walk-in visits with `patient_id = null` valid.

### 8.3 `ReferralService`

- Load source visit inside current tenant context.
- Load target department inside the same tenant; it may be in another facility owned by that tenant.
- Resolve target workflow/station within the target facility.
- Create target visit with source tenant, target facility and target department.
- Preserve same-patient continuity where current referral behavior requires it.
- Generate target visit number from the target facility counter.
- Reject cross-tenant referral IDs even if all referenced IDs exist.

### 8.4 `WorkflowService` and queue services

- Workflow and step creation/update must validate department, workflow and station tenant/facility coherence.
- Queue operations must load ticket/visit/station through tenant-scoped queries.
- A ticket transfer must only target an authorized station within the allowed tenant/facility rules.
- `ReceptionService`, `ClinicalQueueService`, `QueueAcquisition` operations and ticket event creation must not accept a foreign tenant's resource ID.

### 8.5 `UserController` and `AuthController`

- Tenant user creation always derives `tenant_id` from the authenticated admin context.
- Requests cannot set their own `tenant_id` or `account_type`.
- Validate the selected department/station belongs to the same tenant.
- Preserve existing role validation and role semantics.
- Public registration must resolve a tenant through the canonical public tenant locator and create a tenant-scoped patient user.
- Login must reject inactive/suspended tenant accounts. A platform account must use a separately authorized platform flow, not clinic endpoints.
- Keep the global email uniqueness/login behavior in V1.

### 8.6 Controllers and read queries

Every controller must filter list, show, statistics, export, options and relationship queries by the authenticated tenant/facility. This includes:

- departments, stations, workflows and workflow steps;
- patients and patient visits;
- visits and acquisitions;
- queue tickets, events, reception and clinical queue operations;
- referrals;
- users;
- audit logs;
- public queue and kiosk department lookup.

A request for another tenant's numeric ID must not disclose the record. Returning `404` rather than `403` for inaccessible records is preferred where it avoids confirming that another tenant's resource exists; keep the API's established error contract consistent.

### 8.7 `AuditService`

Tenant audit rows must include the current tenant ID. For jobs/commands without a request, tenant context must be passed explicitly or safely rehydrated. Do not infer tenant ownership from an arbitrary entity ID string.

### 8.8 Frontend integration

The backend repository does not establish the exact current Next.js tenant-routing design. Inspect the actual frontend repository and deployment config before deciding how the tenant locator is carried.

The frontend must not treat a tenant ID in local storage, query state or request JSON as authorization. It may carry a public slug/domain for public routing; the backend still derives authenticated access from the user record.

---

## 9. Required automated test matrix

Use at least two independent tenants in the feature-test suite, each with its own facility, admin, staff, patient, departments, stations, workflows, visits and queues. Use realistic overlapping business values (same MRN, NIK, department code, station code and visit number in different tenants where the scoped unique rules allow them).

### Database / migration tests

- Fresh database migrates successfully.
- Legacy one-tenant backfill assigns all expected rows to the tenant/facility.
- Backfill dry-run performs no writes.
- Re-running an applied backfill is idempotent.
- Conflicting preassigned tenant/facility data causes a safe failure.
- Multiple legacy clinic datasets cause the backfill to stop rather than merge them.
- Existing IDs, tokens, patient data, visits, queue tickets/events, referrals and counter sequences are preserved.
- Scoped unique rules allow equal business codes/MRN/NIK/visit numbers in separate scopes and reject duplicates within the same scope.
- `visit_token` remains globally unique.
- Existing global email uniqueness remains enforced.

### Auth and tenant boundary tests

- Tenant A user cannot read, update, delete or infer Tenant B users/patients/departments/visits/tickets/referrals/audit logs.
- Tenant A cannot create a visit using Tenant B's patient, department, workflow, station or facility IDs.
- Tenant A cannot use Tenant B's ticket/acquisition/referral ID for mutation.
- Request-body/query/header `tenant_id` cannot switch an authenticated user's tenant.
- Tenant suspension blocks operational access.
- Platform accounts cannot access tenant APIs without an explicit authorized path.
- A user cannot be assigned a department/station from another tenant.
- A patient user and patient profile cannot be linked across tenants.

### Clinic workflow tests

- Tenant A kiosk device can create intake only for its own facility.
- Tenant B cannot use Tenant A's device credential.
- Public department list is scoped to tenant/facility and respects `is_active` + `display_in_kiosk`.
- Public queue returns only the selected tenant/facility's public queue data.
- Online booking cannot use a patient ID belonging to another tenant.
- Online lookup/check-in cannot find or check in another tenant's booking.
- Manual intake, reception, call-next, start, hold, resume, complete, skip, no-show, cancel and transfer all preserve tenant/facility isolation.
- Transfers cannot target a station from another tenant/facility.
- Referrals are allowed to another facility in the same tenant when supported, and rejected across tenants.
- Visit tracking token returns only the visit represented by that token and no other tenant's data. The response must not expose the token, NIK, `online_active_key` or private clinical payloads.

### Counter and concurrency tests

- Visit sequence increments independently per facility/date.
- Two facilities may generate the same formatted visit number without collision.
- `visit_token` values remain globally unique.
- Queue sequence increments independently per station/date.
- Concurrent requests do not produce duplicate counter values.
- A station from another tenant cannot be used to increment a queue counter.

### Infrastructure leakage tests

- Reverb private channel authorization rejects cross-tenant subscriptions.
- Public queue broadcasts contain no patient/clinical PII and are routed to the correct tenant/facility.
- Cache entries/locks for one tenant cannot be read by another tenant.
- Jobs rehydrate the correct tenant context and do not reuse a previous request's tenant.
- Export/file download routes enforce tenant ownership.

### Regression suite

Run the full existing Pest suite and add/adjust factories/seeders so existing tests create valid tenant/facility ownership. Do not weaken or delete existing tests to make tenancy tests pass.

---

## 10. Production rollout and rollback discipline

### Preferred initial rollout

For the first real multi-tenant migration, a controlled maintenance window is safer than pretending the change is zero-downtime.

1. Verify and test a current backup restore.
2. Announce/enable maintenance or otherwise prevent writes during the ownership backfill.
3. Deploy/apply the expand migrations.
4. Run the explicit legacy tenant/facility backfill with confirmed values.
5. Run verification queries and the migration validation command.
6. Apply the contract/index migration only after validation passes.
7. Deploy the tenant-aware application code and clear/restart relevant workers/caches.
8. Smoke-test login, tenant admin, kiosk, patient intake, online booking, queues, tracking, referrals and public queue.
9. Re-enable traffic.
10. Monitor tenant-scoped errors, rejected foreign-key references, queue broadcasts and counter generation.

If zero-downtime is required, use a deliberate multi-release expand/dual-write/backfill/contract deployment. Do not run old application code that creates rows without tenant/facility IDs after the backfill has completed and before strict constraints are enabled.

### Rollback rules

- Do not use `php artisan migrate:fresh`, `db:wipe`, table drops or truncation against any database with real data.
- Do not assume `migrate:rollback` is a safe production rollback after tenants/data have been created.
- A schema rollback must not silently delete tenant, facility, patient, visit, queue or billing data.
- For a failed rollout, prefer restoring the verified backup or deploying a compatible forward fix under an explicit incident plan.
- `down()` methods for new schema migrations must be reviewed for data-loss behavior. Never write a `down()` that casually drops populated tenant tables in production.
- Do not remove or regenerate existing `visit_token` values, visit numbers, patient MRNs, NIK values or counter sequences.

---

## 11. Implementation order and deliverables

The implementation agent should complete the work in this order and report each deliverable.

### Deliverable A — preflight report

- Current branch, HEAD and working-tree state.
- DB engine/version and migration status.
- Existing table/index/FK summary.
- Existing data counts and duplicate checks.
- Confirmation whether a single-tenant legacy backfill is safe.
- List of any unresolved business/data mapping questions. Stop only for genuine blockers; do not invent clinic identity values.

### Deliverable B — schema and backfill

- Tenant/facility/kiosk schema migrations.
- New ownership columns and indexes.
- Safe backfill command with dry-run/apply behavior.
- Scoped uniqueness changes after verification.
- Migration tests and backfill tests.

### Deliverable C — domain and service tenancy

- Tenant/facility models and relationships.
- Request/job-safe `TenantContext`.
- Authenticated tenant middleware and public tenant/device resolution.
- Tenant-aware model/service/controller/validator logic.
- Facility-aware visit counters.
- Tenant-aware auditing and broadcast authorization.

### Deliverable D — application onboarding

- Tenant provisioning workflow.
- One initial facility per new tenant.
- Initial tenant admin.
- Tenant-owned department/station/workflow setup.
- Staff invite/create flow.
- Tenant-scoped patient registration and public intake.

### Deliverable E — tests and final audit

- Full automated test suite passes.
- Two-tenant isolation tests pass.
- Counter/concurrency tests pass.
- Public endpoint, kiosk credential, tracking and Reverb tests pass.
- Repository-wide search for unscoped queries is complete.
- Final migration/deployment notes and known limitations are documented.

---

## 12. Explicit prohibitions for the implementation agent

The agent must not:

1. Trust `tenant_id` supplied by a frontend, kiosk JSON body or arbitrary request header for authenticated access.
2. Assign every existing record to a single tenant without confirming the legacy database contains exactly one clinic organization.
3. Assume the clinic's official name, slug, facility name or facility code.
4. Make patients facility-owned; patient ownership is tenant-level.
5. Add `tenant_memberships` or allow users to belong to multiple tenants in V1.
6. Introduce a global/shared patient identity across tenants.
7. Remove the existing global `visit_token` uniqueness.
8. Keep globally unique `visit_number`, MRN, NIK or department/station codes where the approved scoped uniqueness rules require reuse across tenants/facilities/departments.
9. Forget to make visit numbering facility-aware or to scope idempotency lookups.
10. Consider Eloquent global scopes alone sufficient for security.
11. Leave anonymous kiosk, public queue, department lookup, online booking, online check-in or patient registration endpoints unscoped.
12. Expose patient NIK, private patient details, raw queue event payloads, visit tokens or online booking keys in public responses/broadcasts.
13. Create a platform admin with `tenant_id = null` and then grant it unrestricted access without a separate platform authorization path.
14. Add tenant IDs redundantly to every child table without a concrete query/integrity reason.
15. Add composite foreign keys without checking database compatibility, parent indexes, nullable columns and delete behavior.
16. Guess migration index names or rely on an assumed schema different from the actual database.
17. Use `migrate:fresh`, `db:wipe`, truncation or destructive seeding on a database with real data.
18. Start payment-gateway integration before tenant isolation and the full clinic workflow work correctly.
19. Mark the migration complete based only on successful `php artisan migrate`; the isolation tests and public-route checks are mandatory.

---

## 13. Definition of done

KIYU is ready to be called multi-tenant only when **all** of the following are true:

- Each clinic organization is represented by a tenant.
- Each tenant has at least one facility; V1 creates one, schema permits multiple.
- Users and patient records are tenant-owned and isolated.
- Departments and visits are facility-owned.
- Patient records can be used across facilities inside their own tenant, but never across tenants.
- Visit numbers and counters are facility-scoped; visit tracking tokens remain globally unique.
- All authenticated API queries/mutations validate tenant ownership.
- All anonymous/public endpoints resolve an explicit tenant/facility context or a trusted kiosk device context.
- Referrals cannot cross tenant boundaries.
- Reverb, jobs, caches, exports, files and audit logs preserve tenant isolation.
- Tenant onboarding can create a usable clinic without manual SQL.
- The legacy backfill is auditable, idempotent and data-preserving.
- The full existing test suite plus the new isolation/concurrency/security tests pass.
- The deployment procedure and backup/restore path have been tested.

A database with `tenant_id` columns but global public routes, unscoped controller queries or cross-tenant relationship acceptance is **not** multi-tenant. It is a data-leak risk.

---

## 14. Final instruction to the implementation agent

Treat this document as the approved target-state contract. Before writing code, inspect the latest local repository state and report any conflict between this document and the actual schema or business behavior. Resolve conflicts by preserving current behavior where this document does not explicitly change it, and by following the approved decisions above where the target state is explicit.

Implement the migration incrementally. Keep existing data and queue behavior intact. Prefer explicit, testable tenant/facility ownership checks over clever implicit behavior. Do not silently invent legacy clinic metadata or data mappings. Do not claim completion until the definition of done is satisfied.
