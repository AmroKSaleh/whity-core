<?php

declare(strict_types=1);

namespace Tests\Unit\Core\Support;

use PHPUnit\Framework\TestCase;
use Whity\Core\Support\Ulid;

/**
 * Unit tests for the hand-rolled ULID generator (#154): 26 Crockford-base32
 * chars, uniqueness, and — the load-bearing property for the event-store
 * cursor — byte-order equals time-order.
 */
final class UlidTest extends TestCase
{
    private const CROCKFORD = '/^[0-9A-HJKMNP-TV-Z]{26}$/';

    public function testIsTwentySixCrockfordBase32Chars(): void
    {
        $ulid = Ulid::generate();

        self::assertSame(26, strlen($ulid));
        self::assertMatchesRegularExpression(self::CROCKFORD, $ulid, 'no I, L, O or U in Crockford base32');
    }

    public function testIsUniqueAcrossManyCallsInTheSameMillisecond(): void
    {
        $fixedMs = 1_700_000_000_000;
        $seen = [];
        for ($i = 0; $i < 1000; $i++) {
            $seen[Ulid::generate($fixedMs)] = true;
        }

        self::assertCount(1000, $seen, 'the 80-bit random suffix keeps ids unique within one millisecond');
    }

    public function testByteOrderMatchesTimeOrder(): void
    {
        $earlier = Ulid::generate(1_700_000_000_000);
        $later = Ulid::generate(1_700_000_000_001);

        self::assertLessThan(0, strcmp($earlier, $later), 'a later timestamp must sort strictly after an earlier one');
    }

    public function testSharedTimestampPrefixIsStableAndSortsByRandomSuffixOnly(): void
    {
        $ms = 1_700_000_000_123;
        $a = Ulid::generate($ms);
        $b = Ulid::generate($ms);

        // Same ms → identical 10-char time prefix; ordering then falls to the suffix.
        self::assertSame(substr($a, 0, 10), substr($b, 0, 10), 'same millisecond yields the same time prefix');
    }
}
