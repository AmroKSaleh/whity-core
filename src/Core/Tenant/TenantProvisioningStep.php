<?php

declare(strict_types=1);

namespace Whity\Core\Tenant;

/**
 * One thing CORE does to every tenant at creation, whoever created it.
 *
 * The distinction this interface draws is against the `tenant.created` hook, and
 * it is the distinction #1012 was about. A hook listener is registered at an
 * ENTRY POINT — `public/index.php` for requests — so it fires for tenants
 * created by a request and for nothing else. That is right for a PLUGIN, which
 * only exists inside a booted application. It is wrong for anything core
 * considers part of what a tenant IS, because a tenant created by the CLI seeder
 * (which never reaches that bootstrap) then silently does not get it, and the
 * one tenant every fresh install actually opens is exactly that one.
 *
 * So: anything a tenant must have to be a working tenant is a step here, listed
 * once in {@see TenantProvisioner::coreSteps()} and therefore run by every
 * creation path. Anything OPTIONAL, or contributed from outside core, stays a
 * `tenant.created` listener.
 *
 * Steps must be IDEMPOTENT. {@see TenantProvisioner::findOrCreate()} runs them
 * against a tenant that already exists, deliberately — that is how an install
 * predating a step acquires it on its next `seed` rather than having to be
 * rebuilt.
 */
interface TenantProvisioningStep
{
    /**
     * Provision one tenant. Called with a tenant that certainly exists.
     *
     * Implementations MUST NOT throw: a step is a side effect of tenant
     * creation, and tenant creation must not fail with it (the same
     * write-then-swallow policy {@see \Whity\Core\Audit\AuditLogger} and
     * {@see \Whity\Core\Document\DocumentStarterSeeder} already state).
     */
    public function provisionTenant(int $tenantId, string $tenantName): void;
}
