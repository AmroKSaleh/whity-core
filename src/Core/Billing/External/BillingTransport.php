<?php

declare(strict_types=1);

namespace Whity\Core\Billing\External;

/**
 * The narrowest possible HTTP seam, so the portal can be tested without a network.
 *
 * WHY NOT {@see \Whity\Core\Http\HttpClient}: that interface answers `null` for
 * every failure, which erases the one distinction this integration cannot lose —
 * *unreachable* versus *refused*. A timeout and a "your key is invalid" would
 * arrive identically, and the caller would have to guess. Guessing wrong in one
 * direction locks out paying customers during an outage; in the other it grants
 * access to everyone the moment a key is revoked.
 *
 * So this returns the status and the body and lets the portal decide, and throws
 * only for the case where there is no answer at all.
 */
interface BillingTransport
{
    /**
     * @param array<string, string> $headers
     * @param string|null           $body    Raw request body, already encoded.
     *
     * @return array{status: int, body: string}
     *
     * @throws BillingPortalException Transport failure only — a connection that
     *         could not be made, or one that did not answer in time. An HTTP
     *         error status is a successful transport and comes back as a status.
     */
    public function send(string $method, string $url, array $headers, ?string $body = null): array;
}
