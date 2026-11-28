# KIYU Architecture

KIYU is a global, general-purpose Queue Management SaaS. One central Laravel API
serves one marketing site, one Platform Portal, and any number of tenant
workspaces, each on its own hostname.

## Hosts

| Host | Frontend context | Allowed roles |
|---|---|---|
| `kiyu.test` / `kiyu.id` | Public marketing site | — |
| `app.kiyu.test` / `app.kiyu.id` | App (customer SaaS) | `tenant_admin` |
| `{tenant}.kiyu.test` / `{tenant}.kiyu.id` | Tenant Workspace | `facility_admin`, `operator`, `staff` |
| `{tenant}.kiyu.test` / `{tenant}.kiyu.id` | Customer self-service | `customer` |
| `portal.kiyu.test` / `portal.kiyu.id` | Platform Portal | `platform_admin` |
| `api.kiyu.test` / `api.kiyu.id` | Laravel API | — |

The hostname selects an **application context**, never an authorization. Every
protected endpoint re-checks role, membership and Facility assignment.

## Domain model

```
Tenant (organization)
├── owner (User, role tenant_admin, one per tenant)
├── Facilities
│   ├── Departments
│   │   ├── Stations (loket)
│   │   └── Workflows → WorkflowSteps
│   ├── KioskDevices
│   └── DisplayDevices
├── Users (global role; Facility assignment rows)
├── Customers (linked to a `customer` user via customers.user_id)
│   └── QueueSessions → QueueAcquisitions, QueueTickets → QueueEvents
├── ServiceTransfers (cross-department handoff, Staff-only action)
├── Subscriptions → SubscriptionEntitlements, Payments → PaymentItems
├── Addons → AddonPrices, AddonEntitlements, PlanAddonEligibilities
│   └── SubscriptionAddons → SubscriptionAddonEntitlements, AddonUsageEvents
└── AuditLogs
```

A user has exactly one global role, which does not change per Facility. The
owner is the tenant's canonical `tenant_admin`. `platform_admin` has no tenant.
A `customer` account is a self-service end user, distinct from a `Customer`
domain record (which may exist without any login).

## Roles

| Role | Application context | Scope |
|---|---|---|
| `platform_admin` | Portal | Entire platform, no tenant |
| `tenant_admin` | App | Owner of one tenant; every facility |
| `facility_admin` | Tenant | Assigned facilities only |
| `operator` | Tenant | Queue intake / calling at assigned facilities |
| `staff` | Tenant | Queue processing + Service Transfer |
| `customer` | Customer | Own profile / own visits / own bookings |

## Authentication contexts

Five frontend contexts: `public`, `customer`, `app`, `tenant`, `portal`. Sanctum
tokens are tagged with exactly one context ability (`ctx:customer`, `ctx:app`,
`ctx:tenant`, `ctx:portal`); a wildcard or ability-less token is rejected on
every protected route. `EnsureApplicationContext` (`context:` middleware)
refuses a token minted by another frontend **and** requires a browser `Origin`
that maps to the route's context (a customer rides the tenant subdomain origin).
`Origin` is a context selector, not authentication.

For the Tenant and Customer contexts `EnsureTenantOriginBinding` (`tenant.bind`)
requires the validated origin slug to equal the authenticated user's tenant
slug (or the device's registered tenant). Role↔context legality is checked at
login: a `tenant_admin` may not sign in to a Tenant workspace, an `operator` may
not sign in to the App or Portal.

The frontend stores the Sanctum token in an HMAC-signed, HttpOnly, host-only
cookie. It is never exposed to browser JavaScript. Server components fetch the
API directly with the bearer token; the browser goes through `/api/backend`,
which attaches it.

## Route topology

`routes/api.php` is a loader only; each context has its own file:

```
routes/api/public.php   → /api/public/*
routes/api/customer.php → /api/customer/*
routes/api/app.php      → /api/app/*
routes/api/tenant.php   → /api/tenant/*
routes/api/portal.php   → /api/portal/*
routes/api/webhooks.php → /api/webhooks/*
```

A controller is registered in exactly one context. Shared domain logic lives in
`app/Services/**` (facilities, staff, configuration, billing, access); the HTTP
controllers are thin adapters.

## Authorization layers

1. **Proxy / layout guards** — optimistic, fail closed; they decide whether to
   render at all.
2. **Middleware** — `context` (EnsureApplicationContext), `tenant.bind`
   (origin ↔ actor tenant), `app.tenant` (tenant from the TenantAdmin),
   `tenant.origin` (guest tenant resolution; also resolves a paired
   `X-Kiosk-Token`/`X-Display-Token` device and pins tenant + facility),
   `tenant` (ResolveTenant), `role`, `feature` (Feature Access Engine
   entitlement gate). There is no facility middleware: the facility comes from
   `facility_id` in the request or is resolved implicitly.
3. **Controllers/services** — every query is tenant-scoped; cross-tenant and
   cross-facility references are rejected.

Hidden navigation is never access control.

## Feature access

`is_feature_released` is a global release switch on the `features` registry;
`false` denies the feature in every context regardless of entitlement. Plans and
active subscription add-ons grant entitlements that are **snapshotted** onto the
subscription (`subscription_entitlements`, `subscription_addon_entitlements`) at
activation, so catalogue edits never change what an existing subscriber bought.
Capacity and period quotas are resolved from the snapshot plus active add-on
usage and are checked inside a database transaction.