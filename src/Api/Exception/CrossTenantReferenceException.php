<?php

declare(strict_types=1);

namespace Whity\Api\Exception;

use RuntimeException;

/**
 * Raised when a relation references a person/user that belongs to a different
 * tenant than the acting one (WC-65).
 *
 * Both endpoints of a relation must live in the acting tenant. Rather than
 * disclosing that a person exists elsewhere, the resolver treats a cross-tenant
 * reference as not-found: {@see \Whity\Api\RelationsApiHandler} translates this
 * into a 404, indistinguishable from a genuinely missing person. This exists as
 * a distinct type (separate from {@see PersonNotFoundException}) so cross-tenant
 * attempts can be logged for security visibility while still responding 404.
 */
class CrossTenantReferenceException extends RuntimeException
{
    /**
     * Build an exception for a person referenced from the wrong tenant.
     *
     * @param int $personId    The referenced person id.
     * @param int $ownerTenant The tenant the person actually belongs to.
     * @param int $actingTenant The acting tenant that made the reference.
     * @return self
     */
    public static function forPerson(int $personId, int $ownerTenant, int $actingTenant): self
    {
        return new self(
            "Person {$personId} belongs to tenant {$ownerTenant}, "
            . "not the acting tenant {$actingTenant}."
        );
    }
}
