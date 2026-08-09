<?php

declare(strict_types=1);

namespace Whity\Core\Tenant;

use InvalidArgumentException;

/**
 * Raised when a table-ownership declaration cannot be accepted by
 * {@see TableOwnershipRegistry} (WC-723).
 *
 * Every case is a REFUSAL of the whole declaration, never a partial accept: a
 * plugin whose declaration is rejected owns nothing, so ownership can never
 * depend on how far iteration got before the bad entry.
 */
class TableOwnershipException extends InvalidArgumentException
{
    /**
     * A table name that is not a usable SQL identifier.
     *
     * @param string $table The offending table name.
     */
    public static function forTableName(string $table): self
    {
        return new self(
            "Invalid table name '{$table}': expected lowercase letters, digits and "
            . 'underscores, starting with a letter, at most 63 characters'
        );
    }

    /**
     * A scope value outside the declared vocabulary.
     *
     * @param string $table The table whose scope was malformed.
     * @param string $scope The offending scope value.
     */
    public static function forScope(string $table, string $scope): self
    {
        return new self(
            "Invalid scope '{$scope}' for table '{$table}': expected 'tenant' or 'global'"
        );
    }

    /**
     * A source name from which no usable slug can be derived, so its claims
     * could not be compared against anyone else's.
     *
     * @param string $source The unusable source name.
     */
    public static function forSource(string $source): self
    {
        return new self(
            "Source '{$source}' yields no usable slug; a table-ownership source "
            . 'must start with a letter'
        );
    }

    /**
     * A caller other than core claimed the reserved `core` source, which would
     * let it pass itself off as the host and claim core's own tables.
     *
     * @param string $source The reserved source that was claimed.
     */
    public static function forReservedSource(string $source): self
    {
        return new self(
            "Source '{$source}' is reserved for whity-core's own tables; a plugin "
            . 'is attributed under its own plugin name'
        );
    }

    /**
     * A table already claimed by somebody else.
     *
     * This is the case that matters: it is what stops a plugin from claiming
     * `users` (or another plugin's table) and then declaring a referential guard
     * over data it cannot otherwise read. The claimant gets nothing — not the
     * contested table, and not the rest of its declaration.
     *
     * @param string $table    The contested table.
     * @param string $owner    The source that already owns it.
     * @param string $claimant The source attempting to claim it.
     */
    public static function forConflict(string $table, string $owner, string $claimant): self
    {
        return new self(
            "Table '{$table}' is already owned by '{$owner}'; '{$claimant}' cannot claim it"
        );
    }
}
