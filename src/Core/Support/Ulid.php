<?php

declare(strict_types=1);

namespace Whity\Core\Support;

/**
 * Minimal ULID generator — a 128-bit Universally-unique Lexicographically
 * Sortable id rendered as 26 Crockford base32 chars:
 *   [ 48-bit ms timestamp → 10 chars ][ 80 bits randomness → 16 chars ]
 * The time prefix makes ids sort by creation order (ASCII/byte sort == time
 * order), so a `domain_events(tenant_id, id)` index doubles as a time-ordered
 * cursor for change feeds — without exposing a monotonic row count the way a
 * BIGSERIAL would.
 *
 * Hand-rolled (spec: https://github.com/ulid/spec) rather than pulling a
 * composer package, per the project's third-party-dependency policy. Uses
 * random_int() (CSPRNG) for the random component.
 */
final class Ulid
{
    /** Crockford base32 alphabet — no I, L, O, U (avoids transcription ambiguity). */
    private const ALPHABET = '0123456789ABCDEFGHJKMNPQRSTVWXYZ';

    private const TIME_CHARS = 10;
    private const RANDOM_CHARS = 16;

    /**
     * Generate a new ULID. `$timestampMs` is injectable purely so tests can
     * assert time-ordering deterministically; production always uses now.
     */
    public static function generate(?int $timestampMs = null): string
    {
        $timestampMs ??= (int) (microtime(true) * 1000);

        return self::encodeTime($timestampMs) . self::encodeRandom();
    }

    private static function encodeTime(int $ms): string
    {
        $out = '';
        // Build least-significant char first, prepending, so the 48-bit value
        // lands big-endian across the 10 chars.
        for ($i = 0; $i < self::TIME_CHARS; $i++) {
            $out = self::ALPHABET[$ms % 32] . $out;
            $ms = intdiv($ms, 32);
        }

        return $out;
    }

    private static function encodeRandom(): string
    {
        $out = '';
        for ($i = 0; $i < self::RANDOM_CHARS; $i++) {
            $out .= self::ALPHABET[random_int(0, 31)];
        }

        return $out;
    }
}
