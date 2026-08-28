<?php

declare(strict_types=1);

namespace Whity\Api;

use PDO;
use Whity\Core\Identity\AssignableRole;
use Whity\Core\Identity\DomainOwnershipVerifier;
use Whity\Core\Identity\TenantEmailDomainsRepository;
use Whity\Core\Request;
use Whity\Core\Response;
use Whity\Core\Tenant\TenantContext;
use Whity\Http\JsonBody;

/**
 * Tenant Email-Domain API Handler (WC-9b87, WC-628738f5).
 *
 * Provides the admin CRUD surface for managing a tenant's email-domain policy
 * registrations. All routes are gated on the `admin` role and tenant-scoped via
 * TenantContext so a tenant can only manage its own domain registrations.
 *
 * A registered domain does NOT auto-provision memberships until the tenant proves
 * it controls the domain via the DNS TXT challenge (verify()); this closes the
 * cross-tenant harvesting hole where a tenant could claim a domain it does not own.
 *
 * Routes (registered in public/index.php):
 *   GET    /api/email-domains             → list()
 *   POST   /api/email-domains             → create()
 *   PATCH  /api/email-domains/{id}        → update()
 *   POST   /api/email-domains/{id}/verify → verify()
 *   DELETE /api/email-domains/{id}        → delete()
 */
final class TenantEmailDomainApiHandler
{
    /**
     * Names no role and leaks no existence. A tenant probing ids should not be
     * able to tell "this role belongs to somebody else" from "no such role" —
     * both are equally not-assignable here, and distinguishing them would turn
     * this validation into a role enumerator across tenants.
     */
    private const FOREIGN_ROLE_MESSAGE =
        'default_role_id must be a role belonging to this tenant, or a global role';

    private TenantEmailDomainsRepository $repo;
    private AssignableRole $assignableRole;
    private DomainOwnershipVerifier $verifier;

    public function __construct(PDO $db, DomainOwnershipVerifier $verifier)
    {
        $this->repo = new TenantEmailDomainsRepository($db);
        $this->assignableRole = new AssignableRole($db);
        $this->verifier = $verifier;
    }

    /**
     * GET /api/email-domains — list all domain registrations for the current tenant.
     */
    public function list(Request $request): Response
    {
        $tenantId = TenantContext::getTenantId();
        if ($tenantId === null) {
            return Response::error('Tenant context is required', 400);
        }

        $rows = array_map($this->withChallenge(...), $this->repo->listForTenant($tenantId));
        return Response::json(['data' => $rows]);
    }

    /**
     * POST /api/email-domains — register a new domain for the current tenant.
     *
     * Body: { "domain": string, "default_role_id": int, "auto_provision"?: bool }
     */
    public function create(Request $request): Response
    {
        $tenantId = TenantContext::getTenantId();
        if ($tenantId === null) {
            return Response::error('Tenant context is required', 400);
        }

        $body = JsonBody::parsed($request);

        $domain = isset($body['domain']) ? trim((string) $body['domain']) : '';
        if ($domain === '') {
            return Response::error('domain is required', 422);
        }
        if (!$this->isValidDomain($domain)) {
            return Response::error('domain must be a valid hostname (no scheme or path)', 422);
        }

        $defaultRoleId = isset($body['default_role_id']) ? (int) $body['default_role_id'] : 0;
        if ($defaultRoleId <= 0) {
            return Response::error('default_role_id is required and must be a positive integer', 422);
        }
        if (!$this->assignableRole->isAssignable($defaultRoleId, $tenantId)) {
            return Response::error(self::FOREIGN_ROLE_MESSAGE, 422);
        }

        $autoProvision = !isset($body['auto_provision']) || (bool) $body['auto_provision'];

        try {
            $id = $this->repo->insert($tenantId, $domain, $defaultRoleId, $autoProvision);
        } catch (\PDOException $e) {
            if (str_contains($e->getMessage(), 'UNIQUE') || str_contains($e->getMessage(), 'unique')) {
                return Response::error('This domain is already registered for your tenant', 409);
            }
            return Response::error('Failed to register domain', 500);
        }

        $row = $this->repo->findById($id, $tenantId);
        return Response::json(['data' => $this->withChallenge($row)], 201);
    }

    /**
     * PATCH /api/email-domains/{id} — change what arrivals on this domain become.
     *
     * Body: { "default_role_id"?: int, "auto_provision"?: bool } — both optional,
     * at least one required.
     *
     * This route was missing entirely, which made `default_role_id` and
     * `auto_provision` write-once: the only way to tighten a policy after the
     * fact was to DELETE the domain and register it again. That is a different
     * operation with different consequences — it discards the ownership proof
     * and the continuity of the record — for what an administrator experiences
     * as an edit.
     *
     * @param array<string, string> $params
     */
    public function update(Request $request, array $params): Response
    {
        $tenantId = TenantContext::getTenantId();
        if ($tenantId === null) {
            return Response::error('Tenant context is required', 400);
        }

        $id = (int) ($params['id'] ?? 0);
        if ($id <= 0) {
            return Response::error('A domain id is required', 422);
        }

        // Read first: a caller who cannot see this row must get the same 404 a
        // non-existent id gets, rather than learning it exists from a different
        // error further down.
        if ($this->repo->findById($id, $tenantId) === null) {
            return Response::error('Domain not found', 404);
        }

        $body = JsonBody::parsed($request);

        $defaultRoleId = null;
        if (array_key_exists('default_role_id', $body)) {
            $defaultRoleId = (int) $body['default_role_id'];
            if ($defaultRoleId <= 0) {
                return Response::error('default_role_id must be a positive integer', 422);
            }
            if (!$this->assignableRole->isAssignable($defaultRoleId, $tenantId)) {
                return Response::error(self::FOREIGN_ROLE_MESSAGE, 422);
            }
        }

        $autoProvision = null;
        if (array_key_exists('auto_provision', $body)) {
            $autoProvision = (bool) $body['auto_provision'];
        }

        if ($defaultRoleId === null && $autoProvision === null) {
            return Response::error('Provide default_role_id, auto_provision, or both', 422);
        }

        $this->repo->updateSettings($id, $tenantId, $defaultRoleId, $autoProvision);

        $row = $this->repo->findById($id, $tenantId);

        return Response::json(['data' => $this->withChallenge($row)]);
    }

    /**
     * POST /api/email-domains/{id}/verify — prove ownership via the DNS TXT
     * challenge. On success marks the domain verified (which is what enables
     * auto-provisioning). Idempotent: re-verifying an already-verified domain
     * returns it unchanged.
     *
     * @param array<string, string> $params
     */
    public function verify(Request $request, array $params): Response
    {
        $tenantId = TenantContext::getTenantId();
        if ($tenantId === null) {
            return Response::error('Tenant context is required', 400);
        }

        $id = (int) ($params['id'] ?? 0);
        if ($id <= 0) {
            return Response::error('Invalid id', 400);
        }

        $row = $this->repo->findById($id, $tenantId);
        if ($row === null) {
            return Response::error('Domain registration not found', 404);
        }
        if (($row['verified_at'] ?? null) !== null) {
            return Response::json(['data' => $this->withChallenge($row)]);
        }

        // Ensure a token exists (rows predating ownership verification have none),
        // then check DNS for the challenge record.
        $token = $this->repo->ensureToken($id, $tenantId);
        if ($token === null) {
            return Response::error('Domain registration not found', 404);
        }

        $domain = (string) $row['domain'];
        if (!$this->verifier->isVerified($domain, $token)) {
            // Not proven — tell the admin exactly what to publish (no internal error).
            return Response::json([
                'error'        => 'Domain ownership not verified. Publish the TXT record below, then try again.',
                'verification' => $this->challengeFor($domain, $token),
            ], 422);
        }

        $this->repo->markVerified($id, $tenantId);
        $updated = $this->repo->findById($id, $tenantId);
        return Response::json(['data' => $this->withChallenge($updated)]);
    }

    /**
     * DELETE /api/email-domains/{id} — remove a domain registration.
     *
     * @param array<string, string> $params
     */
    public function delete(Request $request, array $params): Response
    {
        $tenantId = TenantContext::getTenantId();
        if ($tenantId === null) {
            return Response::error('Tenant context is required', 400);
        }

        $id = (int) ($params['id'] ?? 0);
        if ($id <= 0) {
            return Response::error('Invalid id', 400);
        }

        $affected = $this->repo->delete($id, $tenantId);
        if ($affected === 0) {
            return Response::error('Domain registration not found', 404);
        }

        return Response::json([], 204);
    }

    /**
     * Attach the DNS challenge instructions to a domain row so the admin UI can
     * show "add this TXT record" for a not-yet-verified domain. The verification
     * token is only ever exposed to the domain's own tenant admin (tenant-scoped
     * reads), so surfacing it here is safe.
     *
     * @param array<string, mixed>|null $row
     * @return array<string, mixed>|null
     */
    private function withChallenge(?array $row): ?array
    {
        if ($row === null) {
            return null;
        }
        $token = isset($row['verification_token']) && is_string($row['verification_token'])
            ? $row['verification_token']
            : null;
        if ($token !== null && $token !== '') {
            $row['verification'] = $this->challengeFor((string) $row['domain'], $token);
        }
        // Never leak the raw token in list/create payloads beyond the challenge value.
        unset($row['verification_token']);
        return $row;
    }

    /**
     * @return array{record_name: string, record_type: string, record_value: string}
     */
    private function challengeFor(string $domain, string $token): array
    {
        return [
            'record_name'  => DomainOwnershipVerifier::challengeHost($domain),
            'record_type'  => 'TXT',
            'record_value' => DomainOwnershipVerifier::challengeValue($token),
        ];
    }

    private function isValidDomain(string $domain): bool
    {
        // Reject anything with a scheme or path separator.
        if (str_contains($domain, '/') || str_contains($domain, ':')) {
            return false;
        }
        // Must contain at least one dot.
        if (!str_contains($domain, '.')) {
            return false;
        }
        return (bool) filter_var('http://' . $domain, FILTER_VALIDATE_URL);
    }
}
