<?php

declare(strict_types=1);

namespace Whity\Api;

use Whity\Core\Audit\AuditLogger;
use Whity\Core\Identity\LocalPasswordRefusedException;
use Whity\Core\Identity\PasswordResetMailer;
use Whity\Core\Identity\PasswordResetService;
use Whity\Core\Identity\ProfileEmailRepository;
use Whity\Core\PasswordPolicy;
use Whity\Core\RateLimit\ClientIp;
use Whity\Core\Request;
use Whity\Core\Response;
use Whity\Core\Settings\SettingsRegistry;
use Whity\Core\Settings\SettingsService;
use Whity\Core\Store\SharedStoreInterface;
use Whity\Http\JsonBody;

/**
 * Public self-service "forgot password" endpoints
 * (WC-password-reset-2fa-recovery):
 *   POST /api/v1/auth/password/forgot — (re)issue a reset link
 *   POST /api/v1/auth/password/reset  — confirm a token + set a new password
 *
 * Both are PUBLIC + UNAUTHENTICATED by design: a locked-out user has no
 * session. They resolve no tenant, so they sit on the tenant-isolation public-
 * route allowlist; the token lifecycle and rate-limiting are the safeguards.
 * Mirrors {@see EmailVerificationHandler} almost exactly.
 *
 * NO ENUMERATION: `forgot` returns the SAME 202 whether or not the address
 * exists AND regardless of whether self-service reset is even enabled
 * (`auth.self_password_reset_enabled`) — none of that is ever observable from
 * the response. `reset` returns a generic 400 for any bad/expired/replayed
 * token.
 *
 * Audited as system-level (tenant 0) identity events — a password credential
 * belongs to a person, not a per-tenant resource.
 */
final class PasswordResetHandler
{
    private const SYSTEM_TENANT_ID = 0;

    /** Resend throttle: fixed window + per-email and per-IP ceilings. */
    private const WINDOW_SECONDS = 3600;
    private const EMAIL_MAX      = 5;
    private const IP_MAX         = 20;

    public function __construct(
        private readonly PasswordResetService $service,
        private readonly ProfileEmailRepository $emails,
        private readonly PasswordResetMailer $mailer,
        private readonly SharedStoreInterface $store,
        private readonly AuditLogger $audit,
        private readonly SettingsService $settings,
    ) {}

    /**
     * POST /api/v1/auth/password/forgot — (re)issue a password-reset link.
     *
     * Rate-limited per-email and per-IP. Always answers 202 with a generic
     * message. Dispatch happens only for a known address AND when
     * `auth.self_password_reset_enabled` is on; either way the response never
     * varies.
     *
     * When the address already has a reset PARKED for administrator approval,
     * the dispatch becomes the idempotent branch in
     * {@see self::notifyAwaitingApproval()} instead of minting a fresh link —
     * still behind the same unchanged 202.
     */
    public function forgot(Request $request): Response
    {
        $body  = JsonBody::parsed($request);
        $email = strtolower(trim((string) ($body['email'] ?? '')));

        if ($email === '' || filter_var($email, FILTER_VALIDATE_EMAIL) === false || strlen($email) > 255) {
            return Response::error('A valid email address is required', 422);
        }

        $ip       = ClientIp::fromRequest($request);
        $emailKey = 'pwreset:req:email:' . hash('sha256', $email);
        $ipKey    = $ip !== null ? 'pwreset:req:ip:' . $ip : null;

        if ($this->store->count($emailKey) >= self::EMAIL_MAX
            || ($ipKey !== null && $this->store->count($ipKey) >= self::IP_MAX)
        ) {
            $retryAfter = max(
                $this->store->ttl($emailKey),
                $ipKey !== null ? $this->store->ttl($ipKey) : 0,
                1
            );

            return Response::error(
                'Too many password-reset requests. Please try again later.',
                429
            )->withHeaders(['Retry-After' => (string) $retryAfter]);
        }

        // Count this attempt against both windows before doing any work — same
        // ordering as EmailVerificationHandler, so the throttle behaves
        // identically whether the flag below is on or off (no enumeration of
        // whether self-service reset is even enabled). This ordering is
        // load-bearing: anything that decides whether to count AFTER looking the
        // address up turns the 429 boundary into an existence oracle.
        $emailCount = $this->store->increment($emailKey, self::WINDOW_SECONDS);
        if ($ipKey !== null) {
            $this->store->increment($ipKey, self::WINDOW_SECONDS);
        }

        if ($this->selfServiceEnabled()) {
            $row = $this->emails->findByEmail($email);
            if ($row !== null) {
                try {
                    $profileId = (int) $row['profile_id'];

                    if ($this->service->findPendingApprovalForProfile($profileId) !== null) {
                        $this->notifyAwaitingApproval($email, $profileId, $ip, $emailKey, $emailCount);
                    } else {
                        $token = $this->service->issue($profileId);
                        $this->mailer->sendResetLink($email, $token);
                        $this->audit->record('auth.password_reset.requested', [
                            'tenant_id'   => self::SYSTEM_TENANT_ID,
                            'target_type' => 'profile',
                            'target_id'   => $profileId,
                            'ip_address'  => $ip,
                        ]);
                    }
                } catch (LocalPasswordRefusedException $e) {
                    // #916: the address belongs to an IdP-backed account, which
                    // holds no local password to reset. Caught separately from
                    // the failure below only so the log says what happened —
                    // the RESPONSE is deliberately identical to every other
                    // outcome here, including an unknown address, because a
                    // different one would turn this endpoint into an oracle for
                    // which of an organisation's accounts are federated.
                    error_log('[password-reset] forgot refused (IdP-backed account): ' . $e->getMessage());
                } catch (\Throwable $e) {
                    // Delivery/issuance failure must not change the response shape.
                    error_log('[password-reset] forgot dispatch failed: ' . $e->getMessage());
                }
            }
        }

        return Response::json([
            'data' => ['message' => 'If that address has an account, a password-reset link has been sent.'],
        ], 202);
    }

    /**
     * POST /api/v1/auth/password/reset — consume a reset token + set a new
     * password.
     *
     * Generic 400 for any unknown/expired/replayed token. On success, 200 with
     * either an "applied" or "submitted for approval" message depending on the
     * operator's `auth.password_reset_approval_required` setting — this
     * distinction is safe to reveal here (unlike `forgot`) because the caller
     * has already proven ownership of the account via a valid, single-use
     * token.
     */
    public function reset(Request $request): Response
    {
        $body     = JsonBody::parsed($request);
        $token    = (string) ($body['token'] ?? '');
        $password = (string) ($body['password'] ?? '');

        if (trim($token) === '') {
            return Response::error('A reset token is required', 422);
        }

        try {
            PasswordPolicy::validate($password);
        } catch (\InvalidArgumentException) {
            return Response::error(
                'Password must be at least ' . PasswordPolicy::MIN_LENGTH . ' characters',
                422
            );
        }

        $requireApproval = $this->approvalRequired();

        $result = $this->service->confirm($token, $password, $requireApproval);

        if ($result === null) {
            $this->audit->record('auth.password_reset.failed', [
                'tenant_id'  => self::SYSTEM_TENANT_ID,
                'ip_address' => ClientIp::fromRequest($request),
            ]);

            return Response::error('This reset link is invalid or has expired', 400);
        }

        if ($result['applied']) {
            $this->audit->record('auth.password_reset.completed', [
                'tenant_id'     => self::SYSTEM_TENANT_ID,
                'actor_user_id' => $result['profile_id'],
                'target_type'   => 'profile',
                'target_id'     => $result['profile_id'],
                'ip_address'    => ClientIp::fromRequest($request),
            ]);

            return Response::json([
                'data' => ['status' => 'applied', 'message' => 'Your password has been reset. You can now sign in.'],
            ], 200);
        }

        $this->audit->record('auth.password_reset.submitted_for_approval', [
            'tenant_id'     => self::SYSTEM_TENANT_ID,
            'actor_user_id' => $result['profile_id'],
            'target_type'   => 'profile',
            'target_id'     => $result['profile_id'],
            'ip_address'    => ClientIp::fromRequest($request),
        ]);

        return Response::json([
            'data' => [
                'status'  => 'awaiting_approval',
                'message' => 'Your password reset has been submitted for administrator approval.',
            ],
        ], 200);
    }

    /**
     * Answer a repeat request for a profile whose reset is ALREADY parked in
     * the approval queue (WC-797 §4b/§4c).
     *
     * Idempotent by construction: the parked request is left exactly as it is —
     * no second token, no supersede, no second queue entry for an administrator
     * to disambiguate — and the requester is told, by mail, that it is waiting.
     *
     * The rate-limit release is the delicate part. The unit was already counted
     * above and stays counted for the FIRST request of every window, for every
     * address, known or not: that is what keeps the 429 boundary silent about
     * whether an address exists. Only a REPEAT inside a window that has already
     * been charged is released, and only for a profile whose parked state
     * nobody but the account holder could have created (it takes a valid,
     * mailed, single-use token to park a request at all). So the residual
     * signal is available only to someone already holding the mailbox, while
     * the failure it removes — a locked-out user throttled for retrying a reset
     * that could never apply — is the one an adopter actually hit.
     *
     * The per-IP unit is deliberately NOT released: it is a shared ceiling
     * across every address probed from one host, and leaving it charged means
     * this path can never be used to buy unlimited requests from a single IP.
     */
    private function notifyAwaitingApproval(
        string $email,
        int $profileId,
        ?string $ip,
        string $emailKey,
        int $emailCount
    ): void {
        $this->mailer->sendAwaitingApprovalNotice($email);

        $this->audit->record('auth.password_reset.awaiting_approval_notified', [
            'tenant_id'   => self::SYSTEM_TENANT_ID,
            'target_type' => 'profile',
            'target_id'   => $profileId,
            'ip_address'  => $ip,
        ]);

        if ($emailCount > 1) {
            $this->store->decrement($emailKey);
        }
    }

    private function selfServiceEnabled(): bool
    {
        try {
            $global = $this->settings->getGlobal();
        } catch (\Throwable) {
            return false;
        }

        return (string) ($global[SettingsRegistry::SELF_PASSWORD_RESET_ENABLED] ?? 'false') === 'true';
    }

    private function approvalRequired(): bool
    {
        try {
            $global = $this->settings->getGlobal();
        } catch (\Throwable) {
            // Fail CLOSED (require approval) on a settings-read failure — never
            // silently skip the operator's approval gate.
            return true;
        }

        return (string) ($global[SettingsRegistry::PASSWORD_RESET_APPROVAL_REQUIRED] ?? 'false') === 'true';
    }
}
