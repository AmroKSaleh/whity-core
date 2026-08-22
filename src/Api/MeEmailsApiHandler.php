<?php

declare(strict_types=1);

namespace Whity\Api;

use Whity\Auth\TokenValidator;
use Whity\Core\Audit\AuditLogger;
use Whity\Core\Db\DbBool;
use Whity\Core\Identity\EmailVerificationProvider;
use Whity\Core\Identity\ProfileEmailRepository;
use Whity\Core\RateLimit\ClientIp;
use Whity\Core\Request;
use Whity\Core\Response;
use Whity\Core\Store\SharedStoreInterface;
use Whity\Http\JsonBody;

/**
 * Self-service multi-email management for the signed-in user:
 *   GET    /api/me/emails                       → list the caller's email addresses
 *   POST   /api/me/emails                        → add a new (unverified) address
 *   POST   /api/me/emails/{id}/resend-verification → resend the verification link
 *   POST   /api/me/emails/{id}/set-primary        → promote a verified address to primary
 *   DELETE /api/me/emails/{id}                    → remove an address
 *
 * Self-authenticating via the access token (cookie, or Bearer for token mode),
 * exactly like {@see MeIdentitiesApiHandler}. Every operation is scoped to the
 * caller's OWN profile_id — an id belonging to another profile is reported as a
 * plain 404 (never revealing that the row exists at all).
 *
 * A newly-added address is ALWAYS inserted unverified, regardless of whether
 * {@see \Whity\Core\Identity\EmailVerificationPolicy} enforces verification at
 * REGISTRATION time — that policy only governs the primary email at signup;
 * proving ownership of an additionally-added address is a separate, always-on
 * requirement here.
 *
 * Lockout guards: a caller can never remove their only email address, and
 * cannot remove their CURRENT primary address while any other address exists
 * (they must promote a different one to primary first) — so an account can
 * never end up with zero emails or an ambiguous primary.
 */
final class MeEmailsApiHandler
{
    /** Hard cap on addresses per profile — generous, but not unbounded. */
    private const MAX_EMAILS_PER_PROFILE = 10;

    private const MAX_EMAIL_LENGTH = 255;

    /** Resend throttle: fixed window + per-row ceiling (caller is authenticated, so no per-IP limiter needed). */
    private const RESEND_WINDOW_SECONDS = 3600;
    private const RESEND_MAX = 5;

    public function __construct(
        private readonly TokenValidator $tokenValidator,
        private readonly ProfileEmailRepository $emails,
        private readonly EmailVerificationProvider $verificationProvider,
        private readonly SharedStoreInterface $store,
        private readonly AuditLogger $audit,
    ) {
    }

    public function list(Request $request): Response
    {
        $profileId = $this->resolveProfileId($request);
        if ($profileId === null) {
            return Response::error('Authentication required', 401);
        }

        $rows = array_map(
            self::toPublicEmail(...),
            $this->emails->findByProfileId($profileId),
        );

        return Response::json(['data' => $rows]);
    }

    public function add(Request $request): Response
    {
        $profileId = $this->resolveProfileId($request);
        if ($profileId === null) {
            return Response::error('Authentication required', 401);
        }

        $body = JsonBody::parsed($request);
        $email = strtolower(trim((string) ($body['email'] ?? '')));

        if ($email === '' || filter_var($email, FILTER_VALIDATE_EMAIL) === false || strlen($email) > self::MAX_EMAIL_LENGTH) {
            return Response::error('A valid email address is required', 422);
        }

        if ($this->emails->countForProfile($profileId) >= self::MAX_EMAILS_PER_PROFILE) {
            return Response::error('You have reached the maximum number of email addresses', 422);
        }

        // profile_emails.email is globally unique (ADR 0005 §2) — this covers
        // both "already yours" and "belongs to another profile" without
        // distinguishing the two in the response (no enumeration of other
        // profiles' addresses).
        if ($this->emails->findByEmail($email) !== null) {
            return Response::error('This email address is already registered', 409);
        }

        $id = $this->emails->insert($profileId, $email, verified: false, isPrimary: false);

        try {
            $this->verificationProvider->sendVerification($profileId, $email);
        } catch (\Throwable $e) {
            // Delivery failure must not fail the request — the row is created and
            // verification can be re-requested via resend-verification.
            error_log('[me-emails] verification dispatch failed: ' . $e->getMessage());
        }

        $this->audit->record('profile_email.added', [
            'target_type' => 'profile_email',
            'target_id' => $id,
            'ip_address' => ClientIp::fromRequest($request),
        ]);

        $row = $this->emails->findById($id);
        if ($row === null) {
            return Response::error('Failed to add email address', 500);
        }

        return Response::json(['data' => self::toPublicEmail($row)], 201);
    }

    /**
     * @param array<string, string> $params
     */
    public function resendVerification(Request $request, array $params): Response
    {
        $profileId = $this->resolveProfileId($request);
        if ($profileId === null) {
            return Response::error('Authentication required', 401);
        }

        $row = $this->findOwn($profileId, $params);
        if ($row === null) {
            return Response::error('Email address not found', 404);
        }

        if ($row['verified'] === true) {
            return Response::error('This email address is already verified', 400);
        }

        $rateKey = 'me-emails:resend:' . $row['id'];
        if ($this->store->count($rateKey) >= self::RESEND_MAX) {
            $retryAfter = max($this->store->ttl($rateKey), 1);
            return Response::error('Too many resend requests. Please try again later.', 429)
                ->withHeaders(['Retry-After' => (string) $retryAfter]);
        }
        $this->store->increment($rateKey, self::RESEND_WINDOW_SECONDS);

        try {
            $this->verificationProvider->sendVerification($profileId, (string) $row['email']);
        } catch (\Throwable $e) {
            error_log('[me-emails] resend dispatch failed: ' . $e->getMessage());
        }

        $this->audit->record('profile_email.verification_resent', [
            'target_type' => 'profile_email',
            'target_id' => $row['id'],
            'ip_address' => ClientIp::fromRequest($request),
        ]);

        return Response::json(['data' => ['message' => 'Verification email sent']], 202);
    }

    /**
     * @param array<string, string> $params
     */
    public function setPrimary(Request $request, array $params): Response
    {
        $profileId = $this->resolveProfileId($request);
        if ($profileId === null) {
            return Response::error('Authentication required', 401);
        }

        $row = $this->findOwn($profileId, $params);
        if ($row === null) {
            return Response::error('Email address not found', 404);
        }

        if ($row['verified'] !== true) {
            return Response::error('Verify this email address before setting it as primary', 400);
        }

        $this->emails->setPrimary($profileId, (int) $row['id']);

        $this->audit->record('profile_email.primary_set', [
            'target_type' => 'profile_email',
            'target_id' => $row['id'],
        ]);

        $updated = $this->emails->findById((int) $row['id']);
        if ($updated === null) {
            return Response::error('Email address not found', 404);
        }

        return Response::json(['data' => self::toPublicEmail($updated)]);
    }

    /**
     * @param array<string, string> $params
     */
    public function remove(Request $request, array $params): Response
    {
        $profileId = $this->resolveProfileId($request);
        if ($profileId === null) {
            return Response::error('Authentication required', 401);
        }

        $row = $this->findOwn($profileId, $params);
        if ($row === null) {
            return Response::error('Email address not found', 404);
        }

        if ($this->emails->countForProfile($profileId) <= 1) {
            return Response::error('Cannot remove your only email address', 409);
        }

        if ($row['is_primary'] === true) {
            return Response::error(
                'Cannot remove your primary email address. Set a different address as primary first.',
                409
            );
        }

        if ($this->emails->delete((int) $row['id']) === 0) {
            return Response::error('Email address not found', 404);
        }

        $this->audit->record('profile_email.removed', [
            'target_type' => 'profile_email',
            'target_id' => $row['id'],
        ]);

        return Response::json([], 204);
    }

    /**
     * Resolve a profile_emails row from the `id` route param, scoped to the
     * caller's own profile — a foreign or unknown id resolves to null (404),
     * never revealing which case it was.
     *
     * @param array<string, string> $params
     * @return array<string, mixed>|null
     */
    private function findOwn(int $profileId, array $params): ?array
    {
        $id = (int) ($params['id'] ?? 0);
        if ($id <= 0) {
            return null;
        }

        $row = $this->emails->findById($id);
        if ($row === null || (int) $row['profile_id'] !== $profileId) {
            return null;
        }

        return $row;
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private static function toPublicEmail(array $row): array
    {
        return [
            'id' => (int) $row['id'],
            'email' => (string) $row['email'],
            'verified' => DbBool::of($row['verified']),
            'isPrimary' => DbBool::of($row['is_primary']),
            'createdAt' => (string) $row['created_at'],
        ];
    }

    /** Resolve the caller's profile id from a valid access token (cookie or Bearer). */
    private function resolveProfileId(Request $request): ?int
    {
        $claims = $this->tokenValidator->validateAccessToken();
        if ($claims === null) {
            $header = $request->getHeader('Authorization') ?? '';
            if (stripos($header, 'Bearer ') === 0) {
                $claims = $this->tokenValidator->validateAccessTokenFromBearer(substr($header, 7));
            }
        }
        if ($claims === null) {
            return null;
        }
        $profileId = $claims['profile_id'] ?? null;
        return is_int($profileId) && $profileId > 0 ? $profileId : null;
    }
}
