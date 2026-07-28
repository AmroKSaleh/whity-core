# ADR 0013 — Event spine: `domain_events` log + `event_outbox` (transactional outbox)

- Status: Accepted
- Date: 2026-07-28
- Deciders: Whity-Core maintainers
- Related: #154 (this store), #162 (rewire `dispatchAsync`), ADR 0008 (deferred external-automation surface), WC-queue durable job runner (migration 065)

## Context

`HookManager::dispatchAsync($event, $payload)` — fired from 8 core write paths
(`user/tenant/role/ou .created/.updated/.deleted.async`) — pushed onto the
static `Queue::push('whity-core-async-hooks', …)`, a **log-only stub** that
writes one log line and drops the payload. Nothing drained it, so every async
domain event was silently lost. Phase F needs "the single event spine for
automation, notifications, audit and native sync": a durable, tenant-scoped
record of what happened, plus a reliable way to act on it across the persistent
FrankenPHP worker pool.

The contract was flagged provisional pending this ADR; this pins it.

## Decision

Two tables (migration `066_create_domain_events`), two concerns:

1. **`domain_events` — immutable, append-only log.** One row per dispatched
   event: `id` (ULID), `tenant_id`, `event_name`, `aggregate_type`,
   `aggregate_id`, `actor_user_id`, `payload` (jsonb), `occurred_at`,
   `created_at`. Never UPDATEd — the history *is* the product, queryable and
   valuable even before any consumer exists (audit, replay, the native change
   feed). It is NOT the audit_log: `audit_log` records security-relevant actor
   actions for compliance; `domain_events` records business events for
   automation/notification/sync. They overlap in spirit but serve different
   readers and retention rules, so they stay separate.

2. **`event_outbox` — mutable relay bookkeeping (transactional outbox).**
   Exactly one row per event (`event_id` PK/FK), written in the SAME
   transaction as its `domain_events` row. `status`
   (pending→reserved→relayed|dead), `attempts`/`max_attempts`, `available_at`,
   `reserved_at`, `relayed_at`, `last_error`.

**ULID id.** Byte-order equals time-order, so `domain_events(tenant_id, id)`
doubles as a time-ordered cursor for the future native change feed, without a
BIGSERIAL leaking a global row count. Hand-rolled (`Core\Support\Ulid`, 26
Crockford-base32 chars) rather than adding a composer package — per the
third-party-dependency policy.

**Transactional-outbox guarantee.** `DomainEventStore::append()` inserts the
event and its outbox row and, when the caller already holds a transaction (the
common case: `dispatchAsync` mid-request, right after a business write), simply
JOINS it — so the event + intent-to-relay commit atomically with the business
write, or not at all. No dual-write inconsistency, no lost events on rollback.
When the connection is autocommit, `append` wraps its own short transaction so
the pair stays mutually atomic. It never opens a transaction over one the caller
owns (PDO has no real nesting; this also keeps it correct under the PG test
harness that wraps each test in a transaction).

**Relay.** The drain (`reserve`/`markRelayed`/`fail`/`reclaimExpired`) mirrors
the `jobs` queue exactly: atomic
`UPDATE … WHERE event_id = (SELECT … LIMIT 1 [FOR UPDATE SKIP LOCKED]) RETURNING`
so N relay workers each claim a different event with no double-relay
(Postgres); SQLite serialises writes. Retry with backoff, dead-letter on
`max_attempts` (default 25 — event delivery should be persistent), lease-reclaim
for crashed workers. The relay worker itself and the outbox→`jobs` bridge that
fans an event out to registered async listeners land with the first consumer
(notifications), reusing the WC-queue worker rather than adding a second daemon.

**Tenant scoping.** Both tables are tenant-owned (`TenantOwnedTables`), with a
`CrossTenantRejectionRealEngineTest` case. The relay runs as system infra ACROSS
tenants (its queries are annotated `@tenant-guard-ignore`, like the queue);
`event_outbox.tenant_id` is denormalised from the event so the relay can scope
and keep tenant on one row, and each event's origin tenant is restored into
`TenantContext` before any tenant-scoped handler runs. `append` stamps
`tenant_id` from the trusted caller (`TenantContext`).

## Alternatives considered

- **Single table with a `relay_status` column** (no separate outbox). Rejected:
  it would make the "immutable log" mutable (relay churns status on every row),
  muddying the append-only guarantee the change feed and audit rely on.
- **Enqueue straight onto the `jobs` queue from `dispatchAsync`** (skip the
  event log). Rejected: loses the durable, queryable event history — the actual
  point of the spine — and, with no handlers yet registered, would only move the
  loss to dead-lettered jobs.
- **Add a ULID composer package.** Rejected under the dependency policy for a
  ~40-line, well-specified primitive.

## Consequences

- Async events are now durable and tenant-scoped; `dispatchAsync` becomes a real
  persistence call (#162), retiring the `Queue` log stub.
- A new tenant-owned surface to police (registry + cross-tenant test added).
- Relay delivery is not wired yet — events accumulate as `pending` outbox rows
  until the notifications consumer adds the relay worker. This is intentional:
  the log is useful immediately; delivery follows its first real subscriber.
