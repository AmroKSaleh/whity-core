<?php

declare(strict_types=1);

namespace Whity\Core\Identity;

use PDO;

/**
 * Repository for the `external_identities` table (WC-7ad4).
 *
 * Stores links between an external SSO account — keyed by the provider's stable
 * `(issuer, subject)` pair — and a local `profiles` row, so a returning
 * federated user resolves to their profile without a password.
 *
 * `external_identities` is a GLOBAL (non-tenant-scoped) table, like the
 * `profiles`/`profile_emails` it joins to (ADR 0005): a federated identity
 * belongs to a person, not an org. Methods therefore carry no tenant_id;
 * profile-owned operations (list/unlink) are scoped by `profile_id` so a caller
 * can only touch their own links.
 */
final class ExternalIdentityRepository
{
    private PDO $db;

    public function __construct(PDO $db)
    {
        $this->db = $db;
    }

    /**
     * Link an external account to a profile.
     *
     * The UNIQUE(issuer, subject) constraint enforces that a given external
     * account maps to at most one profile — a duplicate link raises a
     * constraint violation (the caller decides whether that is a conflict).
     *
     * @return int The new row's id.
     */
    public function link(
        int $profileId,
        string $providerKey,
        string $issuer,
        string $subject,
        ?string $email = null,
    ): int {
        $stmt = $this->db->prepare(
            'INSERT INTO external_identities
                 (profile_id, provider_key, issuer, subject, email, linked_at, created_at)
             VALUES (:profile_id, :provider_key, :issuer, :subject, :email, NOW(), NOW())'
        );
        $stmt->execute([
            ':profile_id'   => $profileId,
            ':provider_key' => $providerKey,
            ':issuer'       => $issuer,
            ':subject'      => $subject,
            ':email'        => $email,
        ]);
        return (int) $this->db->lastInsertId();
    }

    /**
     * Resolve the profile linked to an external `(issuer, subject)`, or null if
     * the account has never been linked. This is the federated-login lookup.
     *
     * @return array<string, mixed>|null
     */
    public function findByIssuerSubject(string $issuer, string $subject): ?array
    {
        $stmt = $this->db->prepare(
            'SELECT * FROM external_identities WHERE issuer = :issuer AND subject = :subject LIMIT 1'
        );
        $stmt->execute([':issuer' => $issuer, ':subject' => $subject]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row !== false ? $this->normalizeRow($row) : null;
    }

    /**
     * List all external identities linked to a profile (for the account-settings
     * "connected accounts" UI).
     *
     * @return list<array<string, mixed>>
     */
    public function findByProfileId(int $profileId): array
    {
        $stmt = $this->db->prepare(
            'SELECT * FROM external_identities WHERE profile_id = :profile_id ORDER BY linked_at ASC'
        );
        $stmt->execute([':profile_id' => $profileId]);
        /** @var list<array<string, mixed>> $rows */
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        return array_map($this->normalizeRow(...), $rows);
    }

    /**
     * Count the external identities linked to a profile (used to guard against
     * unlinking a passwordless profile's only sign-in method).
     */
    public function countForProfile(int $profileId): int
    {
        $stmt = $this->db->prepare('SELECT COUNT(*) FROM external_identities WHERE profile_id = :profile_id');
        $stmt->execute([':profile_id' => $profileId]);
        return (int) $stmt->fetchColumn();
    }

    /**
     * Record that this external identity was just used to sign in.
     *
     * @return int Rows affected (1 on success, 0 if id not found).
     */
    public function touchLastLogin(int $id): int
    {
        $stmt = $this->db->prepare(
            'UPDATE external_identities SET last_login_at = NOW() WHERE id = :id'
        );
        $stmt->execute([':id' => $id]);
        return $stmt->rowCount();
    }

    /**
     * Unlink an external identity, scoped to its owning profile so a caller can
     * only remove their OWN link (a cross-profile unlink matches zero rows).
     *
     * @return int Rows affected (1 on success, 0 if not found / wrong profile).
     */
    public function unlink(int $id, int $profileId): int
    {
        $stmt = $this->db->prepare(
            'DELETE FROM external_identities WHERE id = :id AND profile_id = :profile_id'
        );
        $stmt->execute([':id' => $id, ':profile_id' => $profileId]);
        return $stmt->rowCount();
    }

    /**
     * Cast PDO string columns to their proper PHP types.
     *
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function normalizeRow(array $row): array
    {
        return [
            'id'            => (int) $row['id'],
            'profile_id'    => (int) $row['profile_id'],
            'provider_key'  => (string) $row['provider_key'],
            'issuer'        => (string) $row['issuer'],
            'subject'       => (string) $row['subject'],
            'email'         => $row['email'] !== null ? (string) $row['email'] : null,
            'linked_at'     => (string) $row['linked_at'],
            'last_login_at' => $row['last_login_at'] !== null ? (string) $row['last_login_at'] : null,
            'created_at'    => (string) $row['created_at'],
        ];
    }
}
