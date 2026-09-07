<?php

declare(strict_types=1);

namespace Tests\Unit;

use PDO;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use Tests\Support\SchemaFromMigrations;

/**
 * A set-but-unreachable `PHPUNIT_PG_DSN` must FAIL, not quietly become SQLite
 * (#941).
 *
 * WHY THIS IS PINNED RATHER THAN OBSERVED
 * ---------------------------------------
 * The reported behaviour no longer reproduces — `buildPostgresPdo()` lets the
 * connection error out — but nothing asserted it, so the harness could return to
 * falling back and no run would say so. That is the property worth holding: the
 * failure mode was not a crash, it was a PASS.
 *
 * #941 measured it: every notification real-engine suite reported
 * `OK (142 tests, 453 assertions)` against a dead DSN, where a live PostgreSQL
 * runs 204 tests over different code paths. A green run that exercised the
 * engine it was written to avoid is worse than a red one, and these are the
 * DIALECT suites — their entire reason to exist is that the two engines diverge.
 *
 * WHAT THIS DOES NOT ASSERT
 * -------------------------
 * Nothing about the SQLite path, which is the default and is exercised by every
 * other test in the suite. Only that ASKING for PostgreSQL and not getting it is
 * an error.
 */
final class SchemaFromMigrationsDsnFailureTest extends TestCase
{
    /**
     * The DSN as it stood before this class ran.
     *
     * Snapshotted rather than assumed absent: CI's PostgreSQL shards set it for
     * real, and a test asserting "unset afterwards" would fail there while
     * passing on the SQLite job — the same one-engine blind spot #941 is about.
     */
    private static ?string $dsnBeforeClass = null;

    public static function setUpBeforeClass(): void
    {
        self::$dsnBeforeClass = $_ENV['PHPUNIT_PG_DSN'] ?? null;
    }

    public function testAnUnreachableDsnFailsInsteadOfFallingBackToSqlite(): void
    {
        if (!in_array('pgsql', PDO::getAvailableDrivers(), true)) {
            // Without the driver, PDO refuses the DSN for a DIFFERENT reason
            // ("could not find driver") and the test would pass while proving
            // nothing about the fallback.
            self::markTestSkipped('pdo_pgsql is not installed, so a refused DSN would not be refused for the reason under test.');
        }

        $reflection = new ReflectionClass(SchemaFromMigrations::class);
        $templateFlag = $reflection->getProperty('pgTemplateUnavailable');
        $templateFlagWasSet = $templateFlag->getValue();

        $previousDsn = $_ENV['PHPUNIT_PG_DSN'] ?? null;
        $previousUser = $_ENV['PHPUNIT_PG_USER'] ?? null;
        $previousPassword = $_ENV['PHPUNIT_PG_PASSWORD'] ?? null;

        // Port 59999 on loopback: refused immediately rather than hanging, so
        // this stays a fast assertion instead of a timeout.
        $_ENV['PHPUNIT_PG_DSN'] = 'pgsql:host=127.0.0.1;port=59999;dbname=whity_unreachable';
        $_ENV['PHPUNIT_PG_USER'] = 'nobody';
        $_ENV['PHPUNIT_PG_PASSWORD'] = 'nothing';

        try {
            $threw = false;
            try {
                SchemaFromMigrations::make();
            } catch (\Throwable) {
                $threw = true;
            }

            self::assertTrue(
                $threw,
                'make() returned a handle for a DSN nothing is listening on. That is the #941 defect: '
                . 'the real-engine suites would run on SQLite and report a normal pass, so a dialect '
                . 'difference they exist to catch would go unmeasured while the run looked green.'
            );
        } finally {
            // Restore BOTH, and the static in particular: `makeFromTemplateDatabase()`
            // latches `pgTemplateUnavailable` on any failure and offers no reset,
            // so leaving it set would silently disable the PostgreSQL template
            // fast path for every later test in this process — turning one guard
            // into a slowdown across the shard.
            $templateFlag->setValue(null, $templateFlagWasSet);

            foreach ([
                'PHPUNIT_PG_DSN' => $previousDsn,
                'PHPUNIT_PG_USER' => $previousUser,
                'PHPUNIT_PG_PASSWORD' => $previousPassword,
            ] as $key => $value) {
                if ($value === null) {
                    unset($_ENV[$key]);
                } else {
                    $_ENV[$key] = $value;
                }
            }
        }
    }

    /**
     * And the restore above actually restores, so this file cannot make the rest
     * of the shard slower or send it to the wrong engine.
     */
    public function testTheHarnessIsLeftAsItWasFound(): void
    {
        self::assertSame(
            self::$dsnBeforeClass,
            $_ENV['PHPUNIT_PG_DSN'] ?? null,
            'the previous test leaked its unreachable DSN, which would send every following '
            . 'real-engine test at a dead server — and on the PostgreSQL shards it would replace '
            . 'a working DSN rather than merely adding one'
        );
    }
}
