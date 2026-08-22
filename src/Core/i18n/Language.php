<?php

declare(strict_types=1);

namespace Whity\Core\i18n;

/**
 * Domain model for a language.
 *
 * Represents a single language with its code, name, writing direction, and
 * enabled status. Language codes are globally unique and available to all
 * tenants.
 *
 * DIRECTION IS A PROPERTY OF THE LANGUAGE, not a separate preference: choosing
 * Arabic IS choosing right-to-left. It is carried on the record (migration 090)
 * so adding Hebrew, Farsi or Urdu is one row through the admin API rather than
 * a new branch in the interface — nothing in this codebase tests a language
 * CODE to decide direction.
 */
use Whity\Core\Db\DbBool;
final class Language
{
    /** Left-to-right — the direction assumed for any language that omits one. */
    public const DIRECTION_LTR = 'ltr';

    /** Right-to-left. */
    public const DIRECTION_RTL = 'rtl';

    /**
     * The directions a language may declare. These are exactly the values the
     * HTML `dir` attribute accepts for a whole document; `auto` is excluded
     * because it resolves per text node, which is the wrong granularity for an
     * interface (see migration 090).
     */
    public const DIRECTIONS = [self::DIRECTION_LTR, self::DIRECTION_RTL];

    public function __construct(
        public readonly int $id,
        public readonly string $code,
        public readonly string $name,
        public readonly bool $enabled,
        public readonly string $createdAt,
        public readonly string $updatedAt,
        public readonly string $direction = self::DIRECTION_LTR,
    ) {
    }

    /**
     * Create a Language from a database row.
     *
     * `direction` is read defensively: a row read back through a schema that
     * predates migration 090 (or a projection that did not select the column)
     * is left-to-right rather than fatal.
     *
     * @param array<string, mixed> $row The database row.
     * @return self
     */
    public static function fromRow(array $row): self
    {
        return new self(
            id: (int) $row['id'],
            code: (string) $row['code'],
            name: (string) $row['name'],
            enabled: DbBool::of($row['enabled']),
            createdAt: (string) $row['created_at'],
            updatedAt: (string) $row['updated_at'],
            direction: self::normalizeDirection($row['direction'] ?? null),
        );
    }

    /**
     * Coerce an arbitrary value to a supported direction, defaulting to
     * left-to-right. The database CHECK constraint is the real guard; this
     * keeps a legacy or hand-edited row from producing an invalid `dir`.
     */
    public static function normalizeDirection(mixed $direction): string
    {
        return is_string($direction) && in_array($direction, self::DIRECTIONS, true)
            ? $direction
            : self::DIRECTION_LTR;
    }
}
