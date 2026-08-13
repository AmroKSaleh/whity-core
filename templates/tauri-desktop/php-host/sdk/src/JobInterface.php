<?php

declare(strict_types=1);

namespace Whity\Sdk;

/**
 * The async job handler contract (SDK v1.0).
 *
 * A job handler is registered under a NAME in the host's job registry; the
 * durable queue stores enqueued work as {name, payload, tenant_id, …} rows and
 * a worker later reserves a row and invokes the matching handler's
 * {@see self::handle()} with the persisted payload.
 *
 * Guarantees the host provides before calling handle():
 *  - The origin tenant's TenantContext is RESTORED (the tenant_id captured at
 *    enqueue), so tenant-scoped queries inside the handler bind the right
 *    tenant. The host resets TenantContext (and audit/session state) afterwards.
 *  - Retries with exponential backoff on a thrown exception, up to the job's
 *    max_attempts, after which the job is dead-lettered.
 *
 * Contract the handler must uphold:
 *  - **Be idempotent.** Delivery is AT-LEAST-ONCE: a handler may run more than
 *    once for the same job (a worker crash after the side effect but before the
 *    completion write, or a lease-expiry reclaim). Design handle() so a repeat
 *    run is safe (upsert/dedupe/conditional writes), and use an enqueue-time
 *    idempotency key to avoid duplicate enqueues.
 *  - **Signal failure by THROWING.** A thrown exception marks the attempt failed
 *    (→ retry or dead-letter). Returning normally marks the job complete; the
 *    RETURNED ARRAY is the job's result — persisted and readable via the job
 *    status API when the job opted into result retention (an API-submitted job),
 *    and ignored for transient fire-and-forget jobs. Return [] when there is no
 *    meaningful result.
 *  - **Do NOT manage TenantContext or transactions for queue bookkeeping** — the
 *    host owns the tenant restore, the retry policy, and the completion write.
 *
 * @param array<string, mixed> $payload The verbatim JSON payload the job was enqueued with.
 */
interface JobInterface
{
    /**
     * Run the job.
     *
     * @param array<string, mixed> $payload The enqueued payload.
     * @return array<string, mixed> The job result (persisted for retained jobs); [] if none.
     */
    public function handle(array $payload): array;
}
