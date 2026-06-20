<?php

declare(strict_types=1);

namespace Whity\Core\Branding;

/**
 * Resolves a request host to a tenant id for PRE-AUTH branding (Tenant
 * Branding). Precedence: exact custom branding_host → slug subdomain of the
 * configured base domain → null (caller falls back to the global default).
 * Display-only — NEVER an auth decision.
 */
final class HostResolver
{
    private string $baseDomain;

    public function __construct(
        private readonly TenantHostRepository $repo,
        string $baseDomain,
    ) {
        $this->baseDomain = strtolower(trim($baseDomain));
    }

    public function resolveTenantIdByHost(string $host): ?int
    {
        $host = strtolower(trim($host));
        if ($host === '') {
            return null;
        }
        // Strip a trailing :port.
        $colon = strpos($host, ':');
        if ($colon !== false) {
            $host = substr($host, 0, $colon);
        }

        // 1) Exact custom-domain match.
        $byHost = $this->repo->findIdByBrandingHost($host);
        if ($byHost !== null) {
            return $byHost;
        }

        // 2) Slug subdomain of the base domain.
        if ($this->baseDomain === '') {
            return null;
        }
        $suffix = '.' . $this->baseDomain;
        if (!str_ends_with($host, $suffix)) {
            return null;
        }
        $slug = substr($host, 0, -strlen($suffix));
        // Only a single left-most label is a slug; reject empty, www, or nested.
        if ($slug === '' || $slug === 'www' || str_contains($slug, '.')) {
            return null;
        }

        return $this->repo->findIdBySlug($slug);
    }
}
