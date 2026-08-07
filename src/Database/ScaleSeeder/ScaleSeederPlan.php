<?php

declare(strict_types=1);

namespace Whity\Database\ScaleSeeder;

/**
 * Computed row-count plan for a {@see ScaleSeederConfig}, used by `--dry-run`
 * and printed before a real run so an operator sees the shape of what they
 * are about to bulk-insert before it happens. Pure arithmetic — no DB access.
 */
final class ScaleSeederPlan
{
    public function __construct(
        public readonly int $tenants,
        public readonly int $ousPerTenant,
        public readonly int $totalOus,
        public readonly int $customRolesPerTenant,
        public readonly int $totalCustomRoles,
        public readonly int $usersPerTenant,
        public readonly int $totalUsers,
        public readonly int $totalPersons,
        public readonly int $relationsPerTenant,
        public readonly int $totalRelations,
    ) {
    }

    public static function fromConfig(ScaleSeederConfig $config): self
    {
        $ousPerTenant = $config->ousPerTenant();
        $relationsPerTenant = $config->relationsPerTenant($config->usersPerTenant);

        return new self(
            tenants: $config->tenants,
            ousPerTenant: $ousPerTenant,
            totalOus: $ousPerTenant * $config->tenants,
            customRolesPerTenant: $config->customRolesPerTenant,
            totalCustomRoles: $config->customRolesPerTenant * $config->tenants,
            usersPerTenant: $config->usersPerTenant,
            totalUsers: $config->usersPerTenant * $config->tenants,
            totalPersons: $config->usersPerTenant * $config->tenants,
            relationsPerTenant: $relationsPerTenant,
            totalRelations: $relationsPerTenant * $config->tenants,
        );
    }

    /** Grand total of rows this plan would insert (upper bound; reruns dedupe via ON CONFLICT). */
    public function totalRows(): int
    {
        return $this->tenants
            + $this->totalOus
            + $this->totalCustomRoles
            + $this->totalUsers // profiles
            + $this->totalUsers // profile_emails
            + $this->totalUsers // memberships
            + $this->totalPersons
            + $this->totalRelations;
    }
}
