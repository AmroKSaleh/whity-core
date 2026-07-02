<?php

declare(strict_types=1);

namespace Whity\Core\RateLimit;

use Whity\Sdk\Http\Request;

/**
 * Trusted client-IP extraction (WC-b19ff21a).
 *
 * The client IP is read ONLY from {@see self::HEADER}, an INTERNAL header set by
 * the platform's single trusted front proxy (the Next.js API proxy). That proxy
 * derives the real client IP from its own ingress, sets this header on the
 * request it forwards to the backend, and STRIPS any client-supplied copy of it
 * (along with raw `X-Forwarded-For` / `X-Real-IP`) so a browser can neither
 * preset it nor smuggle a spoofed forwarding header through.
 *
 * Consequently, raw `X-Forwarded-For` / `X-Real-IP` are deliberately NOT trusted
 * here: before WC-b19ff21a they were, which let a caller spoof the rate-limit key
 * and poison audit IPs (the Next.js proxy forwards arbitrary client headers
 * verbatim). The result is capped at the IPv6 textual maximum (45 chars) so it is
 * safe as a bounded counter-key segment / audit column value.
 *
 * TRUST ASSUMPTION: the backend (FrankenPHP) is reachable ONLY through the
 * trusted proxy (network isolation) — an attacker with direct network access to
 * the backend port could set this header themselves. That isolation is a
 * deployment/network-policy guarantee, the same class as "the database is not
 * internet-exposed". How the proxy itself derives the client IP (and how many
 * upstream hops it trusts) is configured at the proxy via TRUSTED_PROXY_HOPS.
 */
final class ClientIp
{
    /**
     * Internal, proxy-set header carrying the trusted client IP.
     *
     * Set by the Next.js API proxy; stripped by it from inbound client requests.
     */
    public const HEADER = 'X-Whity-Client-Ip';

    private const MAX_LENGTH = 45;

    public static function fromRequest(Request $request): ?string
    {
        $ip = $request->getHeader(self::HEADER);
        if (!is_string($ip)) {
            return null;
        }

        $ip = trim($ip);
        if ($ip === '') {
            return null;
        }

        return substr($ip, 0, self::MAX_LENGTH);
    }
}
