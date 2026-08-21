<?php

declare(strict_types=1);

namespace Whity\Core\Identity;

use PDO;
use Whity\Core\Hooks\HookManager;

/**
 * Issues and consumes tenant invitations (WHIT-417 / #797 item 3) — the path by
 * which a tenant administrator onboards somebody without an operator typing a
 * password into a form.
 *
 * Token handling is {@see PasswordResetService}'s, unchanged:
 * `bin2hex(random_bytes(32))` (256-bit), only the `sha256()` digest persisted,
 * lookup by an indexed equality on that digest under `UNIQUE(token_hash)` — so
 * the raw token is never compared in PHP and there is no comparison to time.
 * Single use is a status-guarded UPDATE whose `rowCount()` decides the winner
 * of a concurrent double-accept.
 *
 * THE CASE THIS EXISTS FOR
 * ------------------------
 * The invitee may already have a profile, in another tenant. `profile_emails.email`
 * is globally UNIQUE (ADR 0005 §2), so accepting resolves through
 * {@see ProfileProvisioner} and produces a MEMBERSHIP, never a second identity —
 * a duplicate profile would split that person's credential and token epoch
 * across two rows, so a password change or a forced logout would reach only one
 * of them. It follows that an existing profile is never asked for a password,
 * and one supplied anyway is ignored rather than applied: joining a workspace is
 * not a credential change.
 *
 * WHAT IS DELIBERATELY NOT OBSERVABLE
 * -----------------------------------
 * Whether an address already has an account is visible ONLY to the holder of a
 * valid token (who has proven control of that mailbox by receiving the mail) —
 * never to the administrator who issued the invitation, and never in a list
 * response. {@see self::listForTenant()} therefore returns no profile id and no
 * account-existence flag, and {@see self::invite()} performs the same work
 * whether or not the address is known: no profile is created at invite time, so
 * there is no bcrypt cost to distinguish the two paths by.
 *
 * `invitations` is TENANT-OWNED — every administrator-facing statement binds a
 * parameterised `tenant_id`. The two token-driven statements cannot: they run on
 * a PUBLIC endpoint where there is no tenant context and the token itself is the
 * authority, so they carry an explicit guard annotation.
 */
final class InvitationService
{
    /**
     * Default invitation lifetime, in days.
     *
     * Far longer than a password reset's hour, because the two are answers to
     * different questions: a reset is a recovery the requester is waiting on,
     * an invitation is an onboarding decision the invitee may not act on until
     * they are back at a desk. Overridable per tenant — see
     * {@see \Whity\Core\Settings\SettingsRegistry::INVITATION_TTL_DAYS}.
     */
    public const DEFAULT_TTL_DAYS = 7;

    /** Bounds an operator-supplied TTL is clamped to. */
    public const MIN_TTL_DAYS = 1;
    public const MAX_TTL_DAYS = 90;

    /** Raw-token entropy in bytes (256-bit → 64 hex chars). */
    private const TOKEN_BYTES = 32;

    /** Row states. `expired` is DERIVED from expires_at, never stored. */
    public const STATUS_PENDING = 'pending';
    public const STATUS_ACCEPTED = 'accepted';
    public const STATUS_REVOKED = 'revoked';
    public const STATUS_SUPERSEDED = 'superseded';

    /** {@see self::invite()} outcomes. */
    public const INVITE_CREATED = 'created';
    public const INVITE_ALREADY_MEMBER = 'already_member';

    /** {@see self::accept()} outcomes. */
    public const ACCEPT_INVALID = 'invalid';
    public const ACCEPT_PASSWORD_REQUIRED = 'password_required';
    public const ACCEPT_JOINED = 'joined';
    public const ACCEPT_ALREADY_MEMBER = 'already_member';
    public const ACCEPT_SUSPENDED = 'suspended';

    /**
     * @param HookManager|null $hooks Optional; announces the membership an
     *        accepted invitation creates or completes (#889). Null-tolerant so
     *        the service stays constructible in tests and CLI contexts, where
     *        no hook manager exists and an invitation must still be acceptable.
     */
    public function __construct(
        private readonly PDO $db,
        private readonly ProfileProvisioner $profiles,
        private readonly ?HookManager $hooks = null,
    ) {}

    /**
     * Invite an address into a tenant, superseding any invitation already
     * outstanding for it there.
     *
     * Refused when the address already holds an ACTIVE membership in this
     * tenant — that is a clear "they are already here", not a duplicate row.
     * A membership left in `invited` or `suspended` does NOT block the
     * invitation: an `invited` row is exactly what accepting completes, and a
     * `suspended` one is refused later by {@see self::accept()}, so a
     * suspension cannot be bypassed by re-inviting.
     *
     * @param string $email Already validated and lowercased by the caller.
     * @return array{result: string, id: int, token: string} The RAW token is
     *         returned once, for the mail, and is never persisted. On refusal
     *         `id` is 0 and `token` is empty: callers branch on `result`, never
     *         on whether a key happens to be there.
     */
    public function invite(
        int $tenantId,
        string $email,
        int $roleId,
        ?int $ouId = null,
        ?int $invitedByProfileId = null,
        int $ttlDays = self::DEFAULT_TTL_DAYS,
    ): array {
        $rawToken = bin2hex(random_bytes(self::TOKEN_BYTES));
        $expiresAt = gmdate('Y-m-d H:i:s', time() + $this->clampTtlDays($ttlDays) * 86400);

        $ownTx = !$this->db->inTransaction();
        if ($ownTx) {
            $this->db->beginTransaction();
        }

        try {
            $profileId = $this->profileIdForEmail($email);
            if ($profileId !== null && $this->membershipStatuses($profileId, $tenantId)['active']) {
                if ($ownTx) {
                    $this->db->commit();
                }

                return ['result' => self::INVITE_ALREADY_MEMBER, 'id' => 0, 'token' => ''];
            }

            $this->supersedeOutstanding($tenantId, $email);

            $this->db->prepare(
                'INSERT INTO invitations
                     (tenant_id, email, role_id, ou_id, token_hash, status, invited_by,
                      expires_at, created_at, updated_at)
                 VALUES (:tenant_id, :email, :role_id, :ou_id, :hash, :status, :invited_by,
                         :expires_at, NOW(), NOW())'
            )->execute([
                ':tenant_id' => $tenantId,
                ':email' => $email,
                ':role_id' => $roleId,
                ':ou_id' => $ouId,
                ':hash' => hash('sha256', $rawToken),
                ':status' => self::STATUS_PENDING,
                ':invited_by' => $invitedByProfileId,
                ':expires_at' => $expiresAt,
            ]);
            $id = (int) $this->db->lastInsertId();

            if ($ownTx) {
                $this->db->commit();
            }

            return ['result' => self::INVITE_CREATED, 'id' => $id, 'token' => $rawToken];
        } catch (\Throwable $e) {
            if ($ownTx && $this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $e;
        }
    }

    /**
     * Re-issue an outstanding invitation: same recipient and role, a NEW token
     * and a fresh expiry, and the previous link dead.
     *
     * Deliberately not "send the same link again". A resend is normally
     * triggered because the first mail went astray, and a link that may already
     * be in an unknown inbox is exactly the one that should stop working.
     *
     * @return array{id: int, email: string, token: string}|null null when the
     *         invitation is not this tenant's, or is no longer pending.
     */
    public function resend(int $invitationId, int $tenantId, int $ttlDays = self::DEFAULT_TTL_DAYS): ?array
    {
        $ownTx = !$this->db->inTransaction();
        if ($ownTx) {
            $this->db->beginTransaction();
        }

        try {
            $stmt = $this->db->prepare(
                'SELECT id, email FROM invitations
                  WHERE id = :id AND tenant_id = :tenant_id AND status = :status
                  LIMIT 1'
            );
            $stmt->execute([':id' => $invitationId, ':tenant_id' => $tenantId, ':status' => self::STATUS_PENDING]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);

            if ($row === false) {
                if ($ownTx) {
                    $this->db->commit();
                }

                return null;
            }

            $rawToken = bin2hex(random_bytes(self::TOKEN_BYTES));
            $update = $this->db->prepare(
                'UPDATE invitations
                    SET token_hash = :hash, expires_at = :expires_at, updated_at = NOW()
                  WHERE id = :id AND tenant_id = :tenant_id AND status = :status'
            );
            $update->execute([
                ':hash' => hash('sha256', $rawToken),
                ':expires_at' => gmdate('Y-m-d H:i:s', time() + $this->clampTtlDays($ttlDays) * 86400),
                ':id' => $invitationId,
                ':tenant_id' => $tenantId,
                ':status' => self::STATUS_PENDING,
            ]);

            if ($update->rowCount() === 0) {
                // Lost a race with a concurrent revoke/accept.
                if ($ownTx) {
                    $this->db->rollBack();
                }

                return null;
            }

            if ($ownTx) {
                $this->db->commit();
            }

            return ['id' => $invitationId, 'email' => (string) $row['email'], 'token' => $rawToken];
        } catch (\Throwable $e) {
            if ($ownTx && $this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $e;
        }
    }

    /**
     * Withdraw an outstanding invitation. The token stops working immediately.
     *
     * @return bool False when the invitation is not this tenant's, or was
     *         already accepted/revoked — a cross-tenant revoke touches no rows.
     */
    public function revoke(int $invitationId, int $tenantId): bool
    {
        $stmt = $this->db->prepare(
            'UPDATE invitations
                SET status = :revoked, revoked_at = NOW(), updated_at = NOW()
              WHERE id = :id AND tenant_id = :tenant_id AND status = :pending'
        );
        $stmt->execute([
            ':revoked' => self::STATUS_REVOKED,
            ':id' => $invitationId,
            ':tenant_id' => $tenantId,
            ':pending' => self::STATUS_PENDING,
        ]);

        return $stmt->rowCount() > 0;
    }

    /**
     * The invitations a tenant administrator can see, newest first.
     *
     * Carries NO profile id and no account-existence flag: a tenant
     * administrator can type any address into the invite form, so echoing back
     * whether it already has an account would make this list an enumeration
     * oracle over the whole platform. A `pending` row past its expiry is
     * reported as `expired` here rather than in every caller.
     *
     * @return list<array{id: int, email: string, role_id: int, role_name: string, ou_id: int|null,
     *                    status: string, expires_at: string, created_at: string, invited_by: int|null}>
     */
    public function listForTenant(int $tenantId): array
    {
        $stmt = $this->db->prepare(
            'SELECT i.id, i.email, i.role_id, i.ou_id, i.status, i.expires_at, i.created_at, i.invited_by,
                    r.name AS role_name
               FROM invitations i
               LEFT JOIN roles r ON r.id = i.role_id AND (r.tenant_id IS NULL OR r.tenant_id = i.tenant_id)
              WHERE i.tenant_id = :tenant_id
              ORDER BY i.created_at DESC, i.id DESC'
        );
        $stmt->execute([':tenant_id' => $tenantId]);

        $now = gmdate('Y-m-d H:i:s');
        $items = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $status = (string) $row['status'];
            $expiresAt = (string) $row['expires_at'];
            if ($status === self::STATUS_PENDING && $expiresAt <= $now) {
                $status = 'expired';
            }

            $items[] = [
                'id' => (int) $row['id'],
                'email' => (string) $row['email'],
                'role_id' => (int) $row['role_id'],
                'role_name' => (string) ($row['role_name'] ?? ''),
                'ou_id' => $row['ou_id'] !== null ? (int) $row['ou_id'] : null,
                'status' => $status,
                'expires_at' => $expiresAt,
                'created_at' => (string) $row['created_at'],
                'invited_by' => $row['invited_by'] !== null ? (int) $row['invited_by'] : null,
            ];
        }

        return $items;
    }

    /**
     * What the invitee is shown before they accept, without consuming anything.
     *
     * `requires_password` is the one fact this reveals that the administrator's
     * list does not: whether the address already has an account. That is safe
     * HERE and only here — the caller holds a valid single-use token, which is
     * the same proof of mailbox control a password-reset link demands.
     *
     * @return array{tenant_id: int, tenant_name: string, email: string, requires_password: bool}|null
     *         null for an unknown, expired, revoked, superseded or already-used
     *         token — the four are deliberately indistinguishable.
     */
    public function preview(string $rawToken): ?array
    {
        $row = $this->findLiveByToken($rawToken);
        if ($row === null) {
            return null;
        }

        return [
            'tenant_id' => (int) $row['tenant_id'],
            'tenant_name' => (string) ($row['tenant_name'] ?? ''),
            'email' => (string) $row['email'],
            'requires_password' => $this->profileIdForEmail((string) $row['email']) === null,
        ];
    }

    /**
     * Consume an invitation token: resolve the address to a profile (creating
     * one only when there is none) and grant the membership it carries.
     *
     * @param string|null $passwordHash An ALREADY-HASHED password, used only
     *        when the address has no profile yet. The service never sees a
     *        plaintext password and never rewrites an existing credential.
     * @return array{result: string, tenant_id: int|null, profile_id: int|null}
     *         `password_required` leaves the invitation untouched so the
     *         invitee can simply try again; every other non-success outcome is
     *         reported as `invalid`.
     */
    public function accept(string $rawToken, ?string $passwordHash): array
    {
        $ownTx = !$this->db->inTransaction();
        if ($ownTx) {
            $this->db->beginTransaction();
        }

        try {
            $invitation = $this->findLiveByToken($rawToken);
            if ($invitation === null) {
                return $this->finish($ownTx, ['result' => self::ACCEPT_INVALID, 'tenant_id' => null, 'profile_id' => null]);
            }

            $tenantId = (int) $invitation['tenant_id'];
            $email = (string) $invitation['email'];
            $profileId = $this->profileIdForEmail($email);

            if ($profileId === null && ($passwordHash === null || $passwordHash === '')) {
                // Nothing has been written yet, so the invitation survives and
                // the invitee can retry with a password.
                return $this->finish($ownTx, [
                    'result' => self::ACCEPT_PASSWORD_REQUIRED,
                    'tenant_id' => $tenantId,
                    'profile_id' => null,
                ]);
            }

            if ($profileId === null) {
                $profileId = $this->profiles->findOrCreate($email, (string) $passwordHash);
            }

            $statuses = $this->membershipStatuses($profileId, $tenantId);

            // Set by whichever branch actually grants access, and dispatched
            // only after the transaction commits (#889).
            $granted = null;

            if ($statuses['active']) {
                // Already here — burn the invitation so the link cannot be
                // replayed, but add nothing.
                $outcome = self::ACCEPT_ALREADY_MEMBER;
            } elseif ($statuses['suspended']) {
                // A suspension is an administrator's decision; an invitation
                // is not a way around it. The invitation stays live so it can
                // be used if the suspension is lifted.
                return $this->finish($ownTx, [
                    'result' => self::ACCEPT_SUSPENDED,
                    'tenant_id' => $tenantId,
                    'profile_id' => $profileId,
                ]);
            } elseif ($statuses['invited']) {
                // Completing a membership somebody already staged through
                // POST /api/users is precisely what accepting means.
                $this->db->prepare(
                    'UPDATE memberships
                        SET status = :active
                      WHERE profile_id = :profile_id AND tenant_id = :tenant_id AND status = :invited'
                )->execute([
                    ':active' => MembershipRepository::STATUS_ACTIVE,
                    ':profile_id' => $profileId,
                    ':tenant_id' => $tenantId,
                    ':invited' => MembershipRepository::STATUS_INVITED,
                ]);
                // The row existed already, so THIS is the moment access begins —
                // a staged `invited` row grants nothing. The trail records the
                // completion, not the staging (#889).
                $granted = $this->membershipGrantPayload($profileId, $tenantId);
                $outcome = self::ACCEPT_JOINED;
            } else {
                $this->db->prepare(
                    'INSERT INTO memberships (profile_id, tenant_id, role_id, ou_id, status, created_at)
                     VALUES (:profile_id, :tenant_id, :role_id, :ou_id, :status, NOW())'
                )->execute([
                    ':profile_id' => $profileId,
                    ':tenant_id' => $tenantId,
                    ':role_id' => (int) $invitation['role_id'],
                    ':ou_id' => $invitation['ou_id'] !== null ? (int) $invitation['ou_id'] : null,
                    ':status' => MembershipRepository::STATUS_ACTIVE,
                ]);
                $granted = $this->membershipGrantPayload($profileId, $tenantId);
                $outcome = self::ACCEPT_JOINED;
            }

            // Single use. The status guard is what makes a concurrent second
            // accept a safe no-op rather than a second membership.
            $burn = $this->db->prepare(
                'UPDATE invitations
                    SET status = :accepted, accepted_at = NOW(), updated_at = NOW()
                  WHERE id = :id AND tenant_id = :tenant_id AND status = :pending'
            );
            $burn->execute([
                ':accepted' => self::STATUS_ACCEPTED,
                ':id' => (int) $invitation['id'],
                ':tenant_id' => $tenantId,
                ':pending' => self::STATUS_PENDING,
            ]);

            if ($burn->rowCount() === 0) {
                if ($ownTx && $this->db->inTransaction()) {
                    $this->db->rollBack();
                }

                return ['result' => self::ACCEPT_INVALID, 'tenant_id' => null, 'profile_id' => null];
            }

            $result = $this->finish($ownTx, [
                'result' => $outcome,
                'tenant_id' => $tenantId,
                'profile_id' => $profileId,
            ]);

            // AFTER the commit, never inside it. An audit row is a claim that
            // something happened; dispatched from inside a transaction that can
            // still roll back — the burn-guard below returns ACCEPT_INVALID on a
            // concurrent double-accept — the trail would assert a grant the
            // database then discarded, and an append-only trail has no way to
            // take it back.
            if ($granted !== null) {
                $this->announce('user.membership.added', $granted);
            }

            return $result;
        } catch (\Throwable $e) {
            if ($ownTx && $this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $e;
        }
    }

    // ── internals ────────────────────────────────────────────────────────────

    /**
     * Commit (when we opened the transaction) and hand the outcome back.
     *
     * @param array{result: string, tenant_id: int|null, profile_id: int|null} $outcome
     * @return array{result: string, tenant_id: int|null, profile_id: int|null}
     */
    private function finish(bool $ownTx, array $outcome): array
    {
        if ($ownTx && $this->db->inTransaction()) {
            $this->db->commit();
        }

        return $outcome;
    }

    /**
     * The live invitation a raw token names, with its tenant's display name.
     *
     * The lookup is an indexed equality on the sha256 digest under
     * `UNIQUE(token_hash)` — the same shape as {@see PasswordResetService::confirm()},
     * so the raw token is never compared in PHP.
     *
     * @return array<string, mixed>|null
     */
    private function findLiveByToken(string $rawToken): ?array
    {
        if ($rawToken === '') {
            return null;
        }

        // @tenant-guard-ignore: reached from the PUBLIC accept endpoint, which has no tenant context by construction — the 256-bit single-use token is the authority, and the row's own tenant_id is what the membership is then created in
        $stmt = $this->db->prepare(
            'SELECT i.id, i.tenant_id, i.email, i.role_id, i.ou_id, t.name AS tenant_name
               FROM invitations i
               LEFT JOIN tenants t ON t.id = i.tenant_id
              WHERE i.token_hash = :hash AND i.status = :pending AND i.expires_at > :now
              LIMIT 1'
        );
        $stmt->execute([
            ':hash' => hash('sha256', $rawToken),
            ':pending' => self::STATUS_PENDING,
            ':now' => gmdate('Y-m-d H:i:s'),
        ]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row !== false ? $row : null;
    }

    /**
     * Retire any invitation still outstanding for this address in this tenant.
     *
     * Marked `superseded` rather than deleted so an administrator can still see
     * that an earlier invitation was sent, and distinguish it from one they
     * revoked on purpose.
     */
    private function supersedeOutstanding(int $tenantId, string $email): void
    {
        $this->db->prepare(
            'UPDATE invitations
                SET status = :superseded, updated_at = NOW()
              WHERE tenant_id = :tenant_id AND email = :email AND status = :pending'
        )->execute([
            ':superseded' => self::STATUS_SUPERSEDED,
            ':tenant_id' => $tenantId,
            ':email' => $email,
            ':pending' => self::STATUS_PENDING,
        ]);
    }

    /** The profile owning an address, or null when the address is unknown. */
    private function profileIdForEmail(string $email): ?int
    {
        // @tenant-guard-ignore: profile_emails is a sanctioned GLOBAL identity table (ADR 0005 §2); UNIQUE(email)
        $stmt = $this->db->prepare('SELECT profile_id FROM profile_emails WHERE email = :email LIMIT 1');
        $stmt->execute([':email' => $email]);
        $profileId = $stmt->fetchColumn();

        return $profileId !== false ? (int) $profileId : null;
    }

    /**
     * Which membership states a profile holds in a tenant.
     *
     * All of them, rather than "the" membership row: since migration 094 a
     * profile may hold more than one membership per tenant, so a `LIMIT 1`
     * would answer differently between runs.
     *
     * @return array{active: bool, invited: bool, suspended: bool}
     */
    private function membershipStatuses(int $profileId, int $tenantId): array
    {
        $stmt = $this->db->prepare(
            'SELECT status FROM memberships WHERE profile_id = :profile_id AND tenant_id = :tenant_id'
        );
        $stmt->execute([':profile_id' => $profileId, ':tenant_id' => $tenantId]);
        $statuses = array_map('strval', $stmt->fetchAll(PDO::FETCH_COLUMN));

        return [
            'active' => in_array(MembershipRepository::STATUS_ACTIVE, $statuses, true),
            'invited' => in_array(MembershipRepository::STATUS_INVITED, $statuses, true),
            'suspended' => in_array(MembershipRepository::STATUS_SUSPENDED, $statuses, true),
        ];
    }

    /** Keep an operator-supplied lifetime inside something defensible. */
    private function clampTtlDays(int $ttlDays): int
    {
        return max(self::MIN_TTL_DAYS, min(self::MAX_TTL_DAYS, $ttlDays));
    }

    /**
     * The `user.membership.added` payload for the ACTIVE membership an accepted
     * invitation just produced (#889).
     *
     * Read back from the table rather than assembled from the invitation, and
     * deliberately so: the completion branch updates a row that was staged
     * earlier, possibly with a different role than this invitation names, and
     * the trail must record the authority the person actually ended up with —
     * not the one the paperwork asked for.
     *
     * The role NAME is captured beside the id because `memberships.role_id` is
     * `ON DELETE CASCADE`: deleting a role removes every membership holding it
     * with no per-row signal, after which a bare id is a pointer into a table
     * that no longer has the row.
     *
     * @return array<string, mixed>|null Null when the row cannot be re-read, in
     *         which case nothing is announced — a payload that had to guess at
     *         what was granted is worse than a known silence.
     */
    private function membershipGrantPayload(int $profileId, int $tenantId): ?array
    {
        $stmt = $this->db->prepare(
            'SELECT m.id, m.role_id, m.ou_id, m.status, r.name AS role_name
               FROM memberships m
               LEFT JOIN roles r ON r.id = m.role_id
              WHERE m.profile_id = :profile_id AND m.tenant_id = :tenant_id
                AND m.status = :active
              LIMIT 1'
        );
        $stmt->execute([
            ':profile_id' => $profileId,
            ':tenant_id' => $tenantId,
            ':active' => MembershipRepository::STATUS_ACTIVE,
        ]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row)) {
            return null;
        }

        return [
            'profile_id'    => $profileId,
            'tenant_id'     => $tenantId,
            'membership_id' => (int) $row['id'],
            'role_id'       => isset($row['role_id']) ? (int) $row['role_id'] : null,
            'role_name'     => isset($row['role_name']) ? (string) $row['role_name'] : null,
            'ou_id'         => isset($row['ou_id']) ? (int) $row['ou_id'] : null,
            'status'        => isset($row['status']) ? (string) $row['status'] : null,
            'via'           => 'invitation',
        ];
    }

    /**
     * Dispatch a membership lifecycle event when a hook manager is wired.
     *
     * Fail-soft: accepting an invitation must not fail because a listener threw.
     * The audit writer swallows its own errors already, but a PLUGIN listening
     * on the same event makes no such promise, and a person who clicked a valid
     * invitation link must not be left outside the tenant by someone else's bug.
     *
     * @param array<string, mixed> $payload
     */
    private function announce(string $event, array $payload): void
    {
        if ($this->hooks === null) {
            return;
        }

        try {
            $this->hooks->dispatch($event, $payload);
        } catch (\Throwable) {
            // Intentionally swallowed; see the method docblock.
        }
    }
}
