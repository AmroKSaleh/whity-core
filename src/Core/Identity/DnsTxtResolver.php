<?php

declare(strict_types=1);

namespace Whity\Core\Identity;

/**
 * Resolves the TXT records for a host. Abstracted so the DNS lookup can be
 * stubbed in tests (the real implementation hits the network).
 */
interface DnsTxtResolver
{
    /**
     * Return the TXT record strings published for `$host`, or an empty list if
     * none exist or the lookup fails. Implementations MUST NOT throw for an
     * ordinary NXDOMAIN / empty result — return [].
     *
     * @return list<string>
     */
    public function txtRecords(string $host): array;
}
