<?php

declare(strict_types=1);

namespace Whity\Core\Identity;

use PDO;
use PDOStatement;

/**
 * Repository for the `profile_emails` table (WC-99).
 *
 * `profile_emails` is a GLOBAL (non-tenant-scoped) table that stores one or
 * more verified email addresses per profile. Because the table has a
 * UNIQUE(email) constraint, every lookup by email is unambiguous across all
 * tenants — this is the structural fix for issue #181.
 *
 * Methods intentionally carry no tenant_id parameter: emails belong to
 * profiles, and profiles are global. Tenant membership is resolved via the
 * `memberships` table (migration 029), not here.
 */
final class ProfileEmailRepository
{
    private PDO $db;

    public function __construct(PDO $db)
    {
        $this->db = $db;
    }

    /**
     * Insert a new email address for the given profile.
     *
     * @return int The new row's id.
     */
    public function insert(
        int $profileId,
        string $email,
        bool $verified = false,
        bool $isPrimary = false,
    ): int {
        $stmt = $this->db->prepare(
            'INSERT INTO profile_emails (profile_id, email, verified, is_primary, created_at)
             VALUES (:profile_id, :email, :verified, :is_primary, NOW())'
        );
        $stmt->execute([
            ':profile_id' => $profileId,
            ':email'      => $email,
            ':verified'   => $verified ? 1 : 0,
            ':is_primary' => $isPrimary ? 1 : 0,
        ]);
        return (int) $this->db->lastInsertId();
    }

    /**
     * Find a single profile_emails row by its primary key.
     *
     * @return array<string, mixed>|null
     */
    public function findById(int $id): ?array
    {
        $stmt = $this->db->prepare('SELECT * FROM profile_emails WHERE id = :id');
        $stmt->execute([':id' => $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row !== false ? $this->normalizeRow($row) : null;
    }

    /**
     * Find a profile_emails row by email address (globally unique).
     *
     * Used during login to resolve the profile that owns the submitted email.
     *
     * @return array<string, mixed>|null
     */
    public function findByEmail(string $email): ?array
    {
        $stmt = $this->db->prepare('SELECT * FROM profile_emails WHERE email = :email');
        $stmt->execute([':email' => $email]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row !== false ? $this->normalizeRow($row) : null;
    }

    /**
     * Return all email addresses registered for a profile, ordered by created_at.
     *
     * @return list<array<string, mixed>>
     */
    public function findByProfileId(int $profileId): array
    {
        $stmt = $this->db->prepare(
            'SELECT * FROM profile_emails WHERE profile_id = :profile_id ORDER BY created_at ASC'
        );
        $stmt->execute([':profile_id' => $profileId]);
        /** @var list<array<string, mixed>> $rows */
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        return array_map($this->normalizeRow(...), $rows);
    }

    /**
     * Return the is_primary=TRUE email for a profile, or null if none is set.
     *
     * @return array<string, mixed>|null
     */
    public function findPrimaryForProfile(int $profileId): ?array
    {
        $stmt = $this->db->prepare(
            'SELECT * FROM profile_emails WHERE profile_id = :profile_id AND is_primary = TRUE LIMIT 1'
        );
        $stmt->execute([':profile_id' => $profileId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row !== false ? $this->normalizeRow($row) : null;
    }

    /**
     * Mark an email address as verified (or unverified).
     *
     * @return int Rows affected (1 on success, 0 if id not found).
     */
    public function setVerified(int $id, bool $verified): int
    {
        $stmt = $this->db->prepare(
            'UPDATE profile_emails SET verified = :verified WHERE id = :id'
        );
        $stmt->execute([':verified' => $verified ? 1 : 0, ':id' => $id]);
        return $stmt->rowCount();
    }

    /**
     * Atomically promote an email to primary within a profile.
     *
     * Clears is_primary on all other rows for the same profile_id, then sets
     * is_primary on the target row. Both steps are in the same transaction so
     * the profile never has zero or two primaries mid-operation.
     *
     * @return int Rows set to is_primary=TRUE (always 1 on success).
     */
    public function setPrimary(int $profileId, int $id): int
    {
        $clear = $this->db->prepare(
            'UPDATE profile_emails SET is_primary = FALSE WHERE profile_id = :profile_id'
        );
        $clear->execute([':profile_id' => $profileId]);

        $set = $this->db->prepare(
            'UPDATE profile_emails SET is_primary = TRUE WHERE id = :id AND profile_id = :profile_id'
        );
        $set->execute([':id' => $id, ':profile_id' => $profileId]);
        return $set->rowCount();
    }

    /**
     * Delete a profile_emails row by primary key.
     *
     * @return int Rows affected (1 on success, 0 if id not found).
     */
    public function delete(int $id): int
    {
        $stmt = $this->db->prepare('DELETE FROM profile_emails WHERE id = :id');
        $stmt->execute([':id' => $id]);
        return $stmt->rowCount();
    }

    /**
     * Count email addresses registered for a profile.
     */
    public function countForProfile(int $profileId): int
    {
        $stmt = $this->db->prepare(
            'SELECT COUNT(*) FROM profile_emails WHERE profile_id = :profile_id'
        );
        $stmt->execute([':profile_id' => $profileId]);
        return (int) $stmt->fetchColumn();
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
            'id'         => (int) $row['id'],
            'profile_id' => (int) $row['profile_id'],
            'email'      => (string) $row['email'],
            'verified'   => (bool) $row['verified'],
            'is_primary' => (bool) $row['is_primary'],
            'created_at' => (string) $row['created_at'],
        ];
    }
}
