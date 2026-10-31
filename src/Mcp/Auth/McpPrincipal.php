<?php

declare(strict_types=1);

namespace Whity\Mcp\Auth;

/**
 * Represents a validated MCP AI principal extracted from a bearer token.
 *
 * Returned by TokenValidator::validateMcpToken() on success. Immutable by
 * construction — FrankenPHP worker-safe (no static/global state).
 *
 * After migration 040 + step E cutover, MCP tokens carry only profile_id.
 * Both profileId and userId carry the same value (the profile_id) — userId
 * is retained to avoid breaking MCP server callers that still read it.
 */
final readonly class McpPrincipal
{
    /**
     * @param int      $profileId     Profile the token was issued to (profile_id claim).
     * @param int      $userId        Alias for profileId — retained for MCP server callers
     *                                that read it. Both are equal to profile_id post-cutover.
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

    /** The principal kind of an agent credential (ADR-0006 §3, WHIT-753). Not minted until agent proposals exist (WHIT-784). */
    public const KIND_AGENT = 'ai-agent';

    public function isAgent(): bool
    {
        return $this->principalKind === self::KIND_AGENT;
    }

    /**
     * Who this principal acts as, for the audit trail and domain events: an
     * agent for the person who granted it, or the person themselves.
     */
    public function actor(): \Whity\Core\Audit\Actor
    {
        return $this->isAgent()
            ? \Whity\Core\Audit\Actor::agent($this->jti, $this->userId)
            : \Whity\Core\Audit\Actor::person($this->userId);
    }
}
