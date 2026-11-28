# KIYU Backend API Refactoring PRD

**Document type:** Product Requirements Document / Engineering Specification  
**Project:** KIYU API (`slvinnm/kiyu-api`)  
**Scope:** Backend API only  
**Status:** Approved direction; implementation specification  
**Baseline repository:** `main` — `feacb817921c4ad8f2ce2b0129ad62573fe37212`  
**Baseline date:** 25 November 2026  
**Document date:** 28 November 2026  
**Primary audience:** AI coding agent, backend engineer, reviewer

---

## 0. Instructions to the implementation agent

This document is the implementation contract for the backend refactoring. Do not treat it as a suggestion to perform a mechanical folder move.

Before editing:

1. Re-read the current repository, especially `routes/api.php`, `bootstrap/app.php`, all route-referenced controllers, middleware, auth/token code, policies, services, tests, `config/cors.php`, `config/sanctum.php`, `.env.example`, and repository agent instructions (`AGENTS.md`, `CLAUDE.md`).
2. Verify the current branch and commit. The baseline above may have moved; implement against the current checkout, not against stale line numbers.
3. Build a complete route inventory and controller-to-route inventory. Do not infer that a controller is unused just because it is not in the examples below.
4. Record existing behavior and tests before changing it. Preserve business rules unless this PRD explicitly changes them.
5. Refactor in small, testable phases. Run the focused tests after each phase and the full test suite before completion.

Implementation constraints:

- Do not change the product's database/domain model merely to reorganize HTTP code.
- Do not duplicate business logic to separate controllers. Extract or reuse application services, actions, queries, policies, and resources.
- Do not use frontend route hiding, CORS, `Origin`, a tenant slug, or a request header as a substitute for authentication and authorization.
- Do not allow wildcard Sanctum tokens or context-free interactive tokens to bypass application-context checks.
- Do not retain the PlatformAdmin tenant-impersonation/manage route group.
- Do not delete old data, roles, tables, models, or audit history as a side effect of moving controllers. Deprecate/remove behavior separately and preserve records unless a migration is explicitly required.
- Do not implement frontend changes in this task. Publish the new API contract and migration map for the frontend team/agent.
- Do not silently keep ambiguous legacy routes. Any temporary compatibility route must be explicitly documented, feature-flagged, context-safe, tested, and scheduled for removal.

The final implementation report must include changed files, route map, controller mapping, middleware flow, compatibility notes, tests executed and results, and any remaining risks.

## 1. Executive summary

KIYU currently has multiple application contexts but the API route definitions and several controllers still cross those boundaries. A single `AuthController` handles different account types; TenantAdmin portal routes reuse controllers also registered in tenant operational routes; the PlatformAdmin routes contain a tenant support/impersonation path; and application context is represented by only two context values.

The target architecture separates the backend API into four explicit route contexts:

1. **Public** — public marketing/landing-page data for `kiyu.test`.
2. **App** — SaaS customer application for `app.kiyu.test`, used only by `tenant_admin` accounts.
3. **Tenant** — operational workspace for `{tenant-slug}.kiyu.test`, used by `facility_admin`, `operator`, and `staff` accounts, plus explicitly designed public/device endpoints.
4. **Portal** — KIYU platform administration for `portal.kiyu.test`, used only by `platform_admin` accounts.

Payment gateway webhooks are a separate public integration boundary. They do not use user authentication, but must perform provider signature validation, transaction validation, and idempotent processing.

The refactor must establish clear boundaries for routes, controllers, authentication, middleware, authorization, tenant resolution, device context, and tests. Shared business logic remains shared beneath the HTTP layer.

## 2. Baseline repository findings

The following findings were verified against the baseline commit listed above. The implementation agent must verify them again against the current checkout.

### 2.1 Route architecture

- API routes are currently concentrated in `routes/api.php` (approximately 33 KB at the baseline).
- The file contains auth/signup, public queue, kiosk/display pairing, tenant operations, `/portal` TenantAdmin routes, `/platform` PlatformAdmin routes, and payment webhook endpoints.
- There is no complete four-context route-file split today.
- `bootstrap/app.php` registers aliases for `role`, `tenant`, `facility`, `kiosk`, `public-tenant`, `platform`, `platform-tenant`, `feature`, and `context`.

### 2.2 Controller structure

Existing namespaces include root-level API controllers, `Api/Billing`, `Api/Platform`, `Api/Portal`, and `Api/PublicController`.

Notable cross-context reuse in `routes/api.php` includes `FacilityController`, `UserController`, `FacilityAssignmentController`, `InvitationController`, `DepartmentController`, `StationController`, `WorkflowController`, `ReportController`, `QueueSessionController`, `CustomerController`, and billing controllers.

The existing `Api/Platform` namespace contains PlatformAdmin controllers, while `Api/Portal/DashboardController` is currently a TenantAdmin organization dashboard. The new naming convention reverses/clarifies this: **App = tenant customer portal; Portal = platform administration**.

### 2.3 Authentication and context

- `Api/AuthController.php` handles login, tenant self-registration, me, and logout.
- It currently supports `platform_portal` and `tenant_workspace` contexts.
- `context` is nullable in login. When omitted, `issueToken()` creates a wildcard (`*`) token.
- `EnsureApplicationContext` lets wildcard and transient tokens pass without a context claim.
- Role-to-context mapping currently groups `platform_admin` and `tenant_admin` together under `platform_portal`.
- Existing public signup is implemented by `Api/PublicController/SignupController.php`, even though the new product rule places SaaS customer registration in the App context.

### 2.4 Tenant and platform support

- `ResolveTenant` resolves the tenant from the authenticated user's `tenant` relation, but does not compare it with the tenant slug of the tenant frontend origin.
- `ResolvePublicTenant` can resolve a tenant from `X-Tenant-Slug`, a route parameter, or the request host. When the API is hosted centrally, `Request::getHost()` is the API host, not the frontend tenant host.
- `ResolvePlatformTenant` binds a target tenant for `/platform/tenants/{tenant}/manage/*` and allows platform requests to reuse tenant controllers.
- The support/manage route group must be removed. PlatformAdmin must not act as a tenant user.

### 2.5 Existing test foundation

Relevant existing tests include:

- `tests/Feature/AuthApiTest.php`
- `tests/Feature/ApplicationContextTest.php`
- `tests/Feature/PlatformApiTest.php`
- `tests/Feature/PlatformTenantManagementTest.php`
- `tests/Feature/TenancyAndIdentityTest.php`
- `tests/Feature/TenantIsolationTest.php`
- `tests/Feature/FacilityContextTest.php`
- `tests/Feature/IntakeApiTest.php`
- `tests/Feature/DisplayDeviceTest.php`

Refactor and extend these tests; do not remove coverage just because routes move.

## 3. Product and architecture objectives

### 3.1 Objectives

- Establish four explicit API route contexts: Public, App, Tenant, Portal.
- Give each authenticated application context its own authentication controller and allowed-role policy.
- Ensure every route is protected by the correct context, role, tenant, facility, device, and feature checks.
- Ensure a controller is registered only in the context it owns.
- Keep shared domain logic in reusable services/actions/queries/policies rather than shared HTTP controllers.
- Move TenantAdmin customer-facing SaaS operations to App.
- Move PlatformAdmin operations to Portal.
- Restrict Tenant routes to FacilityAdmin, Operator, and Staff for authenticated workspace operations.
- Place kiosk and display runtime, pairing, and device operations under Tenant context.
- Place plan/add-on purchase and tenant billing in App; keep payment-provider callbacks in Webhooks.
- Remove PlatformAdmin tenant impersonation and `/tenants/{tenant}/manage` routes.
- Preserve tenant isolation, facility-level authorization, subscription/entitlement enforcement, audit logging, and current domain behavior.

### 3.2 Non-goals

- Rewriting the queue domain, database schema, subscription engine, entitlement catalogue, or billing provider integration unless required to preserve the new route boundaries.
- Frontend implementation or visual changes.
- Deploying four separate Laravel applications or four API servers.
- Removing the `client` role, customer records, or client-related historical data without a separately approved product decision.
- Creating a platform support impersonation system.
- Exposing tenant operational APIs to TenantAdmin from the Tenant host.

## 4. Canonical domains and API deployment model

### 4.1 Frontend domains

| Frontend | Domain | Purpose | Authenticated role |
|---|---|---|---|
| Public website | `kiyu.test` | Landing page, product information, pricing, public resources | None |
| Customer App | `app.kiyu.test` | SaaS customer portal, signup, organization, users, billing | `tenant_admin` only |
| Tenant Workspace | `{tenant-slug}.kiyu.test` | Queue operations and facility workflows | `facility_admin`, `operator`, `staff` |
| Platform Portal | `portal.kiyu.test` | KIYU platform administration | `platform_admin` only |

Production deployment uses the corresponding production domain. Domain suffixes and exact origins must be configuration-driven, not hard-coded throughout controllers or middleware.

### 4.2 Central API decision

For this backend refactor, retain one central API host (for example `api.kiyu.test` in local development and `api.kiyu.id` in production). The four frontend domains call that central API.

Because Laravel receives the API host in `Request::getHost()`, it must not infer the frontend application from `getHost()` when the API is central. The backend must identify browser application context from a validated frontend `Origin` and enforce the same context against the route group, role, and authenticated credential. Tenant slug resolution must use the validated tenant frontend origin (or a constrained explicit tenant locator for non-browser/device clients), not the API host's first label.

Do not use `Route::domain('app.kiyu.test')` as the primary context guard while all requests are sent to `api.kiyu.test`; that would validate the API request host rather than the frontend host.

### 4.3 Context evidence and security rules

The context resolver must support the actual authentication transports without weakening security:

**Browser SPA request**

- Validate the `Origin` against configured exact origins / anchored tenant-origin patterns.
- Map it to exactly one context: `public`, `app`, `tenant`, or `portal`.
- Require the mapped context to equal the route group's required context.
- Enforce the allowed role and tenant binding separately.
- CORS is an additional browser restriction only; it is not authorization.

**Personal access token / non-browser API client**

- Require a non-wildcard context ability (`ctx:app`, `ctx:tenant`, or `ctx:portal`) for protected application requests.
- Reject context-free or wildcard (`*`) user tokens for interactive context-protected routes.
- If an `Origin` is provided, it must also match the token and route context.
- If no `Origin` is provided, a scoped token is mandatory.

**Sanctum transient/session token**

- A transient token has no personal-access-token ability list. Do not silently bypass context checks.
- Require a validated browser `Origin` matching the route context and enforce the role and tenant binding.
- If the production SPA auth mechanism cannot reliably supply/retain this signal, redesign the session context binding explicitly; do not add a wildcard bypass.

`Origin` and tenant slug are context selectors, not credentials. They must never prove user membership by themselves. Role, token/session authentication, tenant membership, tenant status, facility assignment, and device credential checks remain mandatory.

### 4.4 Configuration

Introduce centralized, documented configuration (names are examples; the agent may align to existing conventions):

- `KIYU_API_ORIGIN`
- `KIYU_PUBLIC_ORIGIN`
- `KIYU_APP_ORIGIN`
- `KIYU_PORTAL_ORIGIN`
- `KIYU_TENANT_DOMAIN_SUFFIX`
- `KIYU_TENANT_ORIGIN_PATTERN` (or equivalent generated/anchored pattern)
- `CORS_ALLOWED_ORIGINS`
- `CORS_ALLOWED_ORIGIN_PATTERNS`

Update `.env.example`, configuration documentation, and tests. Avoid `*` wildcard origins with credentialed browser requests. Validate that a tenant origin has exactly one tenant label before the configured suffix; reserved labels such as `app`, `portal`, `api`, `www`, and `kiyu` must never resolve as tenant slugs.

## 5. Role and access matrix

### 5.1 Application roles

| Context | Allowed authenticated role(s) | Disallowed roles |
|---|---|---|
| Public | None | All authenticated roles for protected operations |
| App | `tenant_admin` | `platform_admin`, `facility_admin`, `operator`, `staff`, `client` |
| Tenant | `facility_admin`, `operator`, `staff` | `platform_admin`, `tenant_admin`, `client` |
| Portal | `platform_admin` | `tenant_admin`, `facility_admin`, `operator`, `staff`, `client` |
| Payment Webhook | No user role | Authentication is provider signature-based |

`client` is an existing domain role, and client/customer records may still be used by public queue journeys. Do not delete it. Under this PRD, `client` is not allowed to authenticate into App, Tenant staff workspace, or Portal. First audit every current `client` login and route dependency. Preserve any required customer-facing functionality through narrowly scoped public tracking/acquisition or a separately approved customer-auth context; do not silently grant `client` access to the Tenant staff workspace.

### 5.2 Role invariants

- `platform_admin` must have `tenant_id = NULL` and is provisioned internally (seeder, CLI command, or controlled operational process), never through public registration.
- `tenant_admin` must belong to exactly one tenant and is the canonical owner/admin identity according to the current domain constraints.
- `facility_admin`, `operator`, and `staff` must belong to exactly one tenant and must have valid facility assignments according to the existing role model.
- No user may change context by modifying request payload, query parameters, or tenant headers.
- Role checks do not replace resource Policies. Facility-scoped users must not gain access to another facility in the same tenant.
- TenantAdmin is intentionally excluded from Tenant Workspace routes. It uses App for organization-wide management and billing.
- PlatformAdmin is intentionally excluded from tenant operational routes and may only operate platform-level resources through Portal.

## 6. Canonical API route topology

All route files are mounted under Laravel's `/api` prefix. Use context path prefixes to make the central API contract explicit.

| Route namespace | Example | Context |
|---|---|---|
| `/api/public/*` | `/api/public/plans` | Public website data |
| `/api/app/*` | `/api/app/auth/login` | TenantAdmin App |
| `/api/tenant/*` | `/api/tenant/tickets` | Tenant Workspace |
| `/api/portal/*` | `/api/portal/tenants` | Platform Portal |
| `/api/webhooks/*` | `/api/webhooks/payments/{provider}` | Provider callback |

The exact endpoint list may be adjusted after the complete route inventory, but every endpoint must belong to exactly one context. Do not expose protected tenant endpoints at the unqualified `/api/*` root after migration.

### 6.1 Public routes

Purpose: public marketing/landing-page data only.

Candidate endpoints:

- `GET /api/public/plans` or a public pricing/catalog endpoint (only public plan names, prices, limits, and published feature descriptions).
- `GET /api/public/tenant-slugs/{slug}/availability` if slug discovery is required for signup.
- `GET /api/public/tenants/{slug}` only if the marketing/onboarding flow requires limited public tenant metadata.

Rules:

- No user session required.
- Do not expose owner contact details, internal tenant settings, subscription/payment records, facility-private information, or operational data.
- SaaS signup is not a Public route; it belongs to App because registration creates a tenant owner account.
- Tenant public queue, tracking, active departments, kiosk intake, and display polling are not general Public website endpoints. They belong to Tenant context and must be tenant-origin-bound.

### 6.2 App routes

Purpose: customer-facing SaaS portal for `tenant_admin`.

Authentication:

- `POST /api/app/auth/signup` — public entry to create tenant + canonical owner + initial facility/onboarding state + trial (subject to existing onboarding rules).
- `POST /api/app/auth/login` — only `tenant_admin` may authenticate.
- `GET /api/app/auth/me` — authenticated TenantAdmin only.
- `POST /api/app/auth/logout` — authenticated TenantAdmin only.
- No tenant-staff registration endpoint.

Functional areas:

- organization profile and settings;
- facility directory and facility lifecycle management;
- tenant-wide user administration and assignments;
- invitation creation/revocation where the inviter is authorized;
- organization dashboard and cross-facility monitoring;
- subscription, plan, add-on catalog, add-on purchase, payment initiation, payment history, invoices/receipts where supported;
- tenant-wide audit log and subscription entitlement view;
- tenant-wide report access only where current product permissions allow it.

App routes must resolve the tenant from the authenticated TenantAdmin user. The customer must not provide an arbitrary `tenant_id` to choose a tenant. The App origin itself does not identify a tenant; the authenticated identity does.

TenantAdmin is not allowed to call operational queue action endpoints, kiosk runtime endpoints, device pairing endpoints, or staff ticket-processing endpoints through App or Tenant.

### 6.3 Tenant routes

Purpose: operational workspace for FacilityAdmin, Operator, and Staff under `{tenant-slug}.kiyu.test`.

Authentication:

- `POST /api/tenant/auth/login` — only `facility_admin`, `operator`, or `staff` whose `tenant.slug` matches the tenant origin.
- `GET /api/tenant/auth/me` — same context and role rules.
- `POST /api/tenant/auth/logout` — same context and role rules.
- No self-registration.
- Invitation acceptance may be unauthenticated before redemption, but must be tenant-origin-bound, single-use, expiring, hashed-token based, and must validate the invitation's tenant and role. It must never accept a client-selected role.

Functional areas:

- facility-scoped dashboard;
- intake and reception workflows;
- queue acquisition and queue processing;
- tickets, ticket events, priority, cancellation, no-show, recall, hold/resume, and authorized transfers;
- queue sessions and customer operations permitted to the role;
- departments, stations, workflows, and workflow steps within assigned facilities for FacilityAdmin; read/operational access for Operator/Staff as currently permitted;
- device administration within facility assignment for FacilityAdmin;
- kiosk/display runtime, pairing, intake, polling, heartbeat, and credential lifecycle;
- facility-scoped report queries that are enabled for the role and subscription;
- WhatsApp send operation only if role policy and entitlement allow it.

Tenant context must be established from the tenant frontend origin and then compared with the authenticated user's tenant. The tenant slug is not an authorization credential.

### 6.4 Portal routes

Purpose: KIYU platform administration only.

Authentication:

- `POST /api/portal/auth/login` — only `platform_admin` with `tenant_id = NULL`.
- `GET /api/portal/auth/me` — authenticated PlatformAdmin only.
- `POST /api/portal/auth/logout` — authenticated PlatformAdmin only.
- There is no register/signup endpoint.

Functional areas:

- platform dashboard;
- tenant directory and tenant metadata/status management (list, detail, suspend/reactivate; no tenant impersonation);
- plan and plan-price catalog management;
- add-on catalog, pricing, eligibility, and entitlement management;
- platform subscription and payment visibility;
- payment webhook event visibility and reconciliation tools;
- platform-level reports (revenue, growth, subscriptions);
- global platform settings;
- platform audit log.

Platform routes must not execute tenant-scoped controllers using a target tenant context. PlatformAdmin must not create/edit tenant staff, update facilities, process queues, pair devices, or access tenant customer operational data.

### 6.5 Payment webhooks

- `POST /api/webhooks/payments/{provider}` remains unauthenticated by user session.
- Route is outside App, Tenant, and Portal context enforcement.
- Validate provider is supported and enabled.
- Verify signature using provider-specific secret/configuration before processing payload.
- Validate transaction/reference identity, expected amount/currency where applicable, and payment state transition.
- Enforce event idempotency and safe replay handling.
- A webhook must not trust a frontend return URL, browser-supplied status, or tenant ID from an unverified payload.
- Do not return sensitive provider or internal error details.

## 7. Authentication design

### 7.1 Separate controllers

Create three independent HTTP auth controllers:

```text
App/Authentication/AppAuthController.php
Tenant/Authentication/TenantAuthController.php
Portal/Authentication/PortalAuthController.php
```

The exact folder names may use `Auth/` instead of `Authentication/`, but use one convention consistently.

Responsibilities:

| Controller | Login | Signup/register | Me | Logout |
|---|---:|---:|---:|---:|
| AppAuthController | TenantAdmin | SaaS tenant signup | TenantAdmin | TenantAdmin |
| TenantAuthController | FacilityAdmin/Operator/Staff | None; invitation acceptance is separate | Same roles | Same roles |
| PortalAuthController | PlatformAdmin | None | PlatformAdmin | PlatformAdmin |

Keep credential verification and low-level token issuance in shared services (for example `AuthenticationService` and `ContextTokenIssuer`). Do not keep a universal HTTP `AuthController` that accepts a context string to choose an application.

### 7.2 Role validation at login

The role is validated by the route/controller context before issuing a token. The user must also satisfy current account status requirements:

- PlatformAdmin: `tenant_id` is null and role is `platform_admin`.
- TenantAdmin: tenant exists, is active/operational, and role is `tenant_admin`.
- Tenant workspace user: tenant exists, is active/operational, slug matches the request's tenant origin, role is one of `facility_admin`, `operator`, `staff`, and facility assignments are valid for scoped roles.

Return a consistent invalid-credentials response for unknown email, wrong password, inactive account, and role/context mismatch where this avoids account enumeration. For a clear authenticated wrong-context request, 403 is acceptable if it does not reveal sensitive account existence.

### 7.3 Token context

Use exactly these context abilities for interactive personal access tokens:

- `ctx:app`
- `ctx:tenant`
- `ctx:portal`

Do not issue a context-free or wildcard (`*`) token from any of the three browser login controllers. Context must be mandatory, never nullable.

A logout endpoint revokes only the current token/session according to the chosen Sanctum transport. Token expiration, revocation, and session invalidation behavior must remain consistent.

Existing wildcard tokens are legacy credentials. The migration plan must invalidate or prevent them from accessing the new context-protected routes. Do not grandfather wildcard tokens into all contexts.

### 7.4 Customer/client accounts

The existing `client` role may support public customer account flows. It is excluded from the three staff/admin application contexts in this PRD. Before removing the existing `/auth/register` or client login behavior, inventory all call sites and data relations (`customers.user_id`, queue tracking, own-history endpoints). Preserve customer data. If authenticated customer functionality is still required, document it as a separate future Customer context rather than smuggling it into Tenant Workspace.

## 8. Middleware architecture

### 8.1 Required middleware responsibilities

Implement or refactor middleware so each responsibility is explicit and composable:

| Middleware responsibility | Required behavior |
|---|---|
| Request application context | Determine and validate Public/App/Tenant/Portal from validated Origin or scoped API token |
| App context | Require `ctx:app` or validated App SPA session; role exactly `tenant_admin` |
| Tenant context | Require `ctx:tenant` or validated Tenant SPA session; role in FacilityAdmin/Operator/Staff; resolve tenant slug and compare with actor tenant |
| Portal context | Require `ctx:portal` or validated Portal SPA session; role exactly `platform_admin`; require `tenant_id = NULL` |
| Public tenant context | Validate tenant frontend origin and resolve active tenant; no user authentication, no staff authorization implied |
| Device context | Resolve tenant/facility from kiosk/display credential; compare resolved device tenant with tenant origin; enforce active/paired/expiry state |
| Facility context | Reuse current facility resolution/assignment rules; never accept arbitrary facility ID without authorization |
| Role | Keep role checks for finer route-specific operations; Policies remain authoritative for resource actions |
| Feature access | Integrate with the existing central Feature Access Engine from the billing/entitlement PRD, including release gate and entitlement checks |
| Throttle | Apply context-appropriate rate limits to login, signup, pairing, tracking, intake, and webhooks |

### 8.2 Naming and aliasing

The current aliases are ambiguous (`tenant` currently resolves user tenant, `platform` means platform role, `platform-tenant` binds support tenant). Replace them with clear names, for example:

- `app.context`
- `tenant.context`
- `portal.context`
- `tenant.public`
- `device.kiosk`
- `device.display`
- `facility`
- `role`
- `feature`

Names may follow existing conventions, but their meaning must be unambiguous. Remove the `platform-tenant` alias entirely after its routes are removed.

### 8.3 Middleware execution order

Protected App request:

```text
request origin/token context
→ auth:sanctum
→ app.context
→ tenant_admin role check
→ resolve tenant from authenticated user
→ policy/resource authorization
→ feature/limit check when relevant
→ controller
```

Protected Tenant request:

```text
request origin/token context
→ auth:sanctum
→ tenant.context
→ tenant slug from validated tenant origin
→ resolve authenticated user's tenant
→ assert origin tenant == user tenant
→ role check (facility_admin/operator/staff)
→ facility context and assignment validation when needed
→ policy/resource authorization
→ feature/limit check when relevant
→ controller
```

Portal request:

```text
request origin/token context
→ auth:sanctum
→ portal.context
→ platform_admin role + tenant_id IS NULL
→ platform policy
→ controller
```

Tenant public request:

```text
validated tenant origin
→ resolve active tenant
→ optional device credential resolution (depending on endpoint)
→ facility resolution where needed
→ endpoint-specific validation/throttle
→ controller
```

Webhook request:

```text
provider route
→ provider throttle
→ provider signature verification
→ payload/reference/amount validation
→ idempotency check
→ payment webhook service
```

Middleware must fail closed. A missing/ambiguous context is not a reason to fall back to a default tenant or application.

## 9. Tenant and device context rules

### 9.1 Authenticated tenant user

For `{tenant-slug}.kiyu.test` browser requests:

1. Extract the tenant slug from the validated frontend Origin.
2. Resolve the authenticated user.
3. Resolve the user's tenant from the database.
4. Compare the origin slug with the user's tenant slug using normalized exact matching.
5. Reject mismatches before any controller executes.
6. Resolve facility only after tenant validation.

Do not allow `X-Tenant-Slug`, `tenant_id`, `facility_id`, query parameters, or route IDs to override the authenticated tenant.

### 9.2 Non-browser tenant API clients

For a scoped non-browser token, require an explicit tenant slug/context header (or documented equivalent) and compare it with the authenticated user's tenant. The header selects a context; it does not grant access.

### 9.3 Device requests

- Device credential is the authoritative identity for paired kiosk/display runtime requests.
- Resolve device record, tenant, facility, active status, paired state, and expiry from the credential.
- If request comes from a tenant browser origin, its tenant slug must match the device's tenant slug.
- Never accept request-body `tenant_id` or `facility_id` as device ownership proof.
- Device routes must not use a user role as a substitute for device credential validation.
- Pairing-code redemption is unauthenticated by user but must resolve a valid, unexpired, single-use pairing code and ensure the code's device tenant matches the tenant origin.

### 9.4 Facility access

Retain existing facility assignment semantics. FacilityAdmin, Operator, and Staff must only operate at assigned facilities. TenantAdmin's organization-wide facility management occurs in App; it is not a reason to allow TenantAdmin into Tenant routes. Every resource query/update must also be scoped by tenant/facility and protected by a Policy or equivalent authorization layer.

## 10. Controller and namespace refactoring

### 10.1 Target controller layout

Use application-context folders as the first-level boundary. Use feature/domain subfolders where they improve discoverability. Example:

```text
app/Http/Controllers/Api/
├── Public/
│   ├── PricingController.php
│   └── TenantDiscoveryController.php
├── App/
│   ├── Authentication/AppAuthController.php
│   ├── Onboarding/SignupController.php
│   ├── DashboardController.php
│   ├── Organization/OrganizationController.php
│   ├── Facilities/FacilityController.php
│   ├── Users/UserController.php
│   ├── Invitations/InvitationController.php
│   ├── Billing/SubscriptionController.php
│   ├── Billing/PaymentController.php
│   ├── Billing/AddonController.php
│   ├── Reports/ReportController.php
│   └── AuditLogController.php
├── Tenant/
│   ├── Authentication/TenantAuthController.php
│   ├── Authentication/InvitationAcceptanceController.php
│   ├── DashboardController.php
│   ├── Intake/ManualIntakeController.php
│   ├── Intake/KioskIntakeController.php
│   ├── Queue/QueueAcquisitionController.php
│   ├── Queue/QueueController.php
│   ├── Queue/QueueSessionController.php
│   ├── Queue/QueueTrackingController.php
│   ├── Queue/TicketController.php
│   ├── Reception/ReceptionController.php
│   ├── Customers/CustomerController.php
│   ├── Facilities/FacilityConfigurationController.php
│   ├── Departments/DepartmentController.php
│   ├── Stations/StationController.php
│   ├── Workflows/WorkflowController.php
│   ├── Devices/KioskDeviceController.php
│   ├── Devices/DisplayDeviceController.php
│   ├── Devices/KioskPairingController.php
│   ├── Devices/DisplayPairingController.php
│   ├── Reports/ReportController.php
│   └── Notifications/WhatsAppController.php
├── Portal/
│   ├── Authentication/PortalAuthController.php
│   ├── DashboardController.php
│   ├── Tenants/TenantController.php
│   ├── Plans/PlanController.php
│   ├── Addons/AddonController.php
│   ├── Subscriptions/SubscriptionController.php
│   ├── Payments/PaymentController.php
│   ├── Reports/ReportController.php
│   ├── Settings/SettingController.php
│   └── AuditLogController.php
└── Webhooks/
    └── PaymentWebhookController.php
```

This is a target organization, not a mandate to create empty controllers for every folder. Create a controller only where there is a real endpoint responsibility. Keep controllers small and single-context.

### 10.2 Controller boundary rule

A controller may only be registered in one application context. A controller cannot be used by both App and Tenant, or both Tenant and Portal.

If two contexts require similar behavior:

- create separate context-specific controllers;
- share the application service/action/query;
- share validation rules only when their constraints are truly identical;
- keep Policies and tenant/facility scoping centralized;
- use separate response resources if the data exposure differs.

Do not solve the issue by checking `if ($user->role...)` repeatedly inside one cross-context controller.

### 10.3 Existing controller migration map

The following is the expected mapping. The agent must expand it after full route inventory and account for every method currently exposed.

| Existing controller | New responsibility / target |
|---|---|
| `Api/AuthController` | Split into `AppAuthController`, `TenantAuthController`, `PortalAuthController`; shared credential/token logic moves to service |
| `Api/PublicController/SignupController` | `App/Onboarding/SignupController`; signup belongs to App |
| `Api/PublicController/TenantResolutionController` | `Public/TenantDiscoveryController` only for safe public metadata/slug lookup; tenant operational resolution is separate middleware |
| `Api/Platform/DashboardController` | `Portal/DashboardController` |
| `Api/Platform/TenantController` | `Portal/Tenants/TenantController`; platform metadata/status only |
| `Api/Platform/PlanController` | `Portal/Plans/PlanController` |
| `Api/Platform/SubscriptionController` | `Portal/Subscriptions/SubscriptionController` |
| `Api/Platform/PaymentController` | `Portal/Payments/PaymentController` (platform read/monitoring) |
| `Api/Platform/ReportController` | `Portal/Reports/ReportController` |
| `Api/Platform/SettingController` | `Portal/Settings/SettingController` |
| `Api/Platform/AuditLogController` | `Portal/AuditLogController` |
| `Api/Portal/DashboardController` | `App/DashboardController` (TenantAdmin organization dashboard) |
| `Api/Billing/SubscriptionController` | `App/Billing/SubscriptionController` |
| `Api/Billing/PaymentController` | `App/Billing/PaymentController` |
| `Api/Billing/WebhookController` | `Webhooks/PaymentWebhookController` |
| `Api/FacilityController` | Split App organization-level facility management and Tenant assigned-facility configuration only where both roles genuinely require different endpoints; share actions/services |
| `Api/UserController` | Split App tenant-wide user management from Tenant facility-scoped staff administration if the latter remains an approved capability |
| `Api/FacilityAssignmentController` | Context-specific App/Tenant controllers if both contexts retain assignment operations; share assignment service and policy |
| `Api/InvitationController` | Separate App invitation administration from Tenant invitation acceptance |
| `Api/DepartmentController` | Tenant facility operations; App controller only if TenantAdmin must configure from App |
| `Api/StationController` | Tenant facility operations; App controller only if TenantAdmin must configure from App |
| `Api/WorkflowController` | Tenant facility operations; App controller only if TenantAdmin must configure from App |
| `Api/IntakeController` | Split manual intake, kiosk intake, and any authenticated online intake by context/auth mechanism |
| `Api/PublicQueueController` | Tenant public queue endpoint, tenant-origin-bound |
| `Api/QueueSessionController` | Split authenticated Tenant queue-session operations from public token-based tracking |
| `Api/QueueController` | Tenant queue processing |
| `Api/QueueAcquisitionController` | Tenant queue acquisition |
| `Api/QueueMonitorController` | Tenant facility monitor; App organization-level dashboard/query must be a separate App endpoint |
| `Api/ReceptionController` | Tenant reception operations |
| `Api/TicketController` | Tenant ticket read/events operations |
| `Api/ServiceTransferController` | Tenant staff transfer operations |
| `Api/CustomerController` | Tenant customer/queue operations; App read-only organization views only if required, with separate controller/query |
| `Api/KioskDeviceController` | Tenant device management, facility-scoped FacilityAdmin access |
| `Api/DisplayDeviceController` | Tenant device management, facility-scoped FacilityAdmin access |
| `Api/KioskPairingController` | Tenant device pairing endpoint with tenant-origin and pairing-code checks |
| `Api/DisplayPairingController` | Tenant device pairing endpoint with tenant-origin and pairing-code checks |
| `Api/WhatsAppController` | Tenant operational messaging endpoint; plan/add-on purchase remains App |
| `Api/AuditLogController` | App tenant-wide audit view if TenantAdmin is the intended audience; Portal has its separate platform audit controller |

If a current controller method serves multiple contexts, split its HTTP entrypoint rather than moving the whole controller to whichever folder is convenient.

## 11. Route file organization

Create route files:

```text
routes/
├── api.php                 # central API route loader only
└── api/
    ├── public.php
    ├── app.php
    ├── tenant.php
    ├── portal.php
    └── webhooks.php
```

`routes/api.php` should only load the route files in a predictable order and may contain shared route registration helpers if needed. It must not become another large mixed-context route file.

Each route file must declare a clear group prefix and middleware stack. Example shape:

```php
// routes/api/app.php (illustrative)
Route::prefix('app')->group(function () {
    // Signup/login are guest endpoints but still App-origin-bound.
    // Authenticated endpoints require auth:sanctum + app context + tenant_admin.
});
```

Do not use `Route::domain()` unless the deployment actually routes API requests to the frontend domain. With a central API host, enforce frontend context through validated Origin/token context as defined above.

## 12. Middleware implementation requirements

### 12.1 Application context middleware

Create one reusable context resolver and thin context guards. The resolver should return a typed context enum/value object, not arbitrary strings scattered through controllers.

Required contexts:

- `public`
- `app`
- `tenant`
- `portal`
- `webhook` may be represented as a deliberately separate non-user integration path rather than an application user context.

The route group chooses the expected context; the request cannot choose its own expected context. Reject unknown origins, multiple/invalid tenant labels, mismatch between route group and origin, invalid tenant slug, and missing context evidence for authenticated requests.

### 12.2 Tenant context middleware

Separate these concerns instead of keeping one overloaded `tenant` middleware:

- resolve tenant for TenantAdmin App from authenticated user;
- resolve tenant for tenant staff from validated tenant origin and compare it to user tenant;
- resolve a public tenant for unauthenticated tenant queue pages;
- resolve device tenant/facility from device credential.

All paths must place the resolved context in request attributes using one consistent key/typed context object. Do not let one middleware overwrite the context established by another without a checked invariant.

### 12.3 Remove platform support middleware

Remove `ResolvePlatformTenant` and `platform-tenant` alias after all `/platform/tenants/{tenant}/manage/*` routes are removed. Remove `acting_as_platform` and similar controller shortcuts if no other approved flow uses them.

Audit the `SupportAccessController`, `SupportAccess` model, table, service, and audit event use. Since impersonation is out of scope, remove support-grant creation/end endpoints unless a separate non-impersonation product use is explicitly documented. Preserve existing support-access database records and audit history; do not drop the table in this refactor.

## 13. Business logic and shared code organization

The HTTP layer is separated by application context; the domain layer is not duplicated.

Suggested shared layers:

```text
app/
├── Actions/                 # use-case operations when action classes are appropriate
├── Services/
│   ├── Authentication/
│   ├── Tenancy/
│   ├── Devices/
│   ├── Queue/
│   ├── Billing/
│   └── Reporting/
├── Policies/                # resource/action authorization
├── Data/ or DTOs/            # typed internal input/output, if project convention supports it
├── Http/
│   ├── Controllers/Api/{Public,App,Tenant,Portal,Webhooks}
│   ├── Middleware/
│   ├── Requests/Api/{App,Tenant,Portal}
│   └── Resources/Api/{App,Tenant,Portal}
└── Enums/
```

Rules:

- Controllers validate/authorize at the HTTP boundary, call a use case, and return a response resource.
- Shared actions/services own the business operation and transaction boundaries.
- Query classes/services own complex read models where query logic is reused.
- Policies enforce resource ownership and facility scope.
- Form Requests must not contain hidden cross-context role logic that is not tested.
- API Resources must not leak platform-only or tenant-private data to other contexts.
- Avoid generic `CommonController`, `BaseTenantController`, or controller inheritance that hides authorization decisions. Existing base controller helpers may be retained only if their behavior is safe and well-defined.

## 14. Billing, entitlement, and feature-release integration

The prior billing/entitlement PRD remains authoritative for plan and add-on behavior. This route refactor must respect it.

- Plan selection, add-on catalog, purchase, payment initiation, billing history, subscription cancellation/change, and tenant entitlement summary belong to App and are TenantAdmin-only.
- Platform plan/add-on catalog administration and platform payment/subscription monitoring belong to Portal and are PlatformAdmin-only.
- Tenant operational endpoints may enforce feature entitlements, but must not expose billing management.
- The central Feature Access Engine remains the single authority for feature release state (`is_feature_released`), effective plan/add-on entitlement, limits, and access decisions.
- Do not implement independent subscription/feature checks inside App/Tenant controllers.
- Device and queue operations must continue to enforce entitlements and quota checks after routes move.
- Payment webhook processing remains in Webhooks and calls existing billing/payment services.

## 15. API compatibility and migration strategy

This is a breaking API organization change because existing route paths and meanings conflict (the current `/api/portal/*` is TenantAdmin-oriented, while the new `/api/portal/*` is PlatformAdmin-only).

### 15.1 Canonical route migration

Prepare an explicit old-to-new endpoint manifest. At minimum:

- existing `/api/platform/*` → canonical `/api/portal/*` (PlatformAdmin);
- existing `/api/portal/*` TenantAdmin functionality → canonical `/api/app/*`;
- existing unqualified tenant operational endpoints (for example `/api/tickets`, `/api/queue-sessions`, `/api/departments`) → `/api/tenant/*`;
- existing public marketing/tenant lookup endpoints → `/api/public/*` or tenant-public subpaths as appropriate;
- existing `/api/auth/*` → split `/api/app/auth/*`, `/api/tenant/auth/*`, `/api/portal/auth/*`;
- existing `/api/kiosk/pair` and `/api/display/pair` → Tenant pairing paths;
- existing `/api/webhooks/payments/{provider}` → `/api/webhooks/payments/{provider}` unless provider integration requires a documented adjustment.

### 15.2 No unsafe legacy aliases

Do not keep old routes if they allow old mixed-context behavior. If a temporary compatibility layer is required for a coordinated deployment:

- gate it behind an explicit config flag, disabled by default in production;
- require the exact same context, role, tenant, facility, device, feature, and policy checks as the new route;
- never map old `/api/portal/*` tenant-admin requests into the new Platform Portal group;
- emit deprecation telemetry/logging;
- set a removal milestone;
- test that the disabled state returns 404/410.

The backend PR must publish the new route list and frontend migration manifest. Do not claim the frontend is compatible until its API client and auth flows are updated and tested.

### 15.3 Database compatibility

No schema migration is expected solely for controller/route folder refactoring. Only add schema changes if the token/session context cannot be securely represented by the current infrastructure, and explain why existing Sanctum token abilities/session behavior are insufficient. Do not delete old tokens or historical support records without an explicit migration plan.

## 16. Error handling and response contract

Keep the API's current response conventions where possible, but standardize context failures:

| Situation | Expected status |
|---|---:|
| Missing/invalid authentication on protected route | 401 |
| Authenticated user has wrong application role/context | 403 |
| Tenant slug does not exist | 404 |
| Tenant origin does not match authenticated tenant | 403 or non-enumerating 404, consistently applied |
| Resource is outside tenant/facility scope | 404 preferred where current isolation behavior uses not-found |
| Invalid request fields | 422 |
| Rate limit | 429 |
| Invalid payment signature | 401/403 according to provider contract; do not process |
| Duplicate webhook event | Idempotent success/accepted response; do not double-apply state |

Do not expose stack traces, internal tenant IDs, provider secrets, hashed credentials, raw device credentials, or cross-tenant record existence through error details.

## 17. Security and authorization requirements

- Context, role, tenant, facility, and device identity are separate checks; none substitutes for another.
- `Origin`/CORS is not an identity mechanism.
- `X-Tenant-Slug`, `X-Facility-Id`, route parameters, and payload IDs are selectors only; validate each against server-side identity and assignment.
- Every tenant-owned query must be tenant-scoped. Every facility-owned resource must be facility-scoped where required.
- Avoid authorization based only on route middleware; use Policies for resource-level actions and protect service entrypoints where they can be invoked outside HTTP.
- PlatformAdmin routes must never call tenant operational controllers through a spoofed tenant context.
- No endpoint may grant or elevate `platform_admin`.
- Invitation acceptance must enforce tenant identity, expiry, single-use, hashed token, and role eligibility.
- Pairing codes must be short-lived, single-use, hash-stored, rate-limited, and tenant-origin-matched.
- Device credentials must be hash-stored and reveal no secret on list/detail responses.
- Webhooks must be signature-verified and idempotent.
- Keep audit events for platform tenant status changes, plan/add-on changes, subscription/payment operations, tenant administration, and sensitive device changes.
- Ensure logs do not include passwords, auth tokens, pairing codes, raw device credentials, or payment secrets.

## 18. Test requirements and acceptance criteria

### 18.1 Route structure

- [ ] `routes/api.php` only loads context-specific route files or minimal common route definitions.
- [ ] Public, App, Tenant, Portal, and Webhook routes are each registered in their own route file.
- [ ] No protected tenant route remains at the unqualified API root after migration.
- [ ] No controller is registered in more than one application context.
- [ ] Route names are unique and route:list output is deterministic.
- [ ] Old `/api/platform/tenants/{tenant}/manage/*` routes return 404/410 and are absent from route:list.

### 18.2 Authentication

- [ ] TenantAdmin can log in only through App.
- [ ] FacilityAdmin, Operator, and Staff can log in only through Tenant and only on their own tenant slug.
- [ ] PlatformAdmin can log in only through Portal and must have `tenant_id = NULL`.
- [ ] Portal has no register/signup endpoint.
- [ ] Tenant staff routes have no public self-registration endpoint.
- [ ] App signup creates only a tenant owner/admin identity and follows the existing onboarding transaction.
- [ ] Login without an explicit application context is rejected; no controller issues `*` token for a browser app.
- [ ] Wildcard/context-free legacy PATs are rejected from new protected context groups.
- [ ] A transient/session token cannot bypass context checks by lacking abilities.
- [ ] A token issued for one context cannot be used in another.
- [ ] Client role cannot authenticate into App, Tenant staff workspace, or Portal.
- [ ] Logout revokes the current auth session/token.

### 18.3 Domain and tenant isolation

- [ ] App origin + TenantAdmin token resolves only the tenant linked to that account.
- [ ] Portal origin + PlatformAdmin token accesses platform resources only.
- [ ] Tenant A user on Tenant B origin is rejected.
- [ ] Tenant slug header cannot override the authenticated user's tenant.
- [ ] Body/query `tenant_id` cannot switch tenant.
- [ ] Reserved subdomains (`app`, `portal`, `api`, `www`, `kiyu`) cannot resolve as tenant slugs.
- [ ] Missing, malformed, multi-label, unknown, or mismatched tenant origin fails closed.
- [ ] Cross-tenant resources remain inaccessible (preserve existing 404 behavior where applicable).

### 18.4 Facility and device context

- [ ] FacilityAdmin, Operator, and Staff are restricted to assigned facilities.
- [ ] An explicit `X-Facility-Id` must belong to the authenticated tenant and user assignment.
- [ ] Kiosk/display credentials determine tenant and facility; request body cannot override either.
- [ ] Pairing code redemption must match the tenant origin and code's device tenant.
- [ ] Device from Tenant A is denied on Tenant B origin.
- [ ] Inactive, unpaired where prohibited, expired, or revoked devices are denied according to existing lifecycle rules.
- [ ] TenantAdmin cannot use Tenant device routes; TenantAdmin manages subscription/capacity in App.

### 18.5 Platform isolation

- [ ] PlatformAdmin can list/view/suspend/reactivate tenant metadata through Portal.
- [ ] PlatformAdmin cannot manage tenant users, facilities, departments, queue sessions, customers, devices, or tickets.
- [ ] `/platform/tenants/{tenant}/manage/*` is removed.
- [ ] `ResolvePlatformTenant` and `platform-tenant` alias are removed after use sites are gone.
- [ ] Historical support audit/database records are preserved unless separately approved.

### 18.6 Billing and webhook

- [ ] Tenant plan and add-on operations are available only in App to TenantAdmin.
- [ ] Platform plan/add-on administration and payment monitoring are available only in Portal to PlatformAdmin.
- [ ] Tenant operational routes continue to enforce Feature Access Engine and limits.
- [ ] Webhook is not blocked by user auth/context middleware.
- [ ] Invalid webhook signature never mutates payment/subscription/add-on state.
- [ ] Duplicate webhook event does not double-apply payment state.

### 18.7 Regression

- [ ] Existing queue acquisition, intake, ticket lifecycle, transfers, reports, devices, invitations, billing, and audit behavior remains correct where still in scope.
- [ ] Full test suite passes.
- [ ] Route inventory is compared before/after to account for every old endpoint.
- [ ] No accidental endpoint deletion or public exposure occurs.

## 19. Test implementation plan

Refactor existing tests rather than deleting them:

- `AuthApiTest` → App, Tenant, and Portal auth test suites.
- `ApplicationContextTest` → four origin contexts and three authenticated contexts.
- `PlatformApiTest` → Portal route coverage.
- `PlatformTenantManagementTest` → replace positive impersonation tests with explicit route-removal/denial tests.
- `TenantIsolationTest` → add tenant-origin slug mismatch tests.
- `TenancyAndIdentityTest` → retain identity/role/facility invariants.
- `FacilityContextTest` → ensure facility selection only occurs after tenant context.
- `IntakeApiTest`, `KioskAndOperationalQueueTest`, `DisplayDeviceTest` → ensure all device/public operational calls are tenant-origin-bound.
- `BillingApiTest`, `PaymentsApiTest` → move tenant billing tests to App; webhook tests remain context-independent.

Add data providers/matrix tests for role × context × origin × token ability. Include browser SPA/transient-token path and personal access token path. Test missing Origin, forged header, wrong tenant slug, wildcard token, expired token, and old context ability.

## 20. Phased implementation plan

### Phase 0 — Inventory and safety baseline

1. Verify current branch/commit and read project instructions.
2. Inventory all API routes, route names, controller methods, middleware, policies, and frontend consumers discoverable in the repository.
3. Capture `php artisan route:list --json` (or equivalent) as baseline.
4. Run focused and full tests; document pre-existing failures separately.
5. Produce controller-to-route and old-to-new route manifests.

**Exit gate:** every existing route has an assigned target context or an explicit removal/deprecation decision.

### Phase 1 — Context and authentication foundation

1. Introduce typed application context and origin resolver.
2. Implement context checks for browser Origin and scoped PAT paths.
3. Reject context-free/wildcard interactive tokens.
4. Split App, Tenant, and Portal auth controllers.
5. Add role-specific login validation and token issuance.
6. Preserve account provisioning logic in shared services.
7. Add auth/context matrix tests.

**Exit gate:** all cross-context login and token reuse tests pass before moving business controllers.

### Phase 2 — Route file split

1. Add `routes/api/{public,app,tenant,portal,webhooks}.php`.
2. Make `routes/api.php` a route loader.
3. Move routes by context and add new prefixes.
4. Apply route-group middleware in a consistent order.
5. Verify every route appears once and route names are unique.

**Exit gate:** new route:list is complete; no mixed/ambiguous route group remains.

### Phase 3 — Controller boundary split

1. Move Platform controllers to `Api/Portal` and TenantAdmin dashboard to `Api/App`.
2. Move tenant billing controllers to App; webhook controller to Webhooks.
3. Split cross-context controllers into separate HTTP controllers.
4. Extract shared logic to Services/Actions/Queries/Policies; do not copy-paste business rules.
5. Update namespaces, dependency injection, route bindings, tests, and API Resources.

**Exit gate:** no controller is mounted in more than one application context; all moved endpoint regression tests pass.

### Phase 4 — Tenant/device enforcement and remove support impersonation

1. Implement tenant-origin slug resolution for central API requests.
2. Enforce actor tenant == origin tenant.
3. Enforce device tenant == origin tenant for pairing/runtime calls.
4. Confirm facility authorization remains intact.
5. Remove `/platform/tenants/{tenant}/manage/*` routes, `ResolvePlatformTenant`, and alias.
6. Remove/deprecate support grant APIs according to the support-access audit; preserve history.

**Exit gate:** tenant-origin and platform-isolation tests pass.

### Phase 5 — Billing, public endpoints, and final route compatibility

1. Move customer billing purchase/management to App.
2. Move platform billing/catalog management to Portal.
3. Keep provider webhook outside user auth/context groups.
4. Keep marketing public data in Public and tenant queue public endpoints under Tenant.
5. Publish route migration manifest and deprecation plan.

**Exit gate:** invalid webhook/context cannot mutate protected state; all API consumers have a documented migration path.

### Phase 6 — Cleanup and final verification

1. Remove obsolete controller classes and middleware only after repository-wide reference search proves they are unused.
2. Remove obsolete imports, aliases, comments, tests, and docs.
3. Update `AGENTS.md`, `CLAUDE.md`, README/API documentation, `.env.example`, and deployment notes.
4. Run full test suite, static analysis, formatter, route:list, and relevant security tests.
5. Produce final implementation report.

**Exit gate:** no unresolved high-severity context/role/tenant bypass; tests pass or every pre-existing failure is documented with evidence.

## 21. Definition of Done

The work is complete only when:

1. Public, App, Tenant, Portal, and Webhook route files exist and are clearly separated.
2. The App, Tenant, and Portal each have independent auth controllers and role rules.
3. App accepts only TenantAdmin; Tenant accepts only FacilityAdmin/Operator/Staff; Portal accepts only PlatformAdmin.
4. Portal has no public signup/register endpoint.
5. App owns tenant signup and all TenantAdmin SaaS billing/organization workflows.
6. Tenant owns operational queue and facility runtime/device workflows.
7. Portal owns platform-level administration only.
8. Payment webhooks are isolated from user authentication and protected by provider verification.
9. Tenant origin slug is checked against the authenticated user/device tenant.
10. Wildcard/context-free interactive tokens cannot bypass context enforcement.
11. No controller is shared across application contexts; shared business logic is extracted/reused.
12. PlatformAdmin tenant impersonation/manage route group and middleware are removed.
13. Feature entitlement/release enforcement and facility/tenant isolation remain functional.
14. Existing data and historical audit records are preserved.
15. Route migration mapping, environment configuration, and developer documentation are updated.
16. Focused and full tests pass, with route inventory and test evidence included in the completion report.

## 22. Explicit decisions and constraints

| Decision | Final rule |
|---|---|
| Number of app contexts | Four: Public, App, Tenant, Portal |
| Authenticated contexts | App, Tenant, Portal |
| Public website | No login; only marketing/public data APIs |
| App role | `tenant_admin` only |
| Tenant role | `facility_admin`, `operator`, `staff` only |
| Portal role | `platform_admin` only |
| Signup | App only; creates tenant owner/admin |
| Tenant self-register | Disabled; invitation acceptance is separate |
| Portal registration | Disabled; PlatformAdmin is provisioned internally |
| PlatformAdmin tenant access | No impersonation, no `/manage` routes |
| Device runtime/pairing | Tenant context, with device/pairing credentials and tenant-origin validation |
| Tenant billing | App context, TenantAdmin only |
| Platform payment/plan admin | Portal context, PlatformAdmin only |
| Payment webhook | Public integration boundary; signature and idempotency required |
| API deployment | One central API; use validated frontend Origin + scoped token context + role/tenant checks |
| Route files | Split by context; root `routes/api.php` is loader |
| Controllers | Single application context per controller |
| Shared business logic | Shared Services/Actions/Queries/Policies; no duplicated logic |
| Database changes | None by default; only if a documented security requirement needs one |
| Frontend work | Out of scope; provide new API contract and migration manifest |

## 23. Repository references

Baseline source files reviewed:

- [`routes/api.php`](https://github.com/slvinnm/kiyu-api/blob/main/routes/api.php)
- [`bootstrap/app.php`](https://github.com/slvinnm/kiyu-api/blob/main/bootstrap/app.php)
- [`app/Http/Controllers/Api/AuthController.php`](https://github.com/slvinnm/kiyu-api/blob/main/app/Http/Controllers/Api/AuthController.php)
- [`app/Http/Middleware/EnsureApplicationContext.php`](https://github.com/slvinnm/kiyu-api/blob/main/app/Http/Middleware/EnsureApplicationContext.php)
- [`app/Http/Middleware/ResolveTenant.php`](https://github.com/slvinnm/kiyu-api/blob/main/app/Http/Middleware/ResolveTenant.php)
- [`app/Http/Middleware/ResolvePublicTenant.php`](https://github.com/slvinnm/kiyu-api/blob/main/app/Http/Middleware/ResolvePublicTenant.php)
- [`app/Http/Middleware/ResolvePlatformTenant.php`](https://github.com/slvinnm/kiyu-api/blob/main/app/Http/Middleware/ResolvePlatformTenant.php)
- [`app/Http/Middleware/EnsurePlatformAccount.php`](https://github.com/slvinnm/kiyu-api/blob/main/app/Http/Middleware/EnsurePlatformAccount.php)
- [`app/Http/Middleware/ResolveFacility.php`](https://github.com/slvinnm/kiyu-api/blob/main/app/Http/Middleware/ResolveFacility.php)
- [`app/Http/Controllers/Api/Portal/DashboardController.php`](https://github.com/slvinnm/kiyu-api/blob/main/app/Http/Controllers/Api/Portal/DashboardController.php)
- [`app/Http/Controllers/Api/Platform/TenantController.php`](https://github.com/slvinnm/kiyu-api/blob/main/app/Http/Controllers/Api/Platform/TenantController.php)
- [`app/Http/Controllers/Api/Platform/SupportAccessController.php`](https://github.com/slvinnm/kiyu-api/blob/main/app/Http/Controllers/Api/Platform/SupportAccessController.php)
- [`app/Http/Controllers/Api/PublicController/SignupController.php`](https://github.com/slvinnm/kiyu-api/blob/main/app/Http/Controllers/Api/PublicController/SignupController.php)
- [`config/cors.php`](https://github.com/slvinnm/kiyu-api/blob/main/config/cors.php)
- [`config/sanctum.php`](https://github.com/slvinnm/kiyu-api/blob/main/config/sanctum.php)
- [`tests/Feature/ApplicationContextTest.php`](https://github.com/slvinnm/kiyu-api/blob/main/tests/Feature/ApplicationContextTest.php)
- [`tests/Feature/PlatformTenantManagementTest.php`](https://github.com/slvinnm/kiyu-api/blob/main/tests/Feature/PlatformTenantManagementTest.php)

**Audit limitation:** this PRD is based on public repository source inspection at the baseline commit. Production database state, deployed reverse-proxy behavior, frontend API call sites outside this repository, and runtime test results were not verified during this document's preparation. The implementation agent must validate those before making irreversible changes.
