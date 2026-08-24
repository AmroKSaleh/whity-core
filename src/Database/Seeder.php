<?php

declare(strict_types=1);

namespace Whity\Database;

use PDO;
use Whity\Core\Identity\AuthMethod;

/**
 * Seeder class for database initialization
 *
 * Seeds the default tenant, roles, and profile-model identity rows
 * (profiles + profile_emails + memberships) with hashed passwords.
 * All inserts use ON CONFLICT for idempotent execution.
 *
 * Initial user passwords are sourced from the INITIAL_ADMIN_PASSWORD,
 * INITIAL_USER_PASSWORD, INITIAL_SUPERUSER_PASSWORD and
 * INITIAL_SYSTEM_ADMIN_PASSWORD environment variables; when unset, a random
 * password is generated and printed once (see {@see InitialPassword}).
 * No static default.
 *
 * Two account tiers (WC-779)
 * ──────────────────────────
 *  1. The BOOTSTRAP administrator — system tenant (id 0), admin role, address
 *     from {@see BootstrapIdentity}. Every install needs this: it is the
 *     account an operator first signs in with. Seeded in every environment,
 *     and kept in step with the address migration 095 resolved.
 *  2. The DEV FIXTURES — admin@example.com, user@example.com and the
 *     system-tenant superuser@example.com. These are demo logins for local
 *     work, and are provisioned ONLY under APP_ENV=development. A
 *     production/staging seed that quietly materialised three known-address
 *     accounts holding real credentials is precisely what this gate exists to
 *     prevent; the release smoke job has documented the seeder as "a no-op
 *     outside development" since before it actually was one. Pass
 *     $includeDevFixtures explicitly (the `seed --with-fixtures` flag) when a
 *     non-development environment genuinely wants them.
 *
 * Unlike the default-tenant admin, a system-tenant admin may manage the global
 * base roles (NULL-tenant roles) and every tenant — see WC-110/WC-223.
 *
 * Profile model (ADR 0005 / WC-10522424 / WC-idcut-F)
 * ───────────────────────────────────────────────────
 * Every seeded account is provisioned as a profile + profile_email +
 * membership so that on a fresh install the bootstrap administrator (tenant 0)
 * and the dev fixtures authenticate through the profile login path
 * (AuthHandler::identityClaims). The legacy `users` table was retired by the
 * identity hard cutover (migration 042), so profiles/profile_emails/memberships
 * are now the sole identity layer.
 *
 * All profile/profile_email/membership inserts use ON CONFLICT guards so
 * re-seeding is idempotent. An account that ALREADY exists is left completely
 * alone — including its password (see {@see reportInertPassword()}).
 */
class Seeder
{
    /**
     * The name of the tenant every install gets beside the system tenant.
     *
     * A constant rather than a repeated literal because a SECOND caller now has
     * to find that tenant by name: {@see \Whity\Cli\Commands\SeedCommand}
     * resolves its id to hand to the document demo seeder. Two spellings of one
     * literal is how a seeder comes to create a tenant that the thing seeding
     * into it cannot find — and it would fail as "no demo data appeared", with
     * nothing anywhere saying why.
     */
    public const DEFAULT_TENANT_NAME = 'Default Tenant';

    /**
     * Seed the database with default data
     *
     * @param Database  $db                Database connection instance
     * @param bool|null $includeDevFixtures Whether to provision the
     *        `*@example.com` demo accounts. Null (the default) decides from
     *        APP_ENV: development only.
     * @return string The address the bootstrap administrator is at afterwards.
     *         Returned rather than left to the caller to re-derive, because a
     *         refused rename (see {@see BootstrapIdentity::applyConfiguredEmail()})
     *         means it is NOT always what INITIAL_SYSTEM_ADMIN_EMAIL names, and a
     *         summary line contradicting the warning printed just above it is
     *         worse than no summary line at all.
     */
    public static function seed(Database $db, ?bool $includeDevFixtures = null): string
    {
        $includeDevFixtures ??= self::isDevelopment();

        $pdo    = $db->getPdo();
        $driver = (string) $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);

        // ── Create default tenant ─────────────────────────────────────────────
        $db->query(
            'INSERT INTO tenants (name, created_at) VALUES (:name, NOW()) ON CONFLICT (name) DO NOTHING',
            [':name' => self::DEFAULT_TENANT_NAME]
        );

        // Fetch the tenant ID
        $tenantResult = $db->query(
            'SELECT id FROM tenants WHERE name = :name',
            [':name' => self::DEFAULT_TENANT_NAME]
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

        // ── Reconcile the bootstrap administrator's address (WC-779) ──────────
        // Runs BEFORE any account is looked up by email, so a rename the
        // operator configured after migration 095 already ran (as a no-op) is
        // applied here instead of producing a SECOND bootstrap administrator at
        // the new address alongside the old one.
        $bootstrapEmail = BootstrapIdentity::applyConfiguredEmail($db);

        // ── Seed profile model (ADR 0005, WC-10522424) ────────────────────────
        // Each account gets: profile row + primary verified profile_email +
        // membership in its tenant.  All inserts are idempotent via ON CONFLICT.
        //
        // Passwords are resolved LAZILY, per account, and only when the account
        // is actually being created: hashing up front meant a re-seed of an
        // install with no INITIAL_* variables set announced a freshly generated
        // password for every existing account that it then did not use.

        /** @var list<array{email: string, password_env: string, tenant_id: int, role_id: int}> */
        $accounts = [
            [
                'email'        => $bootstrapEmail,
                'password_env' => 'INITIAL_SYSTEM_ADMIN_PASSWORD',
                'tenant_id'    => 0,
                'role_id'      => $adminRoleId,
            ],
        ];

        if ($includeDevFixtures) {
            $accounts[] = [
                'email'        => 'admin@example.com',
                'password_env' => 'INITIAL_ADMIN_PASSWORD',
                'tenant_id'    => $tenantId,
                'role_id'      => $adminRoleId,
            ];
            $accounts[] = [
                'email'        => 'user@example.com',
                'password_env' => 'INITIAL_USER_PASSWORD',
                'tenant_id'    => $tenantId,
                'role_id'      => $userRoleId,
            ];
            $accounts[] = [
                'email'        => 'superuser@example.com',
                'password_env' => 'INITIAL_SUPERUSER_PASSWORD',
                'tenant_id'    => 0,
                'role_id'      => $adminRoleId,
            ];
        }

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
                // The credential is NOT touched; if the operator configured one
                // that therefore cannot take effect, say so rather than let it
                // sit there looking applied.
                $profileId = (int) $existing['profile_id'];
                self::reportInertPassword($db, $account['password_env'], $normEmail, $profileId);
            } else {
                // ── b. INSERT a profiles row ──────────────────────────────────
                // Use RETURNING id on PostgreSQL; lastInsertId() on SQLite.
                // @tenant-guard-ignore: profiles is a sanctioned GLOBAL table (ADR 0005 §1)
                $profileParams = [
                    ':display_name'                    => self::localPart($normEmail),
                    ':password_hash'                   => InitialPassword::hashFor($account['password_env'], $normEmail),
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
                 ON CONFLICT (profile_id, tenant_id) WHERE is_primary DO NOTHING",
                [
                    ':profile_id' => $profileId,
                    ':tenant_id'  => $account['tenant_id'],
                    ':role_id'    => $account['role_id'],
                ]
            );
        }

        // ── Global default notification templates (WC-notifications #2aa3411a) ──
        // The operator-managed baseline every tenant inherits (idempotent).
        \Whity\Core\Notification\NotificationTemplateSeeder::seed($pdo);

        return $bootstrapEmail;
    }

    /**
     * Say so when a configured INITIAL_*_PASSWORD cannot take effect.
     *
     * The seeder skips an account whose profile_email already exists, which is
     * what makes re-seeding safe — but it also means a password variable the
     * operator sets AFTER the first seed is silently inert: the documented
     * variable is present, the account authenticates with something else, and
     * nothing anywhere says why. That dead end is diagnosable only by hand
     * (WHIT-587 item 5, where superuser@example.com was found authenticating
     * with the value of a DIFFERENT variable).
     *
     * This reports the mismatch and stops there. It deliberately does NOT
     * rewrite the stored hash: a seed run that silently reset a live
     * administrator's credential would be a credential reset wearing a seeder's
     * clothes. Nothing is reported when the variable is unset (nothing was
     * asked for) or when the stored hash already verifies (nothing is wrong).
     */
    private static function reportInertPassword(
        Database $db,
        string $envVar,
        string $email,
        int $profileId
    ): void {
        $configured = InitialPassword::configuredPlaintext($envVar);
        if ($configured === null) {
            return;
        }

        // @tenant-guard-ignore: profiles is a sanctioned GLOBAL table (ADR 0005 §1)
        $row = $db->query(
            'SELECT password_hash, auth_method FROM profiles WHERE id = :id',
            [':id' => $profileId]
        )->fetch(PDO::FETCH_ASSOC);

        if ($row === false) {
            return;
        }

        // #917: an account whose credentials belong to an identity provider will
        // never match ANY configured value, and telling the operator to go and
        // set one through PATCH /api/users/{id} — as the message below used to,
        // unconditionally — is the exact instruction that produced the defect.
        // Name the real reason instead. Reported rather than silently skipped:
        // an operator who has set INITIAL_ADMIN_PASSWORD for an account that
        // signs in through an IdP has a belief about that deployment worth
        // correcting.
        if (((string) ($row['auth_method'] ?? AuthMethod::LOCAL)) === AuthMethod::IDP) {
            $idpMessage = sprintf(
                '[whity] %s signs in through an identity provider and holds no local password, so %s '
                . 'is inert for this account and always will be. Manage its credentials with the provider. '
                . 'A local password can still be set deliberately (PATCH /api/users/{id} with '
                . 'allowLocalPasswordOnIdpAccount), but that gives the account a second way in that the '
                . 'provider does not control.',
                $email,
                $envVar
            );
            echo $idpMessage . "\n";
            error_log($idpMessage);

            return;
        }

        if (password_verify($configured, (string) $row['password_hash'])) {
            return;
        }

        $message = sprintf(
            '[whity] %s already exists and its stored password does not match %s. '
            . 'The seeder never rewrites an existing credential, so that value is inert for this account — '
            . 'change the password through the admin UI (PATCH /api/users/{id}) if you meant it to take effect.',
            $email,
            $envVar
        );

        // Same two channels InitialPassword announces on: stdout for an
        // interactive `seed`, error_log for the container's stderr.
        echo $message . "\n";
        error_log($message);
    }

    /**
     * Whether this is a development install, and therefore may hold the
     * `*@example.com` demo accounts.
     *
     * An unset APP_ENV counts as NOT development — the same fail-closed default
     * every other environment-gated guard in this codebase uses (CookieManager,
     * TotpService, EncryptedSecretStore), and the safe direction here: forget to
     * set it and you get no demo credentials rather than three.
     */
    private static function isDevelopment(): bool
    {
        $appEnv = $_ENV['APP_ENV'] ?? getenv('APP_ENV');

        return is_string($appEnv) && $appEnv === 'development';
    }

    /** Returns the local-part (before @) of an email address for display_name. */
    private static function localPart(string $email): string
    {
        $at = strrpos($email, '@');
        return $at !== false ? substr($email, 0, $at) : $email;
    }
}
