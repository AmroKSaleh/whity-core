<?php

declare(strict_types=1);

namespace Whity\Mcp\Auth;

/**
 * Represents a validated MCP AI principal extracted from a bearer token.
 *
 * Returned by TokenValidator::validateMcpToken() on success. Immutable by
 * construction — FrankenPHP worker-safe (no static/global state).
 */
final readonly class McpPrincipal
{
    /**
     * @param int      $userId        User the token was issued to.
     * @param int      $tenantId      Tenant the token is scoped to.
     * @param string   $principalKind Token principal kind ('user' for Phase C).
     * @param string[] $scope         Granted scopes (e.g. ['tools:call']).
     * @param string   $jti           JWT ID (unique revocation handle).
     */
    public function __construct(
        public int $userId,
        public int $tenantId,
        public string $principalKind,
        public array $scope,
        public string $jti,
    ) {}
}
