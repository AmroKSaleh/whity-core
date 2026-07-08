<?php

declare(strict_types=1);

namespace Whity\Api;

use Whity\Core\Audit\AuditLogger;
use Whity\Core\Identity\EmailVerificationProvider;
use Whity\Core\Identity\EmailVerificationService;
use Whity\Core\Identity\ProfileEmailRepository;
use Whity\Core\RateLimit\ClientIp;
use Whity\Core\Request;
use Whity\Core\Response;
use Whity\Core\Store\SharedStoreInterface;
use Whity\Http\JsonBody;

/**
 * Public email-verification endpoints (WC-235):
 *   POST /api/v1/email/request-verification  — (re)send a verification link
 *   POST /api/v1/email/verify                — confirm a token
 *
 * Both are PUBLIC + UNAUTHENTICATED by design: a freshly-registered owner is not
 * logged in yet, and the confirm link is clicked from an email with no session.
 * They resolve no tenant, so they sit on the tenant-isolation public-route
 * allowlist; the token lifecycle and rate-limiting are the safeguards.
 *
 * NO ENUMERATION: `request-verification` returns the SAME 202 whether or not the
 * address exists / is already verified, so it cannot be used to probe which
 * emails are registered. `verify` returns a generic 400 for any bad/expired/
 * replayed token.
 *
 * Audited as system-level (tenant 0) identity events — the email is a global
 * identity artifact, not a per-tenant resource.
 */
final class EmailVerificationHandler
{
    /** System tenant that owns cross-tenant / system-level audit records. */
    private const SYSTEM_TENANT_ID = 0;

    /** Resend throttle: fixed window + per-email and per-IP ceilings. */
    private const WINDOW_SECONDS = 3600;
    private const EMAIL_MAX      = 5;
    private const IP_MAX         = 20;

    public function __construct(
        private readonly EmailVerificationService $service,
        private readonly ProfileEmailRepository $emails,
        private readonly EmailVerificationProvider $provider,
        private readonly SharedStoreInterface $store,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * POST /api/v1/email/request-verification — (re)issue a verification link.
     *
     * Rate-limited per-email and per-IP. Always answers 202 with a generic
     * message (no enumeration); dispatch happens only for a known, unverified
     * address and any delivery failure is swallowed (logged) so the response
     * never varies with existence.
     */
    public function request(Request $request): Response
    {
        $body  = JsonBody::parsed($request);
        $email = strtolower(trim((string) ($body['email'] ?? '')));

        // Format validation is not enumeration (it never touches the DB).
        if ($email === '' || filter_var($email, FILTER_VALIDATE_EMAIL) === false || strlen($email) > 255) {
            return Response::error('A valid email address is required', 422);
        }

        $ip       = ClientIp::fromRequest($request);
        $emailKey = 'emailverify:req:email:' . hash('sha256', $email);
        $ipKey    = $ip !== null ? 'emailverify:req:ip:' . $ip : null;

        if ($this->store->count($emailKey) >= self::EMAIL_MAX
            || ($ipKey !== null && $this->store->count($ipKey) >= self::IP_MAX)
        ) {
            $retryAfter = max(
                $this->store->ttl($emailKey),
                $ipKey !== null ? $this->store->ttl($ipKey) : 0,
                1
            );

            return Response::error(
                'Too many verification requests. Please try again later.',
                429
            )->withHeaders(['Retry-After' => (string) $retryAfter]);
        }

        // Count this attempt against both windows before doing any work.
        $this->store->increment($emailKey, self::WINDOW_SECONDS);
        if ($ipKey !== null) {
            $this->store->increment($ipKey, self::WINDOW_SECONDS);
        }

        $row = $this->emails->findByEmail($email);
        if ($row !== null && $row['verified'] === false) {
            try {
                $this->provider->sendVerification((int) $row['profile_id'], $email);
                $this->audit->record('email.verification.requested', [
                    'tenant_id'   => self::SYSTEM_TENANT_ID,
                    'target_type' => 'profile_email',
                    'target_id'   => (int) $row['id'],
                    'ip_address'  => $ip,
                ]);
            } catch (\Throwable $e) {
                // Delivery/issuance failure must not change the response shape.
                error_log('[email-verify] request dispatch failed: ' . $e->getMessage());
            }
        }

        return Response::json([
            'data' => ['message' => 'If that address requires verification, a link has been sent.'],
        ], 202);
    }

    /**
     * POST /api/v1/email/verify — consume a verification token.
     *
     * Generic 400 for any unknown/expired/replayed token (no distinction that
     * could aid probing). 200 on success. The global pre-auth per-IP limiter
     * already backstops brute force (a 256-bit token is infeasible to guess).
     */
    public function confirm(Request $request): Response
    {
        $body  = JsonBody::parsed($request);
        $token = (string) ($body['token'] ?? '');

        if (trim($token) === '') {
            return Response::error('A verification token is required', 422);
        }

        $result = $this->service->confirm($token);

        if ($result === null) {
            $this->audit->record('email.verification.failed', [
                'tenant_id'  => self::SYSTEM_TENANT_ID,
                'ip_address' => ClientIp::fromRequest($request),
            ]);

            return Response::error('This verification link is invalid or has expired', 400);
        }

        $this->audit->record('email.verification.confirmed', [
            'tenant_id'     => self::SYSTEM_TENANT_ID,
            'actor_user_id' => $result['profile_id'],
            'target_type'   => 'profile_email',
            'target_id'     => $result['profile_email_id'],
            'ip_address'    => ClientIp::fromRequest($request),
        ]);

        return Response::json([
            'data' => ['verified' => true, 'email' => $result['email']],
        ], 200);
    }
}
