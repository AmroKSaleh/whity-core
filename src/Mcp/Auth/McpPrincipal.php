<?php

declare(strict_types=1);

namespace Whity\Mcp\Auth;

/**
 * Represents a validated MCP AI principal extracted from a bearer token.
 *
 * Returned by TokenValidator::validateMcpToken() on success. Immutable by
 * construction — FrankenPHP worker-safe (no static/global state).
 *
 * After migration 040, long-lived MCP tokens are keyed on profiles.id and
 * carry `profile_id` in their JWT claims. The dual-claim window means session
 * bearer tokens (type='access') may still carry `user_id` — for those,
 * `principalIdsFromClaims()` falls back to `user_id` and maps it here as
 * `userId` to keep downstream consumers unchanged during the window.
 */
final readonly class McpPrincipal
{
    /**
     * @param int      $profileId     Profile the token was issued to (profile_id claim).
     *                                For session bearer tokens in the dual-claim window,
     *                                this carries the resolved user_id until step E cuts over.
     * @param int      $userId        Kept for backward compatibility during the dual-window;
     *                                equals profileId for new MCP tokens.
     * @param int      $tenantId      Tenant the token is scoped to.
     * @param string   $principalKind Token principal kind ('user' or 'session').
     * @param string[] $scope         Granted scopes (e.g. ['tools:call']).
     * @param string   $jti           JWT ID (unique revocation handle).
     */
    public function __construct(
        public int $profileId,
        public int $userId,
        public int $tenantId,
        public string $principalKind,
        public array $scope,
        public string $jti,
    ) {}
}
