<?php

declare(strict_types=1);

namespace Whity\Core\Billing\External;

/**
 * The real network, via curl.
 *
 * Small on purpose: every decision that could be made wrong lives in the portal
 * above it, where it can be tested. This does one thing — turn a request into a
 * status and a body, or into a transient failure.
 *
 * BOUNDED, BECAUSE AN UNBOUNDED WAIT IS AN OUTAGE. A billing service that hangs
 * must not hang the request that asked it. The timeout is a constructor
 * argument rather than a constant so a deployment can tune it, and it is applied
 * to the whole exchange, not just the connect.
 */
final class CurlBillingTransport implements BillingTransport
{
    public function __construct(
        private readonly int $timeoutSeconds = 10,
        private readonly int $connectTimeoutSeconds = 5,
    ) {
    }

    /**
     * @param array<string, string> $headers
     *
     * @return array{status: int, body: string}
     */
    public function send(string $method, string $url, array $headers, ?string $body = null): array
    {
        $handle = curl_init();
        if ($handle === false) {
            throw BillingPortalException::unreachable('could not initialise an HTTP client');
        }

        $formatted = [];
        foreach ($headers as $name => $value) {
            $formatted[] = $name . ': ' . $value;
        }

        curl_setopt_array($handle, [
            CURLOPT_URL => $url,
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => $formatted,
            CURLOPT_TIMEOUT => $this->timeoutSeconds,
            CURLOPT_CONNECTTIMEOUT => $this->connectTimeoutSeconds,
            // A redirect from a billing service is not something to follow
            // blindly: the credential travels in a header, and following a 302
            // would hand it to wherever that 302 pointed.
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
        ]);

        if ($body !== null) {
            curl_setopt($handle, CURLOPT_POSTFIELDS, $body);
        }

        $response = curl_exec($handle);
        $status = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
        $error = curl_error($handle);
        curl_close($handle);

        if ($response === false || $status === 0) {
            // No answer at all. The caller must treat this as "I do not know",
            // never as "no" — see BillingPortalException::isTransient().
            //
            // The curl message is kept for an operator's log and never reaches a
            // client: it can name the host and the failure, which is exactly
            // what makes it useful here and unsafe there.
            throw BillingPortalException::unreachable(
                $error !== '' ? $error : 'the billing service did not answer'
            );
        }

        return ['status' => $status, 'body' => is_string($response) ? $response : ''];
    }
}
