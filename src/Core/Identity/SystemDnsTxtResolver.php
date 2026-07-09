<?php

declare(strict_types=1);

namespace Whity\Core\Identity;

/**
 * Real {@see DnsTxtResolver} backed by the system resolver (`dns_get_record`).
 *
 * Ownership verification reads only TXT records at a fixed `_whity-verify.<domain>`
 * label and compares them to a server-issued token, so this lookup carries no
 * SSRF risk (no attacker-controlled URL, no HTTP) — a malicious domain can at
 * most publish TXT records for a domain it already controls.
 */
final class SystemDnsTxtResolver implements DnsTxtResolver
{
    public function txtRecords(string $host): array
    {
        // Suppress the native warning on NXDOMAIN / lookup failure; a false/empty
        // result simply means "no proof present".
        $records = @dns_get_record($host, DNS_TXT);
        if ($records === false || $records === []) {
            return [];
        }

        $out = [];
        foreach ($records as $record) {
            if (isset($record['txt']) && is_string($record['txt'])) {
                $out[] = $record['txt'];
                continue;
            }
            // Some resolvers split long records into an 'entries' array.
            if (isset($record['entries']) && is_array($record['entries'])) {
                $out[] = implode('', array_map(static fn($e): string => (string) $e, $record['entries']));
            }
        }
        return $out;
    }
}
