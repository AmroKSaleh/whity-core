<?php

declare(strict_types=1);

namespace Whity\Core\DataType;

use InvalidArgumentException;

/**
 * Raised when a data-type declaration cannot be accepted by
 * {@see DataTypeRegistry} (WC-723).
 *
 * Rejection is per data type: one malformed entry does not discard the rest of
 * a plugin's declaration, but a malformed entry is never partially accepted —
 * a half-registered type would generate affordances backed by validation that
 * never ran.
 */
class InvalidDataTypeException extends InvalidArgumentException
{
    /**
     * A type slug that is not a bare lowercase identifier.
     *
     * @param string $slug The offending slug.
     */
    public static function forSlug(string $slug): self
    {
        return new self(
            "Invalid data-type slug '{$slug}': expected lowercase letters, digits "
            . 'and underscores, starting with a letter (no colon — the host adds the namespace)'
        );
    }

    /**
     * A source name from which no usable namespace prefix could be derived.
     *
     * @param string $source The unusable source name.
     */
    public static function forSource(string $source): self
    {
        return new self(
            "Source '{$source}' yields no usable namespace prefix; a data-type "
            . 'source must start with a letter'
        );
    }

    /**
     * A declaration that is not an array of settings.
     *
     * @param string $key The type key whose declaration was malformed.
     */
    public static function forMalformedDeclaration(string $key): self
    {
        return new self("Data type '{$key}': the declaration must be an array of settings");
    }

    /**
     * A missing or malformed identifier (table, key column, tenant column, or a
     * guard's table/column).
     *
     * @param string $key   The type key.
     * @param string $field The declaration field at fault.
     * @param string $value The offending value.
     */
    public static function forIdentifier(string $key, string $field, string $value): self
    {
        return new self(
            "Data type '{$key}': '{$field}' must be a valid SQL identifier "
            . "(lowercase, starts with a letter, <= 63 chars); got '{$value}'"
        );
    }

    /**
     * A table the declaring source does not own.
     *
     * THE constraint of Door 2. A guard is "how many rows in that table point at
     * this record?" — an aggregate over data the plugin may have no other way to
     * read. Allowing it over a table the plugin does not own would turn the
     * guard vocabulary into a probe of somebody else's schema, so the host
     * refuses rather than trusting the declaration.
     *
     * @param string      $key   The type key.
     * @param string      $table The table that was declared.
     * @param string      $source The declaring source.
     * @param string|null $owner The actual owner, or null when unclaimed.
     */
    public static function forUnownedTable(string $key, string $table, string $source, ?string $owner): self
    {
        $ownership = $owner === null
            ? 'no source has declared it'
            : "it is owned by '{$owner}'";

        return new self(
            "Data type '{$key}': '{$source}' may not declare table '{$table}' — {$ownership}. "
            . 'Declare the table through PluginTablesInterface first; a guard over a table '
            . 'the plugin does not own is refused.'
        );
    }

    /**
     * A table that is not tenant-scoped.
     *
     * Tenant isolation is non-negotiable, and a generated statement can only
     * bind a tenant predicate against a table that has one. A type over a table
     * declared global is refused rather than served unscoped.
     *
     * @param string $key   The type key.
     * @param string $table The offending table.
     */
    public static function forNonTenantTable(string $key, string $table): self
    {
        return new self(
            "Data type '{$key}': table '{$table}' is not declared tenant-scoped, so no "
            . 'tenant predicate could be bound to the statements generated for it'
        );
    }

    /**
     * A lifecycle declaration that claims a capability without the state that
     * would express it.
     *
     * Loud rather than degraded: silently dropping a `trashable` the plugin
     * asked for would leave it believing records are recoverable when a delete
     * is permanent.
     *
     * @param string $key    The type key.
     * @param string $detail What is missing.
     */
    public static function forLifecycle(string $key, string $detail): self
    {
        return new self("Data type '{$key}': invalid lifecycle declaration — {$detail}");
    }

    /**
     * A permission slug that is not in `resource:action` form, or an action
     * outside the lifecycle vocabulary.
     *
     * @param string $key    The type key.
     * @param string $action The action being gated.
     * @param string $slug   The offending slug.
     */
    public static function forPermission(string $key, string $action, string $slug): self
    {
        return new self(
            "Data type '{$key}': permission for action '{$action}' must be a "
            . "'resource:action' slug and the action must be one of "
            . implode(', ', LifecycleAction::all()) . "; got '{$slug}'"
        );
    }

    /**
     * A guard declaration that is not a well-formed reference edge.
     *
     * @param string $key    The type key.
     * @param string $detail What is wrong.
     */
    public static function forGuard(string $key, string $detail): self
    {
        return new self("Data type '{$key}': invalid blocks_delete entry — {$detail}");
    }

    /**
     * A composition declaration that is not a well-formed ownership edge.
     *
     * Held to a stricter standard than a guard, because the statement it
     * produces is a DELETE rather than a COUNT: an accepted-but-wrong guard
     * over-refuses, which is visible and recoverable, while an accepted-but-wrong
     * cascade removes rows nobody asked it to and reports success.
     *
     * @param string $key    The type key.
     * @param string $detail What is wrong.
     */
    public static function forCascade(string $key, string $detail): self
    {
        return new self("Data type '{$key}': invalid cascade_delete entry — {$detail}");
    }

    /**
     * Two sources (or one source twice) claiming the same canonical key.
     *
     * @param string $key The contested canonical key.
     */
    public static function forDuplicateKey(string $key): self
    {
        return new self("Data type '{$key}' is already registered");
    }
}
