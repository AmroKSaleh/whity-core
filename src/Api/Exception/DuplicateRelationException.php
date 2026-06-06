<?php

declare(strict_types=1);

namespace Whity\Api\Exception;

use RuntimeException;

/**
 * Raised when a relation already exists for the same tenant, person pair and
 * type (WC-65).
 *
 * The same directed edge (`from`, `to`, `relationship_type`) within a tenant may
 * exist only once. {@see \Whity\Core\Relations\RelationResolver} checks for the
 * existing edge up front and raises this; the UNIQUE constraint on `relations`
 * is the backstop. {@see \Whity\Api\RelationsApiHandler} translates it into a
 * safe 422 — no second row is written.
 */
class DuplicateRelationException extends RuntimeException
{
    /**
     * Build an exception for a rejected duplicate relation.
     *
     * @param int $fromPersonId       The edge's from-person id.
     * @param int $toPersonId         The edge's to-person id.
     * @param int $relationshipTypeId The relationship type id.
     * @return self
     */
    public static function forEdge(int $fromPersonId, int $toPersonId, int $relationshipTypeId): self
    {
        return new self(
            "A relation of type {$relationshipTypeId} from person {$fromPersonId} "
            . "to person {$toPersonId} already exists."
        );
    }
}
