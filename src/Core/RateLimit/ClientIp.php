<?php

declare(strict_types=1);

namespace Whity\Core\RateLimit;

use Whity\Sdk\Http\Request;

/**
 * Best-effort client-IP extraction from forwarding headers (WC-c0fb3700).
 *
 * Centralises the logic also used for audit (see EnforceTenantIsolation /
 * AuthHandler): prefer the first hop in `X-Forwarded-For`, then `X-Real-IP`.
 * Returns null when neither header carries a value. The result is capped at the
 * IPv6 textual maximum (45 chars) so it is safe as a bounded counter-key segment.
 *
 * NOTE: this trusts the reverse proxy to set the forwarding headers. The
 * deployment's Caddy/Next.js front door is the only thing that should be able to
 * reach FrankenPHP, so the first X-Forwarded-For hop is the real client.
 */
final class ClientIp
{
    private const MAX_LENGTH = 45;

    public static function fromRequest(Request $request): ?string
    {
        $forwarded = $request->getHeader('X-Forwarded-For');
        if (is_string($forwarded) && $forwarded !== '') {
            $first = trim(explode(',', $forwarded)[0]);
            if ($first !== '') {
                return substr($first, 0, self::MAX_LENGTH);
            }
        }

        $realIp = $request->getHeader('X-Real-IP');
        if (is_string($realIp) && trim($realIp) !== '') {
            return substr(trim($realIp), 0, self::MAX_LENGTH);
        }

        return null;
    }
}
