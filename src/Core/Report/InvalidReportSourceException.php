<?php

declare(strict_types=1);

namespace Whity\Core\Report;

/**
 * A report source was declared in a way the registry will not accept
 * (#947 item 6).
 *
 * Thrown at REGISTRATION, which is boot, so a malformed source stops the
 * catalogue being built rather than surfacing later as a report that answers
 * strangely. One named constructor per rejection reason, mirroring
 * {@see \Whity\Core\Inbox\InvalidInboxSourceException}: the reason belongs in
 * the type, not in a string a caller has to parse.
 */
final class InvalidReportSourceException extends \RuntimeException
{
    public static function forSlug(string $key): self
    {
        return new self(
            "Report source key '{$key}' is not a valid slug. Keys must match "
            . '[a-z][a-z0-9_]* — the grammar every registry in the platform uses, '
            . 'so no catalogue accepts a name the others would refuse.'
        );
    }

    public static function forDuplicateKey(string $key): self
    {
        return new self(
            "Report source '{$key}' is already registered. The second registration is "
            . 'refused rather than silently overwriting the first, which would make which '
            . 'report runs depend on boot order.'
        );
    }

    public static function forMissingPermission(string $key): self
    {
        return new self(
            "Report source '{$key}' declares no required permission. A report is a READ of "
            . 'data that is already governed somewhere; a source that cannot name the '
            . 'permission protecting it would be readable by everyone the route admits.'
        );
    }
}
