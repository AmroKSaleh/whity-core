<?php

declare(strict_types=1);

namespace Whity\Core\Identity;

use PDO;

/**
 * Issues and consumes email-verification tokens (WC-235).
 *
 * The security-critical half of the verification flow, deliberately separated
 * from delivery (a {@see \Whity\Core\Mail\Mailer}) and from HTTP
 * ({@see \Whity\Api\EmailVerificationHandler}) so the token lifecycle can be
 * unit-tested in isolation.
 *
 * Token model — single-use, time-boxed, hashed at rest:
 *   - issue() mints a 256-bit random token, stores only its SHA-256 hash, and
 *     returns the RAW token exactly once (for the caller to email). Any prior
 *     outstanding token for the same email is invalidated first, so only the
 *     newest link works.
 *   - confirm() hashes the presented token, matches an unconsumed, unexpired
 *     row, flips the owning `profile_emails.verified` to TRUE and stamps
 *     consumed_at in one transaction. A second confirm with the same token
 *     finds nothing (replay-safe).
 *
 * `email_verifications` is a sanctioned GLOBAL table (no tenant_id); all queries
 * here are exempt from the tenant-predicate guard by that registration.
 */
final class EmailVerificationService
{
    /** Default token lifetime: 24 hours. */
    public const DEFAULT_TTL_SECONDS = 86400;

    /** Raw-token entropy in bytes (256-bit → 64 hex chars). */
    private const TOKEN_BYTES = 32;

    public function __construct(private readonly PDO $db) {}

    /**
     * Mint a verification token for a profile_emails row and persist its hash.
     *
     * Invalidates any prior outstanding (unconsumed) token for the same email so
     * a superseded link stops working. Returns the RAW token — store nothing of
     * it beyond the returned value; only its hash is persisted.
     *
     * @param int $profileEmailId The profile_emails.id being verified.
     * @param int $ttlSeconds     Lifetime; defaults to {@see DEFAULT_TTL_SECONDS}.
     * @return string The raw token to embed in the verification link.
     */
    public function issue(int $profileEmailId, int $ttlSeconds = self::DEFAULT_TTL_SECONDS): string
    {
        $rawToken  = bin2hex(random_bytes(self::TOKEN_BYTES));
        $tokenHash = hash('sha256', $rawToken);
        // UTC throughout so expiry comparison is DB/timezone-independent; the
        // string form sorts lexically the same on Postgres and the SQLite shim.
        $expiresAt = gmdate('Y-m-d H:i:s', time() + $ttlSeconds);

        // Supersede any outstanding token for this email and insert the new one
        // atomically, so a failed INSERT never strands the caller with no live
        // token and concurrent issues cannot both leave a row behind.
        $ownTx = !$this->db->inTransaction();
        if ($ownTx) {
            $this->db->beginTransaction();
        }

        try {
            $this->db->prepare(
                'DELETE FROM email_verifications WHERE profile_email_id = :peid AND consumed_at IS NULL'
            )->execute([':peid' => $profileEmailId]);

            $this->db->prepare(
                'INSERT INTO email_verifications (profile_email_id, token_hash, expires_at, created_at)
                 VALUES (:peid, :hash, :expires_at, NOW())'
            )->execute([
                ':peid'       => $profileEmailId,
                ':hash'       => $tokenHash,
                ':expires_at' => $expiresAt,
            ]);

            if ($ownTx) {
                $this->db->commit();
            }
        } catch (\Throwable $e) {
            if ($ownTx && $this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $e;
        }

        return $rawToken;
    }

    /**
     * Consume a raw verification token: verify the email and burn the token.
     *
     * Matches an unconsumed, unexpired row by token hash, flips the owning
     * email's `verified` flag, and stamps consumed_at — all in one transaction.
     * Returns null (without side effects) for an unknown, expired, or
     * already-consumed token, so the caller can respond generically.
     *
     * @return array{profile_email_id: int, profile_id: int, email: string}|null
     */
    public function confirm(string $rawToken): ?array
    {
        if ($rawToken === '') {
            return null;
        }

        $tokenHash = hash('sha256', $rawToken);
        $now       = gmdate('Y-m-d H:i:s');

        $ownTx = !$this->db->inTransaction();
        if ($ownTx) {
            $this->db->beginTransaction();
        }

        try {
            $stmt = $this->db->prepare(
                'SELECT ev.id AS verification_id, ev.profile_email_id, pe.profile_id, pe.email
                 FROM email_verifications ev
                 JOIN profile_emails pe ON pe.id = ev.profile_email_id
                 WHERE ev.token_hash = :hash
                   AND ev.consumed_at IS NULL
                   AND ev.expires_at > :now
                 LIMIT 1'
            );
            $stmt->execute([':hash' => $tokenHash, ':now' => $now]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);

            if ($row === false) {
                if ($ownTx) {
                    $this->db->commit();
                }
                return null;
            }

            $profileEmailId = (int) $row['profile_email_id'];

            // Mark the email verified (controlled boolean literal; portable).
            $this->db->prepare(
                'UPDATE profile_emails SET verified = true WHERE id = :id'
            )->execute([':id' => $profileEmailId]);

            // Burn the token (single-use). The `consumed_at IS NULL` guard makes
            // a same-token double-submit race idempotent: the loser's UPDATE
            // matches nothing rather than re-stamping an already-consumed row.
            $this->db->prepare(
                'UPDATE email_verifications SET consumed_at = :now WHERE id = :id AND consumed_at IS NULL'
            )->execute([':now' => $now, ':id' => (int) $row['verification_id']]);

            if ($ownTx) {
                $this->db->commit();
            }

            return [
                'profile_email_id' => $profileEmailId,
                'profile_id'       => (int) $row['profile_id'],
                'email'            => (string) $row['email'],
            ];
        } catch (\Throwable $e) {
            if ($ownTx && $this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $e;
        }
    }
}
