<?php

declare(strict_types=1);

namespace Whity\Sdk\Expression;

/**
 * What an expression may read (SDK 1.47, WHIT-759): the record's fields and
 * state, the acting person's roles, and the time.
 *
 * Nothing else is reachable, by construction. There is no reference operator
 * for a table, a setting, another record or the network, so an expression
 * can't be written that leaks or fetches anything; the closed vocabulary IS
 * the sandbox.
 *
 * `today` and `now` are passed in rather than read from the clock, so a rule
 * evaluates the same way in a preview, a test and the live save path, and a
 * test can pin the date.
 */
final class ExpressionContext
{
    /**
     * @param array<string, mixed> $fields Field name => value. Money is in minor
     *        units (JOD has 3 decimals, so 12.500 JOD is 12500). Dates are
     *        `YYYY-MM-DD`. A field holding lines is a list of objects.
     * @param list<string> $roles The acting person's role names.
     * @param string $today `YYYY-MM-DD`, in the workspace's time zone.
     * @param string $now ISO 8601 in UTC, e.g. `2026-10-31T09:30:00Z`.
     */
    public function __construct(
        public readonly array $fields = [],
        public readonly ?string $state = null,
        public readonly array $roles = [],
        public readonly string $today = '1970-01-01',
        public readonly string $now = '1970-01-01T00:00:00Z',
    ) {
    }

    /**
     * @param array<string, mixed> $data Keys `fields`, `state`, `roles`,
     *        `today`, `now`, as the shared fixtures write them.
     */
    public static function fromArray(array $data): self
    {
        /** @var array<string, mixed> $fields */
        $fields = is_array($data['fields'] ?? null) ? $data['fields'] : [];
        /** @var list<string> $roles */
        $roles = is_array($data['roles'] ?? null) ? array_values($data['roles']) : [];

        return new self(
            $fields,
            isset($data['state']) && is_string($data['state']) ? $data['state'] : null,
            $roles,
            is_string($data['today'] ?? null) ? $data['today'] : '1970-01-01',
            is_string($data['now'] ?? null) ? $data['now'] : '1970-01-01T00:00:00Z',
        );
    }
}
