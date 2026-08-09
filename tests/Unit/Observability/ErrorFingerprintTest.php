<?php

declare(strict_types=1);

namespace Tests\Unit\Observability;

use PHPUnit\Framework\TestCase;
use Whity\Core\Observability\ErrorFingerprint;

/**
 * The fingerprint decides what counts as "the same error"
 * (WC-error-tracking), which is what makes the inbox useful or useless: too
 * coarse and unrelated bugs merge, too fine and one bug becomes a thousand rows
 * whose occurrence counter means nothing.
 */
final class ErrorFingerprintTest extends TestCase
{
    public function testSameErrorWithDifferentEmbeddedIdsGroupsTogether(): void
    {
        // The single most important case: messages routinely embed ids, so
        // hashing them raw would defeat grouping entirely.
        $a = ErrorFingerprint::fromParts('RuntimeException', 'User 4192 not found', '/app/x.php', 10);
        $b = ErrorFingerprint::fromParts('RuntimeException', 'User 88 not found', '/app/x.php', 10);

        self::assertSame($a, $b);
    }

    public function testTimestampsAndUuidsDoNotSplitAGroup(): void
    {
        $a = ErrorFingerprint::fromParts('X', 'failed at 2026-08-08T01:02:03Z', '/a.php', 1);
        $b = ErrorFingerprint::fromParts('X', 'failed at 2026-01-01T23:59:59Z', '/a.php', 1);
        self::assertSame($a, $b);

        $c = ErrorFingerprint::fromParts('X', 'job 3f2504e0-4f89-11d3-9a0c-0305e82c3301 died', '/a.php', 1);
        $d = ErrorFingerprint::fromParts('X', 'job 8a1b2c3d-4f89-11d3-9a0c-0305e82c3301 died', '/a.php', 1);
        self::assertSame($c, $d);
    }

    public function testQuotedValuesDoNotSplitAGroup(): void
    {
        $a = ErrorFingerprint::fromParts('X', 'column "email" missing', '/a.php', 1);
        $b = ErrorFingerprint::fromParts('X', 'column "name" missing', '/a.php', 1);

        self::assertSame($a, $b);
    }

    public function testDifferentExceptionTypesStaySeparate(): void
    {
        $a = ErrorFingerprint::fromParts('RuntimeException', 'boom', '/a.php', 1);
        $b = ErrorFingerprint::fromParts('LogicException', 'boom', '/a.php', 1);

        self::assertNotSame($a, $b);
    }

    public function testDifferentThrowSitesStaySeparate(): void
    {
        $a = ErrorFingerprint::fromParts('X', 'boom', '/a.php', 1);
        $b = ErrorFingerprint::fromParts('X', 'boom', '/a.php', 2);
        self::assertNotSame($a, $b);

        $c = ErrorFingerprint::fromParts('X', 'boom', '/b.php', 1);
        self::assertNotSame($a, $c);
    }

    public function testGenuinelyDifferentMessagesStaySeparate(): void
    {
        $a = ErrorFingerprint::fromParts('X', 'connection refused', '/a.php', 1);
        $b = ErrorFingerprint::fromParts('X', 'permission denied', '/a.php', 1);

        self::assertNotSame($a, $b);
    }

    public function testFingerprintIsStableAcrossCalls(): void
    {
        $a = ErrorFingerprint::fromParts('X', 'boom', '/a.php', 1);
        $b = ErrorFingerprint::fromParts('X', 'boom', '/a.php', 1);

        self::assertSame($a, $b);
        self::assertSame(64, strlen($a), 'sha256 hex');
    }

    public function testDerivesFromAThrowable(): void
    {
        $e = new \RuntimeException('boom');

        self::assertSame(
            ErrorFingerprint::fromParts($e::class, 'boom', $e->getFile(), $e->getLine()),
            ErrorFingerprint::of($e)
        );
    }
}
