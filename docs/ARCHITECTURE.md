# KIYU Architecture

KIYU is a global, general-purpose Queue Management SaaS. One central Laravel API
serves one marketing site, one Platform Portal, and any number of tenant
workspaces, each on its own hostname.

## Hosts

| Host | Frontend context | Allowed roles |
|---|---|---|
| `kiyu.test` / `kiyu.id` | Marketing site | — |
| `app.kiyu.test` / `app.kiyu.id` | Platform Portal | `platform_admin`, `tenant_admin` |
| `{tenant}.kiyu.test` / `{tenant}.kiyu.id` | Tenant Workspace | `facility_admin`, `operator`, `staff`, `client` |
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
├── Customers
│   └── QueueSessions → QueueAcquisitions, QueueTickets → QueueEvents
├── ServiceTransfers (cross-department handoff, Staff-only action)
├── Subscriptions → Payments
└── AuditLogs
```

A user has exactly one global role, which does not change per Facility. The
owner is the tenant's canonical `tenant_admin`. `platform_admin` has no tenant.

## Roles

| Role | Application | Scope |
|---|---|---|
| `platform_admin` | Portal | Entire platform, no tenant |
| `tenant_admin` | Portal | Owner of one tenant; every facility |
| `facility_admin` | Workspace | Assigned facilities only |
| `operator` | Workspace | Queue intake / calling at assigned facilities |
| `staff` | Workspace | Queue processing + Service Transfer |
| `client` | Workspace | Own resources only |

## Authentication contexts

Two independent contexts: `platform_portal` and `tenant_workspace`. Sanctum
tokens are tagged with a context ability (`ctx:platform_portal` /
`ctx:tenant_workspace`). `EnsureApplicationContext` (`context:` middleware)
refuses a token minted by the other frontend. Role↔context legality is checked
at login: a `tenant_admin` may not sign in to a tenant workspace, an `operator`
may not sign in to the Portal.

The frontend stores the Sanctum token in an HMAC-signed, HttpOnly, host-only
cookie. It is never exposed to browser JavaScript. Server components fetch the
API directly with the bearer token; the browser goes through `/api/backend`,
which attaches it.

## Authorization layers

1. **Proxy / layout guards** — optimistic, fail closed; they decide whether to
   render at all.
2. **Middleware** — `tenant` (ResolveTenant: tenant + implicit single Facility),
   `facility` (ResolveFacility: explicit `X-Facility-Id`, validated), `role`,
   `platform`, `context`, `feature` (plan entitlement).
3. **Controllers/services** — every query is tenant-scoped; cross-tenant and
   cross-facility references are rejected.

Hidden navigation is never access control.
