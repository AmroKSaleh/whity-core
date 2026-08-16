<?php

declare(strict_types=1);

namespace Whity\Api;

use Whity\Core\Audit\AuditLogger;
use Whity\Core\Identity\InvitationService;
use Whity\Core\PasswordPolicy;
use Whity\Core\RateLimit\ClientIp;
use Whity\Core\Request;
use Whity\Core\Response;
use Whity\Core\Store\SharedStoreInterface;
use Whity\Http\JsonBody;

/**
 * The public end of the invitation flow (WHIT-417 / #797 item 3):
 *   GET  /api/v1/invitations/accept?token=… — what the link leads to
 *   POST /api/v1/invitations/accept         — turn the token into access
 *
 * PUBLIC + UNAUTHENTICATED by design, like the password-reset pair: an invitee
 * has no session, and in the case that matters most may have no account at all.
 * They resolve no tenant of their own — the invitation names its tenant — so
 * they sit on the tenant-isolation public-route allowlist, with the token
 * lifecycle and the IP throttle as the safeguards.
 *
 * WHAT THE TOKEN HOLDER IS ALLOWED TO LEARN. The GET answers whether a password
 * is needed, which is the same as saying whether this address already has an
 * account. That is deliberate and it is safe HERE and nowhere else: the caller
 * holds a valid, unexpired, single-use 256-bit token that was mailed to that
 * address, which is exactly the proof of mailbox control a password-reset link
 * demands before it will do considerably more. A prober without a token gets
 * one answer — the same generic 404 an expired, revoked, superseded or
 * already-used token gets, so none of those four is distinguishable either.
 *
 * WHY ACCEPTING DOES NOT SIGN YOU IN. It would have to mint a session for a
 * profile whose sole proof of identity is a link that may have been forwarded.
 * A password reset gets away with that because it CHANGES the credential; this
 * flow deliberately does not touch an existing one. So both branches finish at
 * the sign-in page — where an existing profile's own password, 2FA and
 * tenant-selection prompt all still apply.
 */
final class InvitationAcceptHandler
{
    /** Throttle: fixed window, per-IP. Defence in depth behind a 256-bit token. */
    private const WINDOW_SECONDS = 3600;
    private const IP_MAX = 30;

    private const SYSTEM_TENANT_ID = 0;

    /**
     * One sentence for unknown, expired, revoked, superseded and already-used.
     * Splitting it would turn the endpoint into an oracle for which links exist.
     */
    private const GENERIC_INVALID = 'This invitation link is invalid, has expired, or has already been used';

    public function __construct(
        private readonly InvitationService $service,
        private readonly SharedStoreInterface $store,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * GET /api/v1/invitations/accept?token=… — describe the invitation without
     * consuming it, so the page can render the right form on first paint.
     */
    public function preview(Request $request): Response
    {
        $throttled = $this->throttle($request);
        if ($throttled instanceof Response) {
            return $throttled;
        }

        $token = $this->tokenFromQuery($request);

        try {
            $preview = $this->service->preview($token);
        } catch (\Throwable $e) {
            error_log('[invitations] preview failed: ' . $e->getMessage());

            return Response::error('Failed to read the invitation', 500);
        }

        if ($preview === null) {
            return Response::error(self::GENERIC_INVALID, 404);
        }

        return Response::json(['data' => [
            'email' => $preview['email'],
            'tenant_name' => $preview['tenant_name'],
            'requires_password' => $preview['requires_password'],
        ]], 200);
    }

    /**
     * POST /api/v1/invitations/accept — consume the token.
     *
     * A password is required only when the address has no profile yet; one sent
     * alongside an address that already has an account is ignored rather than
     * applied, because joining a workspace is not a credential change.
     */
    public function accept(Request $request): Response
    {
        $throttled = $this->throttle($request);
        if ($throttled instanceof Response) {
            return $throttled;
        }

        $body = JsonBody::parsed($request);
        $token = (string) ($body['token'] ?? '');
        $password = (string) ($body['password'] ?? '');

        if (trim($token) === '') {
            return Response::error('An invitation token is required', 422);
        }

        $passwordHash = null;
        if ($password !== '') {
            try {
                PasswordPolicy::validate($password);
            } catch (\InvalidArgumentException) {
                return Response::error(
                    'Password must be at least ' . PasswordPolicy::MIN_LENGTH . ' characters',
                    422
                );
            }
            $passwordHash = password_hash($password, PASSWORD_BCRYPT);
        }

        try {
            $result = $this->service->accept($token, $passwordHash);
        } catch (\Throwable $e) {
            error_log('[invitations] accept failed: ' . $e->getMessage());

            return Response::error('Failed to accept the invitation', 500);
        }

        return match ($result['result']) {
            InvitationService::ACCEPT_JOINED,
            InvitationService::ACCEPT_ALREADY_MEMBER => $this->accepted($request, $result),

            // The token is still good — the invitee simply has to pick a
            // password. Safe to say plainly: they already hold a valid token.
            InvitationService::ACCEPT_PASSWORD_REQUIRED => Response::error(
                'Choose a password to finish setting up your account',
                422
            ),

            // An administrator suspended this account. Say so rather than
            // pretending the link is broken, so the invitee knows who to ask.
            InvitationService::ACCEPT_SUSPENDED => Response::error(
                'Your access to this workspace has been suspended. Contact an administrator.',
                409
            ),

            default => $this->invalid($request),
        };
    }

    // ── internals ────────────────────────────────────────────────────────────

    /**
     * @param array{result: string, tenant_id: int|null, profile_id: int|null} $result
     */
    private function accepted(Request $request, array $result): Response
    {
        $this->audit->record('tenant.invitation.accepted', [
            'tenant_id' => $result['tenant_id'] ?? self::SYSTEM_TENANT_ID,
            'actor_user_id' => $result['profile_id'],
            'target_type' => 'profile',
            'target_id' => $result['profile_id'],
            'ip_address' => ClientIp::fromRequest($request),
        ]);

        return Response::json(['data' => [
            'status' => $result['result'],
            'message' => $result['result'] === InvitationService::ACCEPT_ALREADY_MEMBER
                ? 'You already have access to this workspace. Sign in to continue.'
                : 'Your invitation has been accepted. Sign in to continue.',
        ]], 200);
    }

    private function invalid(Request $request): Response
    {
        $this->audit->record('tenant.invitation.accept_failed', [
            'tenant_id' => self::SYSTEM_TENANT_ID,
            'ip_address' => ClientIp::fromRequest($request),
        ]);

        return Response::error(self::GENERIC_INVALID, 400);
    }

    /**
     * Per-IP ceiling shared by preview and accept.
     *
     * Counted before the token is looked at, so the boundary carries no
     * information about whether a token was real.
     */
    private function throttle(Request $request): ?Response
    {
        $ip = ClientIp::fromRequest($request);
        if ($ip === null) {
            return null;
        }

        $key = 'invite:accept:ip:' . $ip;
        if ($this->store->count($key) >= self::IP_MAX) {
            return Response::error('Too many attempts. Please try again later.', 429)
                ->withHeaders(['Retry-After' => (string) max($this->store->ttl($key), 1)]);
        }

        $this->store->increment($key, self::WINDOW_SECONDS);

        return null;
    }

    /**
     * The token from `?token=…`.
     *
     * Both sources, as everywhere else in src/Api: the request path carries the
     * query under the test harness and under some SAPI configurations, $_GET
     * under others. `parse_str` can yield arrays for `?token[]=x`, so anything
     * that is not a plain string is treated as absent.
     */
    private function tokenFromQuery(Request $request): string
    {
        $query = parse_url($request->getPath(), PHP_URL_QUERY);
        if (is_string($query) && $query !== '') {
            $params = [];
            parse_str($query, $params);
            if (is_string($params['token'] ?? null)) {
                return $params['token'];
            }
        }

        return is_string($_GET['token'] ?? null) ? $_GET['token'] : '';
    }
}
