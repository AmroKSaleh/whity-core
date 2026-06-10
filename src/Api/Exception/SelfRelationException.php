<?php

declare(strict_types=1);

namespace Whity\Api\Exception;

use RuntimeException;

/**
 * Raised when a relation would connect a person to itself (WC-65).
 *
 * A person cannot be their own parent/child/spouse/sibling. The
 * {@see \Whity\Core\Relations\RelationResolver} raises this when the resolved
 * `from` and `to` person ids are equal, and {@see \Whity\Api\RelationsApiHandler}
 * translates it into a safe 422 — the request is well-formed but semantically
 * invalid and no row is written.
 */
class SelfRelationException extends RuntimeException
{
    /**
     * Build an exception for a rejected self-relation.
     *
     * @param int $personId The person referenced as both endpoints.
     * @return self
     */
    public static function forPerson(int $personId): self
    {
        return new self("A person cannot be related to itself (person {$personId}).");
    }
}
