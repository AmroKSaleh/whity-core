<?php

declare(strict_types=1);

namespace Whity\Mcp\Auth;

use PDO;
use Whity\Auth\JwtParser;

/**
 * Business logic for MCP token lifecycle: issuance, listing, and revocation.
 *
 * Tokens are long-lived (90 days) HS256 JWTs with type='mcp' and aud='mcp'.
 * Issued JTIs are tracked in `mcp_tokens`; revocation inserts the JTI into
 * the shared `revoked_tokens` table, consistent with access/refresh revocation.
 *
 * After migration 040, mcp_tokens is keyed on profiles.id (profile_id) rather
 * than users.id. Issued tokens carry `profile_id` in their JWT claims.
 * The dual-claim window (session bearer via validateSessionBearerForMcp) is
 * unaffected: session tokens still carry user_id and are resolved by
 * principalIdsFromClaims(), which falls back to profile_id when user_id is
 * absent — so both token shapes keep working through step E.
 *
 * Worker-safe: no static/global state — all state is per-call on the stack.
 */
final class McpTokenService
{
    /** MCP token lifetime: 90 days. */
    public const int TOKEN_LIFETIME_SECONDS = 7_776_000;

    public function __construct(
        private readonly PDO $db,
        private readonly JwtParser $jwtParser,
    ) {}

    /**
     * Issue a new MCP token, store its JTI in mcp_tokens, and return the raw JWT.
     *
     * @param string[] $scope         Requested scopes.
     * @param string   $principalKind Principal kind (default 'user').
     */
    public function issue(
        int $profileId,
        int $tenantId,
        string $name,
        array $scope,
        string $principalKind = 'user',
    ): string {
        // Post-cutover: emit only {profile_id, active_tenant_id} — no legacy tenant_id.
        $token = $this->jwtParser->create([
            'profile_id'       => $profileId,
            'active_tenant_id' => $tenantId,
            'aud'              => 'mcp',
            'principal_kind'   => $principalKind,
            'scope'            => $scope,
        ], self::TOKEN_LIFETIME_SECONDS, 'mcp');

        $claims = $this->jwtParser->parse($token);
        if ($claims === null) {
            throw new \RuntimeException('Failed to re-parse freshly issued MCP token');
        }

        $stmt = $this->db->prepare("
            INSERT INTO mcp_tokens (jti, profile_id, tenant_id, name, principal_kind, scope, expires_at)
            VALUES (?, ?, ?, ?, ?, ?, ?)
        ");
        $stmt->execute([
            $claims['jti'],
            $profileId,
            $tenantId,
            $name,
            $principalKind,
            (string) json_encode($scope, JSON_THROW_ON_ERROR),
            date('Y-m-d H:i:s', (int) $claims['exp']),
        ]);

        return $token;
    }

    /**
     * List active (non-expired, non-revoked) MCP tokens for a profile + tenant.
     *
     * @return list<array<string, mixed>>
     */
    public function listForUser(int $profileId, int $tenantId): array
    {
        $stmt = $this->db->prepare("
            SELECT t.jti, t.name, t.principal_kind, t.scope, t.expires_at, t.created_at
            FROM   mcp_tokens t
            WHERE  t.profile_id = ?
              AND  t.tenant_id = ?
              AND  t.expires_at > NOW()
              AND  NOT EXISTS (
                       SELECT 1 FROM revoked_tokens r WHERE r.jti = t.jti
                   )
            ORDER BY t.created_at DESC
        ");
        $stmt->execute([$profileId, $tenantId]);

        $rows = $stmt->fetchAll();
        if (!is_array($rows)) {
            return [];
        }

        return array_values(array_map(function (array $row): array {
            $decoded = json_decode((string) $row['scope'], true);
            $row['scope'] = is_array($decoded) ? $decoded : [];
            return $row;
        }, $rows));
    }

    /**
     * Revoke a token by inserting its JTI into revoked_tokens.
     *
     * Returns false when the JTI does not exist or belongs to a different
     * profile/tenant (authorization guard).
     */
    public function revoke(string $jti, int $profileId, int $tenantId): bool
    {
        // Ownership check: only the issuing profile + tenant can revoke
        $stmt = $this->db->prepare("
            SELECT expires_at FROM mcp_tokens
            WHERE jti = ? AND profile_id = ? AND tenant_id = ?
            LIMIT 1
        ");
        $stmt->execute([$jti, $profileId, $tenantId]);
        $row = $stmt->fetch();

        if ($row === false) {
            return false;
        }

        // Insert into the shared revocation table (idempotent: UNIQUE(jti) already there)
        $stmt = $this->db->prepare("
            INSERT INTO revoked_tokens (jti, expires_at)
            VALUES (?, ?)
            ON CONFLICT (jti) DO NOTHING
        ");
        $stmt->execute([$jti, (string) $row['expires_at']]);

        return true;
    }
}
