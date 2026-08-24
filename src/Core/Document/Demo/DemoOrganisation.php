<?php

declare(strict_types=1);

namespace Whity\Core\Document\Demo;

use RuntimeException;

/**
 * The resolved ids of the demo organisation — units, roles and people — keyed by
 * the stable names {@see DemoOrganisationSeeder} declares them under.
 *
 * WHY IDS TRAVEL IN AN OBJECT AND NOT AS THREE ARRAYS
 * ---------------------------------------------------
 * {@see DocumentDemoSeeder} names a unit ~20 times: as a template placement, as
 * the expected origin of a document, as the anchor a routing rule resolves
 * from. With three bare `array<string, int>` parameters, a typo in one of those
 * keys is a PHP notice and a `null` that becomes an UNPLACED template or a
 * document with no origin unit — a demo that quietly stops discriminating,
 * which is the single failure mode a demo dataset must not have. The accessors
 * below throw instead, so a renamed key fails the seed rather than flattening
 * it.
 *
 * A plain enum of unit keys was the alternative and buys nothing here: the keys
 * are internal to these two classes, and an enum would still have to be mapped
 * to database ids by exactly this object.
 */
final class DemoOrganisation
{
    /**
     * @param array<string, int> $ouIds      Unit key => `organizational_units.id`.
     * @param array<string, int> $roleIds    Role key => `roles.id`.
     * @param array<string, int> $profileIds Person key (their email) => `profiles.id`.
     */
    public function __construct(
        public readonly int $tenantId,
        private readonly array $ouIds,
        private readonly array $roleIds,
        private readonly array $profileIds,
    ) {
    }

    public function ou(string $key): int
    {
        return $this->must($this->ouIds, $key, 'organizational unit');
    }

    public function role(string $key): int
    {
        return $this->must($this->roleIds, $key, 'role');
    }

    public function person(string $key): int
    {
        return $this->must($this->profileIds, $key, 'person');
    }

    /**
     * Every unit / role / person the fixture resolved, so a caller can report
     * what is there without re-deriving it from the constants that produced it.
     *
     * Counted rather than written into the message as a literal: a report that
     * says "4 roles" because somebody typed 4 is a report that becomes wrong the
     * first time a role is added, and it becomes wrong SILENTLY — which is
     * exactly what the seed is meant to stop happening to the reader.
     *
     * @return list<string>
     */
    public function units(): array
    {
        return array_keys($this->ouIds);
    }

    /** @return list<string> */
    public function roles(): array
    {
        return array_keys($this->roleIds);
    }

    /** @return list<string> */
    public function people(): array
    {
        return array_keys($this->profileIds);
    }

    /**
     * @param array<string, int> $map
     */
    private function must(array $map, string $key, string $what): int
    {
        if (!isset($map[$key])) {
            // Loud, and it names the alternatives: the caller is seeder code
            // whose author has the correct key in front of them, and a demo that
            // silently placed a template nowhere would still complete and still
            // look plausible on screen.
            throw new RuntimeException(sprintf(
                'The demo organisation has no %s called "%s"; it has: %s.',
                $what,
                $key,
                implode(', ', array_keys($map)) ?: '(none)',
            ));
        }

        return $map[$key];
    }
}
