# Async Job API (`/api/jobs`)

The generic async-job submission + status API (WC-jobs-api) is a thin, tenant-
scoped, RBAC-gated surface over the durable job queue (`jobs` table, WC-queue).
A caller submits an **allow-listed** job for their tenant, then polls it for
status, progress, and — once it completes — its result.

## Endpoints

| Method & path | Permission | Purpose |
|---|---|---|
| `POST /api/jobs` | `jobs:submit` | Enqueue a submittable job for the caller's tenant |
| `GET /api/jobs` | `jobs:read` | List the caller's tenant's jobs (paginated) |
| `GET /api/jobs/{id}` | `jobs:read` | Read one job's status / progress / result |

Routes are versioned (`/api/v1/jobs`) by the router prefix, like every other
`/api/…` route.

### `POST /api/jobs`

Request body:

```json
{
  "name": "core.diagnostics.echo",
  "payload": { "anything": "json" },
  "queue": "default",
  "idempotency_key": "optional-dedupe-key"
}
```

- **`name`** (required) — the job type. It must be **API-submittable**
  (see below); an unknown or internal-only name is rejected `422` with a generic
  message (the allow-list is never disclosed).
- **`payload`** (optional object) — the JSON payload passed verbatim to the handler.
- **`queue`** (optional, default `default`) — 1–64 chars of `[a-z0-9_-]`.
- **`idempotency_key`** (optional, ≤191 chars) — a retried submit with the same
  key returns the **existing** job (`200`) instead of creating a duplicate.

Responses: `201` with the created job (or `200` on an idempotency hit); `422`
validation / non-submittable name; `403` missing `jobs:submit`; `401` unauthenticated.

### `GET /api/jobs/{id}`

Returns the job scoped to the caller's tenant. Another tenant's id (or a missing
one) is `404` — never a cross-tenant existence leak. Shape:

```json
{
  "data": {
    "id": 123, "queue": "default", "name": "core.diagnostics.echo",
    "status": "completed", "progress": 100,
    "attempts": 1, "max_attempts": 3,
    "payload": { "anything": "json" },
    "result": { "echoed": { "anything": "json" } },
    "last_error": null,
    "available_at": "…", "completed_at": "…", "created_at": "…"
  }
}
```

`status` is one of `pending`, `reserved`, `dead`, `completed`. A submitted job is
**retained** on completion (with its `result`) so it can be polled; internal
fire-and-forget jobs stay transient (deleted on completion). Retained completed
jobs are pruned after a retention window by the scheduler
(`JobRepository::pruneCompleted()`).

### `GET /api/jobs`

`{ "data": [ …jobs… ], "pagination": { "page", "perPage", "total", "totalPages" } }`
with `?page=`, `?per_page=` (max 100), and optional `?queue=` / `?status=` filters.

## Security — fail-closed submission

The API does **not** let a caller run any registered handler. A job name is
accepted only if its handler explicitly opted into public submission:

```php
$registry->register(MyJob::NAME, new MyJob(), submittable: true);
```

Handlers registered without that flag can run (internal producers enqueue them)
but are **not** submittable via the API. The one submittable core job is
`core.diagnostics.echo` (echoes its payload as the result) — the reference for
opting in and the vehicle for the API's e2e smoke.

Every submission is stamped with the caller's tenant (`TenantContext`); reads
bind `tenant_id`. See also [HOOK_SYSTEM.md](HOOK_SYSTEM.md) for the event spine
that feeds jobs, and the durable queue worker (`queue:work`).
