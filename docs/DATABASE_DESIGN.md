# KIYU Database Design

All tables live in one database (`kiyu`). Tenant isolation is enforced by
application authorization plus tenant-aware foreign keys; the schema below is
the target shape after the fresh baseline.

## Identity & tenancy

| Table | Purpose | Key constraints |
|---|---|---|
| `tenants` | Organizations | `slug` unique, `owner_user_id` → users |
| `facilities` | Locations of a tenant | `unique(tenant_id, code)`, `unique(id, tenant_id)` |
| `users` | Accounts | `email` unique; `tenant_id` nullable (platform), `role` |
| `user_facility_assignments` | Facility reach per user | `unique(tenant_id, user_id, facility_id)` |
| `tenant_invitations` | Hashed, expiring invites | `token_hash` unique; `unique(tenant_id, email, role)` |

A user has one global role. `tenant_admin` reaches every facility implicitly;
`facility_admin`/`operator`/`staff` need assignment rows; `client` needs none.

## Configuration

| Table | Purpose |
|---|---|
| `departments` | Service units within a facility |
| `stations` | Counters/loket within a department (`StationType`: INTAKE, SERVICE, CASHIER, COUNTER) |
| `workflows` | Ordered service sequence within a department |
| `workflow_steps` | Steps of a workflow, each bound to a station + sequence |

## Queue operations

| Table | Purpose | Integrity |
|---|---|---|
| `customers` | General contact record (name, optional phone/email/address) | `tenant_id`; soft deletes |
| `queue_sessions` | A customer's service session | `unique(facility_id, session_number)`, `tracking_token` unique |
| `queue_acquisitions` | Intake record per channel | `unique(tenant_id, idempotency_key)` |
| `queue_tickets` | Numbered service tickets | indexed for station queue selection |
| `queue_events` | Status transitions with actor | `queue_ticket_id`, `user_id` |
| `service_transfers` | Cross-department handoff (Staff-only) | source/target sessions |
| `queue_session_counters` | Facility/date session numbering | `unique(facility_id, counter_date)` |
| `queue_counters` | Station/date ticket numbering | `unique(station_id, counter_date)` |

Numbering is allocated inside a transaction with a unique constraint per
facility/date (sessions) and station/date (tickets), preventing duplicates and
race conditions.

## Billing

| Table | Purpose | Integrity |
|---|---|---|
| `plans` | Product plans | `code` unique |
| `plan_prices` | Price per interval/currency/provider | non-unique lookup index; a superseded price is deactivated (never mutated) so historical payments keep their amount |
| `plan_entitlements` | Feature toggles / limits | `unique(plan_id, feature_code)` |
| `subscriptions` | One effective subscription per tenant (locked check) | `tenant_id`, `plan_id` restrict-delete |
| `payments` | Charges from a provider | `unique(provider, provider_transaction_id)`; subscription restrict-delete (history preserved) |
| `billing_webhook_events` | Provider events | `unique(provider, event_id)` for idempotency |

## Platform

| Table | Purpose |
|---|---|
| `audit_logs` | Tenant + platform actor audit; `tenant_id`, `user_id`, `platform_user_id` |
| `support_accesses` | Time-boxed, reasoned support grants (reason, start, expiry, end) |
| `platform_settings` | Safe runtime settings (registration policy, reserved subdomains); no secrets |

## Notes

- Financial rows (`payments`, `billing_webhook_events`) are never cascade-deleted
  with a subscription.
- Device quotas are aggregated per tenant (`max_kiosk_devices`,
  `max_display_devices`), enforced atomically on registration.
- Registration/pairing use hashed, single-use tokens.
