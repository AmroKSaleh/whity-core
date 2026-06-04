<?php

declare(strict_types=1);

namespace Whity\Core\RBAC;

use InvalidArgumentException;

/**
 * Raised when a permission string does not conform to the `resource:action`
 * naming pattern required by the RBAC permission model.
 *
 * A valid permission consists of a non-empty resource segment and a non-empty
 * action segment separated by a single colon, e.g. `users:read`.
 */
class InvalidPermissionException extends InvalidArgumentException
{
    /**
     * Create an exception describing a permission that failed format validation.
     *
     * @param string $permission The offending permission string.
     * @return self
     */
    public static function forPermission(string $permission): self
    {
        return new self(
            "Invalid permission '{$permission}': expected 'resource:action' format"
        );
    }
}
