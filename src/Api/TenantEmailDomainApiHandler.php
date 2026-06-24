<?php

declare(strict_types=1);

namespace Whity\Api;

use PDO;
use Whity\Core\Identity\TenantEmailDomainsRepository;
use Whity\Core\Request;
use Whity\Core\Response;
use Whity\Core\Tenant\TenantContext;
use Whity\Http\JsonBody;

/**
 * Tenant Email-Domain API Handler (WC-9b87).
 *
 * Provides the admin CRUD surface for managing a tenant's email-domain policy
 * registrations. All routes are gated on the `admin` role and tenant-scoped via
 * TenantContext so a tenant can only manage its own domain registrations.
 *
 * Routes (registered in public/index.php):
 *   GET    /api/email-domains            → list()
 *   POST   /api/email-domains            → create()
 *   DELETE /api/email-domains/{id}       → delete()
 */
final class TenantEmailDomainApiHandler
{
    private TenantEmailDomainsRepository $repo;

    public function __construct(PDO $db)
    {
        $this->repo = new TenantEmailDomainsRepository($db);
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

        $rows = $this->repo->listForTenant($tenantId);
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
        return Response::json(['data' => $row], 201);
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
