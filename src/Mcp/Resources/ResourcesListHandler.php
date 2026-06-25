<?php

declare(strict_types=1);

namespace Whity\Mcp\Resources;

use Whity\Mcp\JsonRpc\MethodHandler;

/**
 * MCP resources/list handler (WC-30513809).
 *
 * Returns all GET routes with schemas as MCP resources (static, no path params)
 * or resourceTemplates (RFC 6570 templates, routes with path params).
 * No authentication filtering is applied here — all declared resources are
 * listed and RBAC is enforced individually at resources/read time.
 */
final class ResourcesListHandler implements MethodHandler
{
    public function __construct(private readonly ResourceDeriver $resourceDeriver) {}

    /** @param array<string, mixed>|null $params */
    public function __invoke(?array $params, ?string $bearerToken): mixed
    {
        return $this->resourceDeriver->deriveResources();
    }
}
