<?php

declare(strict_types=1);

namespace Whity\Api\Exception;

use RuntimeException;

/**
 * Raised when a referenced person (or the user whose shadow person was being
 * resolved) cannot be found in the acting tenant (WC-65).
 *
 * Used by {@see \Whity\Core\Relations\RelationResolver} when a `{kind,id}`
 * reference resolves to nothing visible to the tenant.
 * {@see \Whity\Api\RelationsApiHandler} / {@see \Whity\Api\PersonsApiHandler}
 * translate it into a 404 so a person/user's existence in another tenant is
 * never disclosed (it is indistinguishable from "does not exist").
 */
class PersonNotFoundException extends RuntimeException
{
    /**
     * Build an exception for a person id that is absent/not visible.
     *
     * @param int $personId The person id that could not be resolved.
     * @return self
     */
    public static function forPerson(int $personId): self
    {
        return new self("Person {$personId} was not found.");
    }

    /**
     * Build an exception for a user id whose shadow person could not be resolved
     * (the user does not exist or is not visible to the acting tenant).
     *
     * @param int $userId The user id that could not be resolved to a person.
     * @return self
     */
    public static function forUser(int $userId): self
    {
        return new self("User {$userId} was not found.");
    }
}
