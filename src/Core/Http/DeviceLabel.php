<?php

declare(strict_types=1);

namespace Whity\Core\Http;

/**
 * Derives a human-friendly device label ("Chrome on Windows") from a raw
 * User-Agent string, for the sessions/devices UI (WC-b3330495).
 *
 * Best-effort and deliberately coarse: the classic UA cannot reliably express
 * fine-grained versions or distinguish Windows 10 from 11 (both report
 * "Windows NT 10.0" — that needs UA Client Hints, a separate enhancement). The
 * goal is a recognisable label, not forensic precision.
 *
 * Pure/deterministic; no request state.
 */
final class DeviceLabel
{
    /**
     * @return string A label like "Chrome on Windows", "Safari on iOS", the OS or
     *   browser alone when only one is known, or "Unknown device".
     */
    public static function fromUserAgent(?string $userAgent): string
    {
        $ua = trim((string) $userAgent);
        if ($ua === '') {
            return 'Unknown device';
        }

        $browser = self::browser($ua);
        $os = self::os($ua);

        if ($browser !== null && $os !== null) {
            return "{$browser} on {$os}";
        }
        return $browser ?? $os ?? 'Unknown device';
    }

    private static function browser(string $ua): ?string
    {
        // Order matters: Edge/Opera/Brave UAs also contain "Chrome"; Chrome's UA
        // also contains "Safari" — check the more specific tokens first.
        return match (true) {
            str_contains($ua, 'Edg/') || str_contains($ua, 'Edge/') => 'Edge',
            str_contains($ua, 'OPR/') || str_contains($ua, 'Opera') => 'Opera',
            str_contains($ua, 'Brave/')                             => 'Brave',
            str_contains($ua, 'Firefox/') || str_contains($ua, 'FxiOS/') => 'Firefox',
            str_contains($ua, 'CriOS/')                             => 'Chrome',
            str_contains($ua, 'Chrome/') || str_contains($ua, 'Chromium/') => 'Chrome',
            str_contains($ua, 'Safari/')                            => 'Safari',
            str_contains($ua, 'curl/')                              => 'curl',
            str_contains($ua, 'PostmanRuntime')                     => 'Postman',
            default                                                 => null,
        };
    }

    private static function os(string $ua): ?string
    {
        return match (true) {
            str_contains($ua, 'Windows NT')                          => 'Windows',
            str_contains($ua, 'iPhone') || str_contains($ua, 'iPad') || str_contains($ua, 'iOS') => 'iOS',
            // "Mac OS X" appears in iOS UAs too, so iOS is checked first above.
            str_contains($ua, 'Mac OS X') || str_contains($ua, 'Macintosh') => 'macOS',
            str_contains($ua, 'Android')                             => 'Android',
            str_contains($ua, 'CrOS')                                => 'ChromeOS',
            str_contains($ua, 'Linux')                               => 'Linux',
            default                                                  => null,
        };
    }
}
