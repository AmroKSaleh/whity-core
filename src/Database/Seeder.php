<?php

declare(strict_types=1);

namespace Whity\Database;

use PDO;

/**
 * Seeder class for database initialization
 *
 * Seeds default tenant, roles, and users with hashed passwords.
 * All inserts use ON CONFLICT for idempotent execution.
 *
 * Initial user passwords are sourced from the INITIAL_ADMIN_PASSWORD,
 * INITIAL_USER_PASSWORD, INITIAL_SUPERUSER_PASSWORD and
 * INITIAL_SYSTEM_ADMIN_PASSWORD environment variables; when unset, a random
 * password is generated and printed once (see {@see InitialPassword}).
 * No static default.
 *
 * In addition to the two default-tenant accounts, the seeder provisions a
 * system-tenant (id 0) superuser (superuser@example.com) holding the admin
 * role.  Unlike the default-tenant admin, a system-tenant admin may manage
 * the global base roles (NULL-tenant roles) and every tenant — see
 * WC-110/WC-223.
 *
 * Profile model (ADR 0005 / WC-10522424)
 * ──────────────────────────────────────
 * Every seeded account is ALSO provisioned as a profile + profile_email +
 * membership so that on a fresh install the system admin (system@whity.local,
 * tenant 0) and the dev fixtures (admin@example.com, user@example.com,
 * superuser@example.com) can authenticate through the new profile login path
 * (AuthHandler::identityClaims) introduced by the dual-claim window
 * (WC-d4340daf).  The users rows are kept in parallel — both identity layers
 * co-exist during Phase B.
 *
 * All profile/profile_email/membership inserts use ON CONFLICT guards so
 * re-seeding is idempotent.
 */
class Seeder
{
    /**
     * Seed the database with default data
     *
     * @param Database $db Database connection instance
     * @return void
     */
    public static function seed(Database $db): void
    {
        $pdo    = $db->getPdo();
        $driver = (string) $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);

        // ── Create default tenant ─────────────────────────────────────────────
        $db->query(
            'INSERT INTO tenants (name, created_at) VALUES (:name, NOW()) ON CONFLICT (name) DO NOTHING',
            [':name' => 'Default Tenant']
        );

        // Fetch the tenant ID
        $tenantResult = $db->query(
            'SELECT id FROM tenants WHERE name = :name',
            [':name' => 'Default Tenant']
        );
        $tenant   = $tenantResult->fetch();
        $tenantId = (int) ($tenant['id'] ?? 1);

        // ── Resolve role IDs ──────────────────────────────────────────────────
        // @tenant-guard-ignore: seed-time bootstrap resolves global default role ids by name; no tenant context exists during seeding
        $adminRoleResult = $db->query(
            'SELECT id FROM roles WHERE name = :name',
            [':name' => 'admin']
        );
        $adminRole   = $adminRoleResult->fetch();
        $adminRoleId = (int) ($adminRole['id'] ?? 1);

        // @tenant-guard-ignore: seed-time bootstrap resolves global default role ids by name; no tenant context exists during seeding
        $userRoleResult = $db->query(
            'SELECT id FROM roles WHERE name = :name',
            [':name' => 'user']
        );
        $userRole   = $userRoleResult->fetch();
        $userRoleId = (int) ($userRole['id'] ?? 2);

        // ── Resolve passwords ────────────────────────────────────────────────
        // Sourced from env vars or a one-time random value — never a static literal.
        $adminPassword     = InitialPassword::hashFor('INITIAL_ADMIN_PASSWORD', 'admin@example.com');
        $userPassword      = InitialPassword::hashFor('INITIAL_USER_PASSWORD', 'user@example.com');
        $superuserPassword = InitialPassword::hashFor('INITIAL_SUPERUSER_PASSWORD', 'superuser@example.com');
        $systemPassword    = InitialPassword::hashFor('INITIAL_SYSTEM_ADMIN_PASSWORD', 'system@whity.local');

        // ── Seed users table (legacy identity layer, Phase B) ─────────────────

        // admin@example.com — default-tenant admin
        $db->query(
            'INSERT INTO users (tenant_id, email, password, role_id, created_at)
             VALUES (:tenant_id, :email, :password, :role_id, NOW())
             ON CONFLICT (tenant_id, email) DO NOTHING',
            [
                ':tenant_id' => $tenantId,
                ':email'     => 'admin@example.com',
                ':password'  => $adminPassword,
                ':role_id'   => $adminRoleId,
            ]
        );

        // user@example.com — default-tenant regular user
        $db->query(
            'INSERT INTO users (tenant_id, email, password, role_id, created_at)
             VALUES (:tenant_id, :email, :password, :role_id, NOW())
             ON CONFLICT (tenant_id, email) DO NOTHING',
            [
                ':tenant_id' => $tenantId,
                ':email'     => 'user@example.com',
                ':password'  => $userPassword,
                ':role_id'   => $userRoleId,
            ]
        );

        // superuser@example.com — system-tenant (id 0) superuser
        // @tenant-guard-ignore: seed-time bootstrap; system-tenant (0) superuser
        $db->query(
            'INSERT INTO users (tenant_id, email, password, role_id, created_at)
             VALUES (0, :email, :password, :role_id, NOW())
             ON CONFLICT (tenant_id, email) DO NOTHING',
            [
                ':email'    => 'superuser@example.com',
                ':password' => $superuserPassword,
                ':role_id'  => $adminRoleId,
            ]
        );

        // ── Seed profile model (ADR 0005, WC-10522424) ────────────────────────
        // Each account gets: profile row + primary verified profile_email +
        // membership in its tenant.  All inserts are idempotent via ON CONFLICT.

        // Accounts: (email, password_hash, tenant_id, role_id)
        /** @var list<array{email: string, password: string, tenant_id: int, role_id: int}> */
        $accounts = [
            [
                'email'     => 'system@whity.local',
                'password'  => $systemPassword,
                'tenant_id' => 0,
                'role_id'   => $adminRoleId,
            ],
            [
                'email'     => 'admin@example.com',
                'password'  => $adminPassword,
                'tenant_id' => $tenantId,
                'role_id'   => $adminRoleId,
            ],
            [
                'email'     => 'user@example.com',
                'password'  => $userPassword,
                'tenant_id' => $tenantId,
                'role_id'   => $userRoleId,
            ],
            [
                'email'     => 'superuser@example.com',
                'password'  => $superuserPassword,
                'tenant_id' => 0,
                'role_id'   => $adminRoleId,
            ],
        ];

        foreach ($accounts as $account) {
            $normEmail = strtolower(trim($account['email']));

            // ── a. Check whether a profile_email already exists ──────────────
            // @tenant-guard-ignore: profile_emails is a sanctioned GLOBAL table (ADR 0005 §2)
            $existing = $db->query(
                'SELECT profile_id FROM profile_emails WHERE email = :email',
                [':email' => $normEmail]
            )->fetch(PDO::FETCH_ASSOC);

            if ($existing !== false) {
                // Profile model rows already present — ensure the membership
                // row exists (may have been removed externally) and move on.
                $profileId = (int) $existing['profile_id'];
            } else {
                // ── b. INSERT a profiles row ──────────────────────────────────
                // Use RETURNING id on PostgreSQL; lastInsertId() on SQLite.
                // @tenant-guard-ignore: profiles is a sanctioned GLOBAL table (ADR 0005 §1)
                $profileParams = [
                    ':display_name'                    => self::localPart($normEmail),
                    ':password_hash'                   => $account['password'],
                    ':two_factor_enabled'              => 0,
                    ':two_factor_secret'               => null,
                    ':two_factor_backup_codes_version' => 0,
                    ':token_epoch'                     => 0,
                ];

                if ($driver === 'pgsql') {
                    $insertStmt = $db->query(
                        "INSERT INTO profiles
                             (display_name, password_hash, two_factor_enabled,
                              two_factor_secret, two_factor_backup_codes_version,
                              token_epoch, created_at, updated_at)
                         VALUES
                             (:display_name, :password_hash, :two_factor_enabled,
                              :two_factor_secret, :two_factor_backup_codes_version,
                              :token_epoch, NOW(), NOW())
                         ON CONFLICT DO NOTHING
                         RETURNING id",
                        $profileParams
                    );
                    $idRow     = $insertStmt->fetch(PDO::FETCH_ASSOC);
                    $profileId = $idRow !== false ? (int) $idRow['id'] : 0;
                } else {
                    $db->query(
                        "INSERT INTO profiles
                             (display_name, password_hash, two_factor_enabled,
                              two_factor_secret, two_factor_backup_codes_version,
                              token_epoch, created_at, updated_at)
                         VALUES
                             (:display_name, :password_hash, :two_factor_enabled,
                              :two_factor_secret, :two_factor_backup_codes_version,
                              :token_epoch, datetime('now'), datetime('now'))
                         ON CONFLICT DO NOTHING",
                        $profileParams
                    );
                    $profileId = (int) $pdo->lastInsertId();
                }

                // ── c. INSERT the primary verified profile_email ──────────────
                // @tenant-guard-ignore: profile_emails is a sanctioned GLOBAL table (ADR 0005 §2)
                $db->query(
                    "INSERT INTO profile_emails
                         (profile_id, email, verified, is_primary, created_at)
                     VALUES
                         (:profile_id, :email, :verified, :is_primary, NOW())
                     ON CONFLICT (email) DO NOTHING",
                    [
                        ':profile_id' => $profileId,
                        ':email'      => $normEmail,
                        ':verified'   => 1,
                        ':is_primary' => 1,
                    ]
                );
            }

            // ── d. INSERT the membership (idempotent via UNIQUE(profile_id, tenant_id))
            // @tenant-guard-ignore: seed-time bootstrap; system-tenant rows use tenant_id = 0
            $db->query(
                "INSERT INTO memberships
                     (profile_id, tenant_id, role_id, ou_id, status, created_at)
                 VALUES
                     (:profile_id, :tenant_id, :role_id, NULL, 'active', NOW())
                 ON CONFLICT (profile_id, tenant_id) DO NOTHING",
                [
                    ':profile_id' => $profileId,
                    ':tenant_id'  => $account['tenant_id'],
                    ':role_id'    => $account['role_id'],
                ]
            );
        }
    }

    /** Returns the local-part (before @) of an email address for display_name. */
    private static function localPart(string $email): string
    {
        $at = strrpos($email, '@');
        return $at !== false ? substr($email, 0, $at) : $email;
    }
}
