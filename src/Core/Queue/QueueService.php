<?php

declare(strict_types=1);

namespace Whity\Core\Queue;

use Whity\Core\Tenant\TenantContext;

/**
 * The producer-side facade for the durable queue: enqueue a named job for a
 * tenant. Registered in the service container so core services, hooks, and
 * plugins enqueue work through one entry point instead of the old static
 * log-only Queue stub.
 *
 * The job is stamped with the CURRENT tenant (from TenantContext) unless an
 * explicit `tenant_id` is passed in `opts` — so a job enqueued while handling a
 * tenant's request runs later under that same tenant. Infra-enqueued work with
 * no tenant context defaults to the system tenant (0).
 */
final class QueueService
{
    private JobRepository $repo;

    public function __construct(JobRepository $repo)
    {
        $this->repo = $repo;
    }

    /**
     * Enqueue a job. Returns its id, or null when a live job with the same
     * (tenant, idempotency_key) already exists (deduped).
     *
     * @param array<string, mixed> $payload
     * @param array{queue?: string, priority?: int, max_attempts?: int, delay?: int, idempotency_key?: string|null, tenant_id?: int} $opts
     */
    public function dispatch(string $name, array $payload = [], array $opts = []): ?int
    {
        $tenantId = isset($opts['tenant_id'])
            ? (int) $opts['tenant_id']
            : (TenantContext::getTenantId() ?? 0);

        return $this->repo->enqueue($tenantId, $name, $payload, $opts);
    }
}
