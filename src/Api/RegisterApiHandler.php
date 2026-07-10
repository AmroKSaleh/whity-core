<?php

declare(strict_types=1);

namespace Whity\Api;

use PDO;
use Whity\Core\Identity\AccountActivationPolicy;
use Whity\Core\Identity\EmailVerificationPolicy;
use Whity\Core\Identity\EmailVerificationProvider;
use Whity\Core\Hooks\HookManager;
use Whity\Core\Identity\MembershipRepository;
use Whity\Core\Identity\NullEmailVerificationProvider;
use Whity\Core\PasswordPolicy;
use Whity\Core\Request;
use Whity\Core\Response;
use Whity\Core\Settings\SettingsRegistry;
use Whity\Core\Settings\SettingsService;
use Whity\Http\JsonBody;

/**
 * Public self-service registration (WC-235).
 *
 * Provisions a NEW tenant with the registrant as its owner. In one transaction
 * it creates: a `tenants` row, a global `profiles` row (+ a primary verified
 * `profile_emails` row), and a `memberships` row binding the profile to the new
 * tenant with the base `admin` role (ADR 0005 — identity is profiles +
 * profile_emails + memberships; there is no legacy `users` row). The membership
 * is 'active' by default; when {@see AccountActivationPolicy} is enforced it is
 * 'invited' (pending) and the owner cannot log in until a system-tenant admin
 * approves it.
 *
 * PUBLIC + UNAUTHENTICATED: registered with NO required permission and covered
 * by the global rate-limiter (a public tenant-creating endpoint is an abuse
 * vector — rate limiting, input validation, and the unique-email/name/slug
 * guards + a single all-or-nothing transaction are the safeguards). It does NOT
 * mint a session; on 201 the client logs in via `POST /api/login` with the same
 * credentials. Error bodies are generic; details are logged server-side only.
 *
 * NOTE: this deliberately bypasses the system-admin gate that guards the admin
 * `POST /api/tenants` path — self-service signup is precisely the sanctioned way
 * an unauthenticated caller provisions their own tenant.
 */
final class RegisterApiHandler
{
    /** The base (global, NULL-tenant) role the workspace owner is granted. */
    private const OWNER_ROLE = 'admin';

    private PDO $db;
    private SettingsService $settings;
    private EmailVerificationProvider $verificationProvider;
    private ?HookManager $hooks;

    public function __construct(
        PDO $db,
        SettingsService $settings,
        ?EmailVerificationProvider $verificationProvider = null,
        ?HookManager $hooks = null,
    ) {
        $this->db = $db;
        // Instance-governance flags (self-registration open? approval required?)
        // are read fresh per request from the GLOBAL settings layer.
        $this->settings = $settings;
        // Default to the MVP no-op provider; a real one is bound in the
        // composition root when EMAIL_VERIFICATION_ENFORCED is turned on.
        $this->verificationProvider = $verificationProvider ?? new NullEmailVerificationProvider();
        // Optional: dispatches the `registration.completed` lifecycle hook (drives
        // the welcome email). Null in tests that don't exercise notifications.
        $this->hooks = $hooks;
    }

    public function register(Request $request): Response
    {
        try {
            // Instance governance (WC-696206d8): self-service signup must be
            // enabled by the operator. A fresh, sovereign instance is CLOSED by
            // default (the operator opens signup via global instance settings), so
            // a public caller cannot self-provision a tenant + account. Read fresh
            // per request and fail closed with a generic 403 (no enumeration).
            $global = $this->settings->getGlobal();
            if (($global[SettingsRegistry::SELF_REGISTRATION_ENABLED] ?? 'false') !== 'true') {
                return Response::error('Self-service registration is disabled', 403);
            }

            $body = JsonBody::parsed($request);

            $email       = strtolower(trim((string) ($body['email'] ?? '')));
            $password    = (string) ($body['password'] ?? '');
            $tenantName  = trim((string) ($body['tenant_name'] ?? $body['tenantName'] ?? ''));
            $displayName = trim((string) ($body['display_name'] ?? $body['displayName'] ?? ''));

            // ── Validate inputs (422 with a generic, safe message) ──────────────
            if ($email === '' || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
                return Response::error('A valid email address is required', 422);
            }
            if ($tenantName === '') {
                return Response::error('A workspace name is required', 422);
            }
            // Cap inputs to the backing VARCHAR(255) columns: an over-long value
            // would otherwise surface as a Postgres 22001 → generic 500 (and would
            // diverge from the length-less SQLite test shim). FILTER_VALIDATE_EMAIL
            // imposes no length bound of its own.
            if (strlen($email) > 255 || strlen($tenantName) > 255 || strlen($displayName) > 255) {
                return Response::error('Email, workspace name, and display name must each be 255 characters or fewer', 422);
            }
            try {
                PasswordPolicy::validate($password);
            } catch (\InvalidArgumentException) {
                // Return a controlled message built from the policy constant — never
                // the raw exception text (WC-186: no handler may leak $e->getMessage()
                // into a client response).
                return Response::error(
                    'Password must be at least ' . PasswordPolicy::MIN_LENGTH . ' characters',
                    422
                );
            }
            $slug = self::slugify($tenantName);
            if ($slug === '') {
                return Response::error('Workspace name must contain letters or numbers', 422);
            }
            if ($displayName === '') {
                $displayName = self::localPart($email);
            }

            // Email-verification enforcement (WC-235): default OFF (MVP). When ON,
            // the primary email starts unverified and we hand off to the provider
            // after commit; when OFF, the email is self-attested verified and
            // nothing is sent. Flipping the flag needs no change to this handler.
            $enforced = EmailVerificationPolicy::isEnforced();

            // Admin-approval enforcement (WC-235 + WC-696206d8): when ON, the owner
            // membership is provisioned as 'invited' (pending) instead of 'active',
            // so the owner CANNOT log in until a system-tenant admin approves it.
            // Required by DEFAULT via the global instance setting (secure default);
            // the legacy ADMIN_APPROVAL_ENFORCED env flag still forces it on too.
            $approvalRequired =
                (($global[SettingsRegistry::REGISTRATION_APPROVAL_REQUIRED] ?? 'true') === 'true')
                || AccountActivationPolicy::isEnforced();
            $membershipStatus = $approvalRequired
                ? MembershipRepository::STATUS_INVITED
                : MembershipRepository::STATUS_ACTIVE;

            // ── Resolve the base owner role; fail closed if the platform has not
            //    been seeded (a workspace owner cannot exist without it). ────────
            // @tenant-guard-ignore: base roles are global (NULL tenant_id); looked up by unique name
            $roleStmt = $this->db->prepare(
                'SELECT id FROM roles WHERE name = :name AND tenant_id IS NULL LIMIT 1'
            );
            $roleStmt->execute([':name' => self::OWNER_ROLE]);
            $roleId = $roleStmt->fetchColumn();
            if ($roleId === false) {
                error_log('[register] base "' . self::OWNER_ROLE . '" role missing — platform not seeded');
                return Response::error('Registration is temporarily unavailable', 503);
            }
            $roleId = (int) $roleId;

            $ownTx = !$this->db->inTransaction();
            if ($ownTx) {
                $this->db->beginTransaction();
            }

            try {
                // Reject an already-registered email (profile_emails.email is globally unique).
                // @tenant-guard-ignore: profile_emails is a sanctioned GLOBAL table (ADR 0005 §2)
                $emailStmt = $this->db->prepare('SELECT 1 FROM profile_emails WHERE email = :email LIMIT 1');
                $emailStmt->execute([':email' => $email]);
                if ($emailStmt->fetchColumn() !== false) {
                    if ($ownTx && $this->db->inTransaction()) {
                        $this->db->rollBack();
                    }
                    return Response::error('An account with this email already exists', 409);
                }

                // Reject a taken workspace name or slug.
                // @tenant-guard-ignore: registration provisions a NEW tenant for an unauthenticated caller; the tenants table is the tenant registry itself, not a tenant-scoped resource
                $tenantStmt = $this->db->prepare('SELECT 1 FROM tenants WHERE name = :name OR slug = :slug LIMIT 1');
                $tenantStmt->execute([':name' => $tenantName, ':slug' => $slug]);
                if ($tenantStmt->fetchColumn() !== false) {
                    if ($ownTx && $this->db->inTransaction()) {
                        $this->db->rollBack();
                    }
                    return Response::error('That workspace name is already taken', 409);
                }

                // 1. New tenant.
                // @tenant-guard-ignore: registration provisions the tenant itself (the tenant registry, not a tenant-scoped row)
                $tenantId = $this->insertReturningId(
                    'INSERT INTO tenants (name, slug, created_at) VALUES (:name, :slug, NOW())',
                    [':name' => $tenantName, ':slug' => $slug]
                );

                // 2. Global profile (bcrypt password; 2FA off; token epoch 0).
                // @tenant-guard-ignore: profiles is a sanctioned GLOBAL table (ADR 0005 §1)
                $profileId = $this->insertReturningId(
                    'INSERT INTO profiles
                         (display_name, password_hash, two_factor_enabled,
                          two_factor_secret, two_factor_backup_codes_version, token_epoch,
                          created_at, updated_at)
                     VALUES (:display_name, :password_hash, false, NULL, 0, 0, NOW(), NOW())',
                    [
                        ':display_name'  => $displayName,
                        ':password_hash' => password_hash($password, PASSWORD_BCRYPT),
                    ]
                );

                // 3. Primary email. When verification is enforced the address
                // starts UNVERIFIED (the provider drives the confirm round-trip);
                // otherwise it is self-attested verified at signup (MVP default).
                // The literal is a controlled boolean (never user input) and is
                // portable across Postgres + the SQLite test shim.
                $verifiedLiteral = $enforced ? 'false' : 'true';
                // @tenant-guard-ignore: profile_emails is a sanctioned GLOBAL table (ADR 0005 §2)
                $this->db->prepare(
                    "INSERT INTO profile_emails (profile_id, email, verified, is_primary, created_at)
                     VALUES (:profile_id, :email, {$verifiedLiteral}, true, NOW())"
                )->execute([':profile_id' => $profileId, ':email' => $email]);

                // 4. Owner membership binding the profile to the new tenant. Its
                // status is 'active' (owner can log in immediately) unless admin
                // approval is enforced, in which case it is 'invited' (pending)
                // and login is refused until a system-tenant admin approves it.
                // The value is a controlled enum constant, never user input.
                $this->db->prepare(
                    "INSERT INTO memberships (profile_id, tenant_id, role_id, ou_id, status, created_at)
                     VALUES (:profile_id, :tenant_id, :role_id, NULL, :status, NOW())"
                )->execute([
                    ':profile_id' => $profileId,
                    ':tenant_id'  => $tenantId,
                    ':role_id'    => $roleId,
                    ':status'     => $membershipStatus,
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

            // Hand off to the verification provider AFTER commit (never inside the
            // transaction), and only when enforced. A delivery failure must not
            // undo the already-created account — log it and still return 201; the
            // owner can re-request verification. (The MVP provider is a no-op.)
            if ($enforced) {
                try {
                    $this->verificationProvider->sendVerification($profileId, $email);
                } catch (\Throwable $e) {
                    error_log('[register] verification dispatch failed for profile ' . $profileId . ': ' . $e->getMessage());
                }
            }

            // Lifecycle hook (drives the welcome email + any plugin reaction).
            // Best-effort and post-commit: a listener failure must never undo the
            // created account. The subscriber decides whether to send (e.g. it
            // holds the welcome when approval is still pending).
            if ($this->hooks !== null) {
                try {
                    $this->hooks->dispatch('registration.completed', [
                        'email'                 => $email,
                        'name'                  => $displayName,
                        'tenant_name'           => $tenantName,
                        'approval_required'     => $approvalRequired,
                        'verification_required' => $enforced,
                    ]);
                } catch (\Throwable $e) {
                    error_log('[register] registration.completed hook failed: ' . $e->getMessage());
                }
            }

            return Response::json([
                'data' => [
                    'profile_id'            => $profileId,
                    'tenant_id'             => $tenantId,
                    'email'                 => $email,
                    'verification_required' => $enforced,
                    // When true the owner cannot log in until a system-tenant
                    // admin approves the pending registration (WC-235). The
                    // client shows a "pending approval" message instead of
                    // chaining a login it knows will be refused.
                    'approval_required'     => $approvalRequired,
                ],
            ], 201);
        } catch (\Throwable $e) {
            error_log('[register] ' . $e->getMessage());
            return Response::error('Registration failed', 500);
        }
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

    /** Lowercase, hyphenate, and trim a workspace name into a URL-safe slug. */
    private static function slugify(string $name): string
    {
        $slug = strtolower(trim($name));
        $slug = (string) preg_replace('/[^a-z0-9]+/', '-', $slug);
        return trim($slug, '-');
    }

    private static function localPart(string $email): string
    {
        $at = strpos($email, '@');
        return $at !== false ? substr($email, 0, $at) : $email;
    }
}
