<?php

declare(strict_types=1);

namespace Whity\Core\Audit;

/**
 * Contract for the platform's security audit writer.
 *
 * Extracted so MCP handlers and other consumers can accept a mockable
 * dependency rather than the final concrete {@see AuditLogger}.
 */
interface AuditLoggerInterface
{
    /**
     * Record an audit entry.
     *
     * @param string $action Stable action key (e.g. `mcp.tools.call`).
     * @param array{
     *     tenant_id?: int|null,
     *     actor_user_id?: int|null,
     *     target_type?: string|null,
     *     target_id?: int|null,
     *     metadata?: array<string, mixed>,
     *     ip_address?: string|null
     * } $options Optional fields. Anything omitted is resolved from context.
     * @return void
     */
    public function record(string $action, array $options = []): void;
}
