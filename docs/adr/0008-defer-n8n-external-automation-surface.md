# ADR 0008: Defer GH #45 — Reframe as External Automation Surface

- **Status:** Deferred
- **Date:** 2026-06-29
- **Task / Issue:** WC-d51e7658 (GH #45)
- **Deciders:** Amro Saleh

## Context

GitHub issue #45 proposed **embedding n8n** inside Whity Core as the primary workflow
automation engine. The motivating need was clear: platform events (user created, role
changed, tenant onboarded, etc.) should be able to trigger external workflows —
notifications, provisioning steps, integrations with third-party services.

Since #45 was filed, **MCP (Model Context Protocol) support landed in Phase C
(ADR-0006)**. That changes the picture considerably.

### What MCP already delivers

Every permission-gated API route is automatically exposed as a callable MCP tool,
authenticated by AI-principal tokens (`principal_kind: ai-agent | service`), tenant-scoped
via `TenantContext`, and RBAC-gated through `RoleChecker` — the same guarantees as human
HTTP callers. Tool lists are derived from `Router::getRoutes()` at worker boot and kept
current with plugin hot-reload. Rate limiting, audit logging, and tenant isolation are
all handled by the existing infrastructure (ADR-0006, §5–7).

This means any workflow orchestrator that speaks MCP — including n8n's upcoming MCP
integration, Claude, or a custom service-account agent — can already **call any platform
operation** without any additional embedding work.

### Why embedding n8n would duplicate existing layers

Embedding n8n inside Whity Core (as a managed service or co-process) would require:

1. **A second authentication layer** — n8n has its own user/credential model. Bridging
   it to Whity Core's JWT + RBAC means maintaining a credential mapping, token exchange,
   or a privileged service-account with elevated trust — all of which are custom code on
   top of the auth system we already have.

2. **A second tenancy layer** — Whity Core is multi-tenant; n8n is not natively
   multi-tenant. Making n8n tenant-aware would require per-tenant workflow namespaces,
   credential isolation, and execution scoping — duplicating the isolation guarantees
   that `TenantContext` and `EnforceTenantIsolation` already provide.

3. **An additional operational surface** — n8n is a long-running stateful service with
   its own database, process lifecycle, and failure modes. Embedding it couples its
   availability to the platform's availability.

4. **Maintenance of a tight coupling** — n8n API changes, licensing changes (n8n moved
   from Apache-2.0 to a fair-code licence in 2020), or version drift would require
   coordinated updates inside the platform release cycle.

### Forces

- **FrankenPHP persistent workers** — the platform runs in worker mode; embedding a
  long-running external service process is an operational anti-pattern here.
- **Tenant isolation is a hard constraint** — anything that processes cross-tenant events
  must be tenant-scoped from day one (see [TENANT_ISOLATION](../wiki/TENANT_ISOLATION.md)).
- **The queue is already there** — `dispatchAsync` pushes events onto
  `whity-core-async-hooks`; a native event catalogue is a natural extension of the
  existing hook system, not a new subsystem.
- **MCP is the AI automation interface** — ADR-0006 establishes MCP as the primary
  interface for AI-agent calls. Introducing a parallel automation pathway via embedded
  n8n fragments the automation story.

---

## Decision

We will **not embed n8n** in Whity Core.

GH #45 is relabelled as **"external automation surface"** and its scope narrowed to:

1. **Native event catalogue** — a queryable registry of platform event types
   (e.g. `role.created`, `user.updated`, `tenant.onboarded`), their payload shapes, and
   the tenants/contexts they are scoped to. This is an extension of the existing hook
   system's event table in [HOOK_SYSTEM](../wiki/HOOK_SYSTEM.md).

2. **Webhook-trigger registration** — a tenant-scoped API for registering HTTP callback
   endpoints that receive a signed payload when a named event fires. No n8n process; just
   an async hook listener that POSTs to a registered URL. External orchestrators (n8n,
   Zapier, a custom service) subscribe to these webhooks and handle the downstream logic
   themselves.

This approach gives external tools what they need (a reliable event stream with tenant
scoping) without pulling their runtime complexity into the platform.

### MCP-vs-n8n positioning

These two tools are **complementary, not competing**:

| Dimension | MCP | n8n (external) |
|---|---|---|
| Invocation direction | AI agent → platform (pull / call) | Platform → external system (push / event) |
| Transport | JSON-RPC 2.0 over HTTP | Webhooks / REST / polling |
| Auth model | MCP token (JWT, RBAC-gated) | Webhook HMAC signature |
| Tenant scoping | `TenantContext` per call | Payload carries `tenant_id`; receiver is responsible |
| Primary use case | Synchronous AI-callable tool execution | Event-driven workflow automation |
| Who initiates | External agent | Platform event |

**MCP** is the interface for AI agents and service accounts that need to _read or
mutate_ platform state on demand. **n8n** (or any equivalent orchestrator) connects
_from outside_ by subscribing to webhook events or calling MCP tools — it does not
need to run inside the platform to do so.

An n8n workflow that provisions a user when a new tenant is created would:
1. Subscribe to the `tenant.created` webhook endpoint.
2. Receive the signed payload (carrying `tenant_id`).
3. Call `POST /mcp` → `tools/call` → `createUser` with an MCP service-account token
   scoped to that tenant.

No embedding required.

---

## Alternatives Considered

- **Embed n8n as proposed in GH #45** — rejected; duplicates auth and multi-tenancy
  layers, introduces a fair-code licensed dependency, and couples an external service's
  lifecycle to the platform's.

- **Build a full internal workflow engine (DAG executor)** — rejected; far higher
  complexity than the use cases justify, and the MCP layer already covers the
  synchronous-call half of the problem.

- **Do nothing (no automation surface at all)** — rejected; platform events have genuine
  external consumers and the hook system already queues them. A lightweight webhook
  registration API is low-risk and high-value.

---

## Consequences

### Positive

- No duplicated auth or tenancy layer to maintain.
- No fair-code / proprietary licensing exposure from bundling n8n.
- MCP remains the single AI automation interface; the event/webhook surface is additive
  and orthogonal.
- External orchestrators (n8n, Zapier, custom services) are first-class consumers of the
  platform's event stream without being embedded in it.
- The native event catalogue documents the hook system's event shapes as a byproduct —
  improving the developer experience for plugin authors.

### Negative / Trade-offs

- The webhook-trigger registration API described above is **not yet implemented** — GH #45
  stays open as a backlog item tracking that work.
- External orchestrators must handle their own retry/backoff logic on webhook delivery
  failures. The platform guarantees at-least-once delivery from the async queue, but does
  not manage the orchestrator's reliability.
- n8n's own MCP support is still maturing; teams that want n8n-driven automation today
  will use n8n's HTTP Request node against the REST API or the webhook endpoint, not MCP.

### Impact on existing conventions

- No source files change as part of this ADR.
- GH #45 should be relabelled in GitHub: title → "External automation surface: event
  catalogue + webhook-trigger registration"; label → `backlog`.
- Future webhook-registration work will add a tenant-scoped table (e.g.
  `webhook_subscriptions`) and an async hook listener. It must follow the
  `SanctionedGlobalTables` convention and carry explicit `tenant_id` predicates on all
  queries (see [TENANT_ISOLATION](../wiki/TENANT_ISOLATION.md)).
- Plugin authors who want to emit events consumable by external systems should use
  `dispatchAsync` with a well-named event key; the event catalogue will index these.

---

## References

- [ADR-0006: MCP Server Architecture](0006-mcp-server-architecture.md)
- [HOOK_SYSTEM](../wiki/HOOK_SYSTEM.md)
- [TENANT_ISOLATION](../wiki/TENANT_ISOLATION.md)
- GH #45 — original n8n embedding proposal (to be relabelled)
- [MCP specification 2025-03-26](https://spec.modelcontextprotocol.io/)
- n8n fair-code licence: [https://github.com/n8n-io/n8n/blob/master/LICENSE.md](https://github.com/n8n-io/n8n/blob/master/LICENSE.md)
