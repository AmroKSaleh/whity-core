<?php

declare(strict_types=1);

namespace Whity\Auth;

use PDO;

/**
 * Lifecycle for per-device credentials issued to non-browser clients (native /
 * desktop apps such as the KeyHub KiCad companion) — WC-b-device-tokens.
 *
 * A device credential is a long-lived (90-day) HS256 JWT with type='device' and
 * aud='device'. Its jti is recorded in `devices`; the client later EXCHANGES the
 * credential for a short-lived access token at POST /api/v1/devices/token (the
 * credential is the durable enrollment, not a bearer presented on every call).
 * Revocation inserts the jti into the shared `revoked_tokens` table — the same
 * jti keyspace as access/refresh/mcp tokens — so a revoked device can no longer
 * exchange its credential.
 *
 * Device credentials are EPOCH-CHECKED (unlike mcp_tokens, which are minted
 * through a permission-gated admin flow). Enrollment here is self-service off
 * any live session, so a device credential is closer to a refresh token than to
 * a deliberately-provisioned service token — and refresh tokens are epoch-bound.
 * The credential therefore carries the profile's token_epoch at issue time, and
 * {@see TokenValidator::validateDeviceToken()} rejects it once that epoch is
 * stale. This closes the laundering vector where a stolen short-lived access
 * token is exchanged for 90-day persistence that survives a password change:
 * bumping profiles.token_epoch (on password change) invalidates EVERY device
 * credential, so "change your password" is a real kill switch. A specific lost
 * device is still revoked individually via revoked_tokens without disturbing the
 * others.
 *
 * Worker-safe: no static/global state — all state is per-call on the stack.
 */
final class DeviceCredentialService
{
    /** Device credential lifetime: 90 days (mirrors the MCP token lifetime). */
    public const int CREDENTIAL_LIFETIME_SECONDS = 7_776_000;

    public function __construct(
        private readonly PDO $db,
        private readonly JwtParser $jwtParser,
    ) {}

    /**
     * Issue a device credential, record it in `devices`, and return the raw JWT
     * plus the persisted row's metadata.
     *
     * @return array{token: string, jti: string, id: int, expires_at: string}
     */
    public function issue(
        int $profileId,
        int $tenantId,
        string $email,
        string $name,
        string $platform,
        ?string $fingerprint,
    ): array {
        // email is carried so the exchange can reuse it in the derived session
        // claim (mirrors how the refresh flow reuses the token's email claim).
        // token_epoch binds the credential to the profile's current epoch so a
        // password-change bump invalidates it (see the class docblock).
        $token = $this->jwtParser->create([
            'profile_id'       => $profileId,
            'active_tenant_id' => $tenantId,
            'aud'              => 'device',
            'email'            => $email,
            'token_epoch'      => $this->currentProfileEpoch($profileId),
        ], self::CREDENTIAL_LIFETIME_SECONDS, 'device');

        $claims = $this->jwtParser->parse($token);
        if ($claims === null || !isset($claims['jti'], $claims['exp'])) {
            throw new \RuntimeException('Failed to re-parse freshly issued device credential');
        }
        $jti = (string) $claims['jti'];
        $expiresAt = date('Y-m-d H:i:s', (int) $claims['exp']);

        $id = $this->insertReturningId(
            'INSERT INTO devices (jti, profile_id, tenant_id, name, platform, fingerprint, expires_at)
             VALUES (:jti, :profile_id, :tenant_id, :name, :platform, :fingerprint, :expires_at)',
            [
                ':jti'         => $jti,
                ':profile_id'  => $profileId,
                ':tenant_id'   => $tenantId,
                ':name'        => $name,
                ':platform'    => $platform,
                ':fingerprint' => $fingerprint,
                ':expires_at'  => $expiresAt,
            ]
        );

        return ['token' => $token, 'jti' => $jti, 'id' => $id, 'expires_at' => $expiresAt];
    }

    /**
     * List a profile+tenant's active (non-expired, non-revoked) devices.
     *
     * @return list<array<string, mixed>>
     */
    public function listForProfile(int $profileId, int $tenantId): array
    {
        $stmt = $this->db->prepare("
            SELECT d.id, d.name, d.platform, d.fingerprint, d.last_seen_at, d.expires_at, d.created_at
            FROM   devices d
            WHERE  d.profile_id = ?
              AND  d.tenant_id = ?
              AND  d.expires_at > NOW()
              AND  NOT EXISTS (
                       SELECT 1 FROM revoked_tokens r WHERE r.jti = d.jti
                   )
            ORDER BY d.created_at DESC
        ");
        $stmt->execute([$profileId, $tenantId]);

        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        if (!is_array($rows)) {
            return [];
        }

        return array_values(array_map(static function (array $row): array {
            return [
                'id'           => (int) $row['id'],
                'name'         => (string) $row['name'],
                'platform'     => (string) $row['platform'],
                'fingerprint'  => $row['fingerprint'] !== null ? (string) $row['fingerprint'] : null,
                'last_seen_at' => $row['last_seen_at'] !== null ? (string) $row['last_seen_at'] : null,
                'expires_at'   => (string) $row['expires_at'],
                'created_at'   => (string) $row['created_at'],
            ];
        }, $rows));
    }

    /**
     * Revoke a device by its row id, inserting its jti into revoked_tokens.
     *
     * Ownership-gated: returns false when the id does not exist or belongs to a
     * different profile/tenant, so a caller can only revoke its own devices.
     */
    public function revokeById(int $id, int $profileId, int $tenantId): bool
    {
        $stmt = $this->db->prepare("
            SELECT jti, expires_at FROM devices
            WHERE id = ? AND profile_id = ? AND tenant_id = ?
            LIMIT 1
        ");
        $stmt->execute([$id, $profileId, $tenantId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($row === false) {
            return false;
        }

        // Shared per-jti revocation table (idempotent via UNIQUE(jti)).
        $ins = $this->db->prepare("
            INSERT INTO revoked_tokens (jti, expires_at)
            VALUES (?, ?)
            ON CONFLICT (jti) DO NOTHING
        ");
        $ins->execute([(string) $row['jti'], (string) $row['expires_at']]);

        return true;
    }

    /**
     * The profile's current token epoch (0 when the row is absent), so an issued
     * device credential is bound to it and dies on the next epoch bump.
     */
    private function currentProfileEpoch(int $profileId): int
    {
        $stmt = $this->db->prepare('SELECT token_epoch FROM profiles WHERE id = ? LIMIT 1');
        $stmt->execute([$profileId]);
        $epoch = $stmt->fetchColumn();

        return $epoch === false ? 0 : (int) $epoch;
    }

    /**
     * Portable single-row INSERT returning the new id (Postgres RETURNING /
     * SQLite lastInsertId).
     *
     * @param array<string, mixed> $params
     */
    private function insertReturningId(string $sql, array $params): int
    {
        $driver = (string) $this->db->getAttribute(PDO::ATTR_DRIVER_NAME);
        if ($driver === 'pgsql') {
            $stmt = $this->db->prepare($sql . ' RETURNING id');
            $stmt->execute($params);
            return (int) $stmt->fetchColumn();
        }
        $this->db->prepare($sql)->execute($params);
        return (int) $this->db->lastInsertId();
    }
}
