<?php

declare(strict_types=1);

namespace Whity\Api;

use PDO;
use Whity\Auth\TokenValidator;
use Whity\Core\Identity\ExternalIdentityRepository;
use Whity\Core\Request;
use Whity\Core\Response;

/**
 * "Connected accounts" management for the signed-in user (WC-f3b17bd2):
 *   GET    /api/v1/me/identities        → list the caller's linked SSO identities
 *   DELETE /api/v1/me/identities/{id}   → unlink one of the caller's identities
 *
 * Self-authenticating via the access token (cookie, or Bearer for token mode),
 * exactly like {@see DeviceApiHandler}. Every operation is scoped to the caller's
 * OWN profile_id — the repository's profile-scoped queries mean an id belonging to
 * another profile simply matches nothing.
 *
 * Lockout guard: unlinking is refused (409) when it would remove the caller's ONLY
 * sign-in method — i.e. the profile has no password (a passwordless, SSO-provisioned
 * account) and this is its last linked identity — so a user cannot strand themselves.
 */
final class MeIdentitiesApiHandler
{
    public function __construct(
        private readonly TokenValidator $tokenValidator,
        private readonly ExternalIdentityRepository $identities,
        private readonly PDO $db,
    ) {
    }

    public function list(Request $request): Response
    {
        $profileId = $this->resolveProfileId($request);
        if ($profileId === null) {
            return Response::error('Authentication required', 401);
        }

        $rows = array_map(
            static fn(array $r): array => [
                'id'            => $r['id'],
                'provider_key'  => $r['provider_key'],
                'email'         => $r['email'],
                'linked_at'     => $r['linked_at'],
                'last_login_at' => $r['last_login_at'],
            ],
            $this->identities->findByProfileId($profileId),
        );

        return Response::json(['data' => $rows]);
    }

    /**
     * @param array<string, string> $params
     */
    public function unlink(Request $request, array $params): Response
    {
        $profileId = $this->resolveProfileId($request);
        if ($profileId === null) {
            return Response::error('Authentication required', 401);
        }

        $id = (int) ($params['id'] ?? 0);
        if ($id <= 0) {
            return Response::error('Invalid id', 400);
        }

        // Lockout guard: don't let a passwordless account remove its last identity.
        if ($this->identities->countForProfile($profileId) <= 1 && $this->isPasswordless($profileId)) {
            return Response::error(
                'Cannot remove your only sign-in method. Set a password first, then unlink.',
                409
            );
        }

        if ($this->identities->unlink($id, $profileId) === 0) {
            return Response::error('Identity not found', 404);
        }
        return Response::json([], 204);
    }

    /** True when the profile has no usable password (SSO-only account). */
    private function isPasswordless(int $profileId): bool
    {
        $stmt = $this->db->prepare('SELECT password_hash FROM profiles WHERE id = :id');
        $stmt->execute([':id' => $profileId]);
        $hash = $stmt->fetchColumn();
        return $hash === false || (string) $hash === '';
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
