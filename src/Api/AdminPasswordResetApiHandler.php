<?php

declare(strict_types=1);

namespace Whity\Api;

use PDO;
use Whity\Auth\RoleChecker;
use Whity\Core\Audit\AuditLogger;
use Whity\Core\Identity\AuthMethod;
use Whity\Core\Identity\PasswordResetMailer;
use Whity\Core\Identity\PasswordResetService;
use Whity\Core\Identity\ProfileEmailRepository;
use Whity\Core\RBAC\CorePermissions;
use Whity\Core\Request;
use Whity\Core\Response;
use Whity\Core\Settings\SettingsRegistry;
use Whity\Core\Settings\SettingsService;
use Whity\Core\Tenant\TenantContext;

/**
 * The administrator-facing half of the password-reset domain (WC-797):
 *   POST /api/v1/users/{id}/password-reset            — send this user a reset link
 *   GET  /api/v1/password-resets/approver-coverage    — can this tenant still approve?
 *
 * Why a LINK and not an admin-typed password
 * ------------------------------------------
 * `PATCH /api/users/{id}` accepts a `password` and always will, for scripted
 * provisioning. It is the wrong thing to put a button on. A reset LINK goes
 * through {@see PasswordResetService}, which already invalidates every existing
 * session on the credential change, already rate-limits, already audits, and
 * never puts a plaintext password in an administrator's hands or into whatever
 * support channel they would otherwise read it out over. An admin-set-password
 * control would have to reproduce all four in a second place, and a second
 * implementation of a security property is one that drifts from the first.
 *
 * So this handler mints nothing itself: it resolves the target, then calls
 * {@see PasswordResetService::issue()} and {@see PasswordResetMailer} — the same
 * two the self-service path uses.
 *
 * Tenant scoping mirrors {@see UsersApiHandler} exactly: a tenant may target
 * only a profile holding a membership in its own tenant (404 otherwise, so
 * tenant membership never leaks), and the SYSTEM tenant (id 0) may target any
 * tenant's profile.
 */
final class AdminPasswordResetApiHandler
{
    /** The reserved identifier for the system (cross-tenant authority) tenant. */
    private const SYSTEM_TENANT_ID = 0;

    /**
     * Two approvers is the smallest roster that survives losing one of them —
     * below it, the account that must approve a reset can be the account whose
     * reset is parked, and the tenant has no exit that is not an operator
     * writing SQL.
     */
    private const MINIMUM_APPROVERS = 2;

    public function __construct(
        private readonly PDO $db,
        private readonly PasswordResetService $service,
        private readonly PasswordResetMailer $mailer,
        private readonly ProfileEmailRepository $emails,
        private readonly AuditLogger $audit,
        private readonly SettingsService $settings,
        private readonly RoleChecker $roleChecker,
    ) {}

    /**
     * POST /api/v1/users/{id}/password-reset — mail the target profile a
     * password-reset link.
     *
     * Deliberately NOT gated on `auth.self_password_reset_enabled`: that flag
     * governs whether the PUBLIC forgot-password endpoint is open, and an
     * operator who closed it may still want administrators to be able to start
     * a reset. The confirm endpoint does not consult it either, so a link sent
     * from here is redeemable exactly as it should be.
     *
     * It IS gated on `mail.events.password_reset_enabled`, and loudly. With
     * that event off {@see PasswordResetMailer} silently sends nothing, and an
     * administrator who pressed a button and saw success would believe they had
     * started a recovery that never left the building — the same false belief
     * about a security action that WC-797 §4b is about on the user's side.
     *
     * @param array<string, mixed> $params Route params (expects `id` = profile_id).
     */
    public function sendResetLink(Request $request, array $params = []): Response
    {
        $profileId = (int) ($params['id'] ?? 0);
        if ($profileId <= 0) {
            return Response::error('A valid user id is required', 422);
        }

        $tenantId = TenantContext::getTenantId();
        if ($tenantId === null) {
            return Response::error('Tenant context required', 400);
        }

        try {
            if (!$this->targetIsVisibleTo($profileId, $tenantId)) {
                return Response::error('User not found', 404);
            }

            $primary = $this->emails->findPrimaryForProfile($profileId);
            $email = (string) ($primary['email'] ?? '');
            if ($email === '') {
                return Response::error('This user has no email address to send a reset link to', 422);
            }

            // Checked BEFORE issuing: a token minted for a mail that can never
            // be delivered is a live credential-recovery secret with no owner.
            if (!$this->resetMailEnabled()) {
                return Response::error(
                    'Password-reset emails are disabled for this instance. Enable the password-reset mail event before sending a reset link.',
                    409
                );
            }

            // #917: this account signs in through an identity provider and holds
            // no local password, so a reset link would not restore access — it
            // would CREATE a local credential that outlives the provider's
            // control of the account. PasswordResetService::issue() refuses it
            // regardless; the check is repeated here so the administrator gets
            // this sentence instead of the generic 500 the catch below produces,
            // and is told where the deliberate version of this lives.
            if ((new AuthMethod($this->db))->refusesLocalPassword($profileId)) {
                return Response::error(
                    'This account signs in through an identity provider and has no local password to reset. '
                    . 'Recover access through the provider. If a local password is genuinely intended, set one '
                    . 'explicitly with PATCH /api/users/{id} and allowLocalPasswordOnIdpAccount.',
                    409
                );
            }

            $token = $this->service->issue($profileId);
            $this->mailer->sendResetLink($email, $token);

            $this->audit->record('auth.password_reset.admin_requested', [
                'tenant_id'     => $tenantId,
                'actor_user_id' => $this->actorProfileId($request),
                'target_type'   => 'profile',
                'target_id'     => $profileId,
            ]);

            // The raw token lives only in the mail. Nothing about it — not the
            // token, not a password, not even whether one was already
            // outstanding — comes back to the administrator.
            return Response::json(['data' => ['status' => 'sent', 'profile_id' => $profileId]], 202);
        } catch (\Throwable $e) {
            error_log('[admin-password-reset] send failed: ' . $e->getMessage());

            return Response::error('Failed to send the password-reset link', 500);
        }
    }

    /**
     * GET /api/v1/password-resets/approver-coverage — how many accounts in this
     * tenant can approve a parked password reset (WC-797 §4a).
     *
     * Answers the one question that makes the approval gate safe to operate:
     * turning it on, removing an account, or moving one to a role without
     * `password_resets:approve` can each leave a tenant where the only person
     * who could approve a reset is the person whose reset is parked. The UI
     * warns off this data; nothing here blocks anything, because the operator
     * may have a reason and a hard refusal on a settings toggle is its own
     * trap.
     *
     * Counted through ROLES rather than per-profile permission resolution:
     * distinct membership roles in a tenant are a handful, its members are not,
     * and this is an advisory endpoint an admin screen calls on mount. The
     * consequence is that a grant reaching a profile by OU inheritance or by
     * delegation is not counted — which can only UNDER-count approvers and so
     * only ever over-warns. A warning that fires when it needn't is a nuisance;
     * one that stays silent when it should fire is the failure this is for.
     */
    public function approverCoverage(Request $request): Response
    {
        $tenantId = TenantContext::getTenantId();
        if ($tenantId === null) {
            return Response::error('Tenant context required', 400);
        }

        try {
            [$approvers, $roleNames] = $this->approversIn($tenantId);

            return Response::json(['data' => [
                'tenant_id'            => $tenantId,
                'minimum_recommended'  => self::MINIMUM_APPROVERS,
                'approval_required'    => $this->approvalRequired(),
                'approver_count'       => count($approvers),
                'approver_profile_ids' => $approvers,
                'approver_role_names'  => $roleNames,
                'below_minimum'        => count($approvers) < self::MINIMUM_APPROVERS,
            ]], 200);
        } catch (\Throwable $e) {
            error_log('[admin-password-reset] approver coverage failed: ' . $e->getMessage());

            return Response::error('Failed to resolve password-reset approver coverage', 500);
        }
    }

    /**
     * The approvers of $tenantId: profiles with an ACTIVE membership whose
     * membership role grants `password_resets:approve`, plus the names of the
     * roles that carry the grant.
     *
     * The role names are what let the caller tell a genuine demotion from a
     * sideways move between two approving roles — without them the edit screen
     * would have to warn on every role change to an approver, which trains
     * people to click through the warning that matters.
     *
     * A profile may hold several memberships in one tenant (migration 094), so
     * every active row contributes and both lists are de-duplicated.
     *
     * @return array{list<int>, list<string>}
     */
    private function approversIn(int $tenantId): array
    {
        $stmt = $this->db->prepare(
            "SELECT m.profile_id, m.role_id, r.name AS role_name
               FROM memberships m
               LEFT JOIN roles r ON r.id = m.role_id
              WHERE m.tenant_id = :tenant_id AND m.status = 'active'"
        );
        $stmt->execute([':tenant_id' => $tenantId]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        /** @var array<int, bool> $roleGrants */
        $roleGrants = [];
        /** @var array<int, true> $approvers */
        $approvers = [];
        /** @var array<string, true> $roleNames */
        $roleNames = [];

        foreach ($rows as $row) {
            $roleId = (int) $row['role_id'];
            if (!isset($roleGrants[$roleId])) {
                $roleGrants[$roleId] = in_array(
                    CorePermissions::PASSWORD_RESETS_APPROVE,
                    $this->roleChecker->getEffectivePermissionsForRole($roleId, $tenantId),
                    true
                );
            }

            if ($roleGrants[$roleId]) {
                $approvers[(int) $row['profile_id']] = true;
                $name = (string) ($row['role_name'] ?? '');
                if ($name !== '') {
                    $roleNames[$name] = true;
                }
            }
        }

        $ids = array_keys($approvers);
        sort($ids);
        $names = array_keys($roleNames);
        sort($names);

        return [$ids, $names];
    }

    /**
     * A profile is targetable when it holds a membership in the acting tenant;
     * the SYSTEM tenant reaches every tenant's profiles, exactly as
     * {@see UsersApiHandler} does. Membership STATUS is not filtered: an
     * administrator recovering a suspended or half-provisioned account is a
     * normal thing to do, and hiding it here would send them back to the
     * database.
     */
    private function targetIsVisibleTo(int $profileId, int $tenantId): bool
    {
        if ($tenantId === self::SYSTEM_TENANT_ID) {
            // @tenant-guard-ignore: system-tenant (id 0) may target a profile in any tenant; the scoped else-branch binds tenant_id = :tenant_id
            $stmt = $this->db->prepare('SELECT 1 FROM memberships WHERE profile_id = :pid LIMIT 1');
            $stmt->execute([':pid' => $profileId]);
        } else {
            $stmt = $this->db->prepare(
                'SELECT 1 FROM memberships WHERE profile_id = :pid AND tenant_id = :tenant_id LIMIT 1'
            );
            $stmt->execute([':pid' => $profileId, ':tenant_id' => $tenantId]);
        }

        return $stmt->fetchColumn() !== false;
    }

    private function actorProfileId(Request $request): ?int
    {
        $actor = $request->user;

        return is_object($actor) && isset($actor->profile_id) && is_int($actor->profile_id)
            ? $actor->profile_id
            : null;
    }

    private function resetMailEnabled(): bool
    {
        try {
            $global = $this->settings->getGlobal();
        } catch (\Throwable) {
            return false;
        }

        return (string) ($global[SettingsRegistry::MAIL_EVENT_PASSWORD_RESET] ?? 'false') === 'true';
    }

    private function approvalRequired(): bool
    {
        try {
            $global = $this->settings->getGlobal();
        } catch (\Throwable) {
            // Fail CLOSED, like PasswordResetHandler: reporting the gate as off
            // when it might be on would suppress the very warning §4a is for.
            return true;
        }

        return (string) ($global[SettingsRegistry::PASSWORD_RESET_APPROVAL_REQUIRED] ?? 'false') === 'true';
    }
}
