<?php

declare(strict_types=1);

namespace Whity\Api;

use Whity\Core\Audit\AuditLogger;
use Whity\Core\Identity\ProfileEmailRepository;
use Whity\Core\Identity\TwoFactorRecoveryMailer;
use Whity\Core\Identity\TwoFactorRecoveryService;
use Whity\Core\RateLimit\ClientIp;
use Whity\Core\Request;
use Whity\Core\Response;
use Whity\Core\Settings\SettingsRegistry;
use Whity\Core\Settings\SettingsService;
use Whity\Core\Store\SharedStoreInterface;
use Whity\Http\JsonBody;

/**
 * Public "I lost my 2FA device" recovery-request endpoints
 * (WC-password-reset-2fa-recovery):
 *   POST /api/v1/auth/2fa-recovery/request — submit an email, get a confirmation link
 *   POST /api/v1/auth/2fa-recovery/confirm — confirm the token; CREATES the pending admin request
 *
 * Both are PUBLIC + UNAUTHENTICATED by design: a user who lost both their
 * password AND their 2FA device has no session at all. Mirrors
 * {@see EmailVerificationHandler}'s request+confirm shape.
 *
 * This is a REQUEST, never an instant self-service action — `confirm` only
 * creates a PENDING entry in the admin approval queue; it never clears 2FA or
 * touches the target profile. An admin must explicitly approve
 * (see {@see TwoFactorRecoveryApprovalsApiHandler}) before anything changes.
 *
 * NO ENUMERATION: `request` returns the SAME 202 whether or not the address
 * exists AND regardless of whether the flow is even enabled
 * (`auth.self_2fa_recovery_enabled`). `confirm` returns a generic 400 for any
 * bad/expired/replayed token — token possession there already proves mailbox
 * ownership, so revealing "request submitted" on success is safe.
 *
 * Audited as system-level (tenant 0) identity events.
 */
final class TwoFactorRecoveryHandler
{
    private const SYSTEM_TENANT_ID = 0;

    private const WINDOW_SECONDS = 3600;
    private const EMAIL_MAX      = 5;
    private const IP_MAX         = 20;

    public function __construct(
        private readonly TwoFactorRecoveryService $service,
        private readonly ProfileEmailRepository $emails,
        private readonly TwoFactorRecoveryMailer $mailer,
        private readonly SharedStoreInterface $store,
        private readonly AuditLogger $audit,
        private readonly SettingsService $settings,
    ) {}

    /**
     * POST /api/v1/auth/2fa-recovery/request — (re)issue a confirmation link.
     *
     * Rate-limited per-email and per-IP. Always answers 202 with a generic
     * message; dispatch happens only for a known address AND when
     * `auth.self_2fa_recovery_enabled` is on.
     */
    public function request(Request $request): Response
    {
        $body  = JsonBody::parsed($request);
        $email = strtolower(trim((string) ($body['email'] ?? '')));

        if ($email === '' || filter_var($email, FILTER_VALIDATE_EMAIL) === false || strlen($email) > 255) {
            return Response::error('A valid email address is required', 422);
        }

        $ip       = ClientIp::fromRequest($request);
        $emailKey = '2farecovery:req:email:' . hash('sha256', $email);
        $ipKey    = $ip !== null ? '2farecovery:req:ip:' . $ip : null;

        if ($this->store->count($emailKey) >= self::EMAIL_MAX
            || ($ipKey !== null && $this->store->count($ipKey) >= self::IP_MAX)
        ) {
            $retryAfter = max(
                $this->store->ttl($emailKey),
                $ipKey !== null ? $this->store->ttl($ipKey) : 0,
                1
            );

            return Response::error(
                'Too many recovery requests. Please try again later.',
                429
            )->withHeaders(['Retry-After' => (string) $retryAfter]);
        }

        $this->store->increment($emailKey, self::WINDOW_SECONDS);
        if ($ipKey !== null) {
            $this->store->increment($ipKey, self::WINDOW_SECONDS);
        }

        if ($this->selfServiceEnabled()) {
            $row = $this->emails->findByEmail($email);
            if ($row !== null) {
                try {
                    $profileId = (int) $row['profile_id'];
                    $token = $this->service->issue($profileId);
                    $this->mailer->sendConfirmationLink($email, $token);
                    $this->audit->record('auth.2fa_recovery.requested', [
                        'tenant_id'   => self::SYSTEM_TENANT_ID,
                        'target_type' => 'profile',
                        'target_id'   => $profileId,
                        'ip_address'  => $ip,
                    ]);
                } catch (\Throwable $e) {
                    error_log('[2fa-recovery] request dispatch failed: ' . $e->getMessage());
                }
            }
        }

        return Response::json([
            'data' => ['message' => 'If that address has an account, a confirmation link has been sent.'],
        ], 202);
    }

    /**
     * POST /api/v1/auth/2fa-recovery/confirm — consume the confirmation token.
     *
     * On success this CREATES the pending admin-queue entry; it does NOT touch
     * 2FA or any other profile field. Generic 400 for any unknown/expired/
     * replayed token.
     */
    public function confirm(Request $request): Response
    {
        $body  = JsonBody::parsed($request);
        $token = (string) ($body['token'] ?? '');

        if (trim($token) === '') {
            return Response::error('A confirmation token is required', 422);
        }

        $result = $this->service->confirm($token);

        if ($result === null) {
            $this->audit->record('auth.2fa_recovery.confirm_failed', [
                'tenant_id'  => self::SYSTEM_TENANT_ID,
                'ip_address' => ClientIp::fromRequest($request),
            ]);

            return Response::error('This confirmation link is invalid or has expired', 400);
        }

        $this->audit->record('auth.2fa_recovery.submitted', [
            'tenant_id'     => self::SYSTEM_TENANT_ID,
            'actor_user_id' => $result['profile_id'],
            'target_type'   => 'profile',
            'target_id'     => $result['profile_id'],
            'ip_address'    => ClientIp::fromRequest($request),
        ]);

        // Best-effort courtesy notice; must never fail the confirm response.
        try {
            $emailRow = $this->emails->findPrimaryForProfile($result['profile_id']);
        } catch (\Throwable) {
            $emailRow = null;
        }
        if (is_array($emailRow) && ($emailRow['email'] ?? '') !== '') {
            try {
                $this->mailer->sendSubmittedNotice((string) $emailRow['email']);
            } catch (\Throwable $e) {
                error_log('[2fa-recovery] submitted-notice dispatch failed: ' . $e->getMessage());
            }
        }

        return Response::json([
            'data' => [
                'status'  => 'pending',
                'message' => 'Your account-recovery request has been submitted for administrator review.',
            ],
        ], 200);
    }

    private function selfServiceEnabled(): bool
    {
        try {
            $global = $this->settings->getGlobal();
        } catch (\Throwable) {
            return false;
        }

        return (string) ($global[SettingsRegistry::SELF_2FA_RECOVERY_ENABLED] ?? 'false') === 'true';
    }
}
