<?php

declare(strict_types=1);

namespace HelloWorld\Jobs;

use Whity\Core\Tenant\TenantContext;
use Whity\Sdk\JobInterface;

/**
 * Reference async job for the HelloWorld plugin: count the calling tenant's
 * greetings.
 *
 * Registered from {@see \HelloWorld\HelloWorldPlugin::getJobs()} under the BARE
 * name `greeting_digest`; the host stamps the plugin's namespace onto it, so the
 * name to enqueue is `helloworld:greeting_digest`.
 *
 * Three things the tutorial needs it to demonstrate, and nothing more:
 *
 *  - It reads the plugin's OWN table through a connection the plugin supplied
 *    when it built the handler, so a plugin's data access reaches its job
 *    exactly as it reaches its route handlers — the host injects nothing beyond
 *    the payload, because it cannot know what a plugin's handler needs.
 *  - The query is tenant-scoped from {@see TenantContext}, which the host
 *    restores to the ENQUEUING tenant before calling ({@see JobInterface}). A
 *    job belongs to the tenant that asked for it, not to whoever runs the worker.
 *  - It is IDEMPOTENT, as the contract requires: it only reads, so the re-run
 *    that follows a worker crash produces the same digest and no second effect.
 */
final class GreetingDigestJob implements JobInterface
{
    /** The bare name the plugin declares; the host namespaces it. */
    public const NAME = 'greeting_digest';

    /** @var \Closure(): \PDO */
    private \Closure $connection;

    /**
     * @param \Closure(): \PDO $connection Resolves a live connection PER RUN.
     *                                     A handler outlives every job it runs in
     *                                     a persistent worker, so a PDO captured
     *                                     here would pin a connection the host
     *                                     may already have recycled.
     */
    public function __construct(\Closure $connection)
    {
        $this->connection = $connection;
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public function handle(array $payload): array
    {
        $tenantId = TenantContext::getTenantId();
        if ($tenantId === null) {
            // Fail LOUDLY rather than counting across tenants: the host restores
            // the origin tenant before calling, so an absent one means this ran
            // through a path that did not — and a cross-tenant total handed back
            // as one tenant's digest is worse than a retry.
            throw new \RuntimeException('greeting_digest ran without a tenant context');
        }

        $stmt = ($this->connection)()->prepare(
            'SELECT COUNT(*) AS total FROM hello_greetings WHERE tenant_id = :tenant_id'
        );
        $stmt->execute([':tenant_id' => $tenantId]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);

        return [
            'tenantId'  => $tenantId,
            'greetings' => (int) ($row['total'] ?? 0),
        ];
    }
}
