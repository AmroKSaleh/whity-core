<?php

declare(strict_types=1);

namespace Whity\Core\Audit;

use InvalidArgumentException;

/**
 * Raised when a plugin's audited-event declaration does not conform to what
 * {@see PluginAuditEvents::fromDeclaration()} accepts.
 *
 * A valid declared event name is lowercase, starts with a letter, and continues
 * with letters, digits, underscores or dots — `task.completed`, the shape core's
 * own `role.created` already uses. It carries NO colon: the colon is the
 * namespace separator the host applies, so allowing one in a declared name would
 * let a plugin write its own prefix and file its activity under another plugin's
 * name.
 *
 * Every refusal here is WHOLE-declaration. A plugin that got half of its events
 * subscribed would ship an audit trail that looks complete and silently omits
 * some of its actions — strictly more dangerous than one that is empty, because
 * only the empty one is obviously empty.
 */
class InvalidPluginAuditEventException extends InvalidArgumentException
{
    /** A declared event name that failed format validation. */
    public static function forEventName(string $name): self
    {
        return new self(
            "Invalid plugin audit event name '{$name}': expected lowercase 'event' or "
            . "'event.name' format (letters, digits, underscores, dots; no colon)"
        );
    }

    /**
     * A source name from which no usable namespace prefix could be derived, so
     * its audit actions would be recorded unprefixed and indistinguishable from
     * core's own.
     */
    public static function forSource(string $source): self
    {
        return new self(
            "Source '{$source}' yields no usable namespace prefix; a plugin audit-event "
            . 'source must start with a letter'
        );
    }

    /** Something other than a descriptor array appeared against an event name. */
    public static function forDescriptor(string $name, mixed $descriptor): self
    {
        return new self(
            "Plugin audit event '{$name}' must be declared as an array "
            . "{targetType: string, idKey: string|null}, got " . get_debug_type($descriptor)
        );
    }

    /**
     * A descriptor missing `targetType`, or naming one that is not a usable
     * entity type.
     *
     * Not defaulted to the event's own first segment: a guessed target type is
     * unfalsifiable in review and wrong in the ordinary case where the event is
     * `task.completed` but the record is an `assignment`.
     */
    public static function forTargetType(string $name, mixed $targetType): self
    {
        return new self(
            "Plugin audit event '{$name}' declares an invalid targetType "
            . '(' . (is_string($targetType) ? "'{$targetType}'" : get_debug_type($targetType)) . '): '
            . 'expected a lowercase entity type starting with a letter (letters, digits, underscores; no colon)'
        );
    }

    /**
     * A descriptor whose `idKey` is absent or is neither a non-empty string nor
     * an explicit null.
     *
     * Absence is refused rather than defaulted to `id`: `id` is right for core's
     * payloads and wrong for most plugin ones, and the mistake produces a row
     * that names an action and points at nothing while the write still succeeds
     * — an audit trail that is quietly useless rather than visibly broken.
     */
    public static function forIdKey(string $name, mixed $idKey): self
    {
        return new self(
            "Plugin audit event '{$name}' must declare an idKey: the payload key holding the "
            . 'affected record id, or an explicit null for an event with no single target. Got '
            . (is_string($idKey) ? "'{$idKey}'" : get_debug_type($idKey))
        );
    }

    /**
     * The namespaced action or target type is wider than its `audit_log` column,
     * so every row this event produced would be truncated or refused by the
     * database — an event that is declared audited and is not.
     */
    public static function forOversizedName(string $name, int $limit): self
    {
        return new self(
            "Plugin audit event name '{$name}' is longer than {$limit} characters once namespaced, "
            . 'so it could never be written to audit_log'
        );
    }
}
