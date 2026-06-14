<?php

declare(strict_types=1);

namespace Tests\Http;

use PHPUnit\Framework\TestCase;
use Whity\Http\WorkerRuntime;

/**
 * Unit tests for the FrankenPHP worker runtime decisions (WC-182).
 *
 * The worker loop in public/index.php is not directly unit-testable (it needs
 * frankenphp_handle_request), so the per-request logging gate and the
 * opportunistic-GC cadence are extracted into pure, deterministic functions
 * here and exercised in isolation.
 */
class WorkerRuntimeTest extends TestCase
{
    /**
     * Production (the default when APP_ENV is unset) must NOT emit the
     * per-request lifecycle log lines that previously spammed the log.
     */
    public function testLifecycleLoggingIsDisabledInProductionByDefault(): void
    {
        self::assertFalse(WorkerRuntime::shouldLogLifecycle([]));
        self::assertFalse(WorkerRuntime::shouldLogLifecycle(['APP_ENV' => 'production']));
    }

    /**
     * Development enables the per-request lifecycle logging.
     */
    public function testLifecycleLoggingIsEnabledInDevelopment(): void
    {
        self::assertTrue(WorkerRuntime::shouldLogLifecycle(['APP_ENV' => 'development']));
    }

    /**
     * A truthy DEBUG flag enables lifecycle logging even outside development,
     * so an operator can opt into verbose tracing on a production-mode worker.
     *
     * @dataProvider truthyDebugProvider
     */
    public function testLifecycleLoggingIsEnabledByTruthyDebugFlag(mixed $debug): void
    {
        self::assertTrue(WorkerRuntime::shouldLogLifecycle(['APP_ENV' => 'production', 'DEBUG' => $debug]));
    }

    /**
     * A falsy DEBUG flag in production keeps lifecycle logging off.
     *
     * @dataProvider falsyDebugProvider
     */
    public function testLifecycleLoggingStaysOffForFalsyDebugFlag(mixed $debug): void
    {
        self::assertFalse(WorkerRuntime::shouldLogLifecycle(['APP_ENV' => 'production', 'DEBUG' => $debug]));
    }

    /**
     * In production, a forced cycle collection must NOT happen on most
     * requests; it is opportunistic and runs only on the fixed cadence.
     */
    public function testCycleCollectionIsOpportunisticInProduction(): void
    {
        $env = ['APP_ENV' => 'production'];

        // Request 0 is the first iteration: no forced sweep yet.
        self::assertFalse(WorkerRuntime::shouldCollectCycles(0, $env));
        // A handful of ordinary requests below the cadence: still no forced sweep.
        self::assertFalse(WorkerRuntime::shouldCollectCycles(1, $env));
        self::assertFalse(WorkerRuntime::shouldCollectCycles(49, $env));
        self::assertFalse(WorkerRuntime::shouldCollectCycles(99, $env));
    }

    /**
     * On the chosen cadence boundary in production, a forced sweep runs.
     */
    public function testCycleCollectionRunsOnCadenceBoundaryInProduction(): void
    {
        $env = ['APP_ENV' => 'production'];

        self::assertTrue(WorkerRuntime::shouldCollectCycles(WorkerRuntime::GC_CADENCE, $env));
        self::assertTrue(WorkerRuntime::shouldCollectCycles(WorkerRuntime::GC_CADENCE * 2, $env));
        self::assertTrue(WorkerRuntime::shouldCollectCycles(WorkerRuntime::GC_CADENCE * 3, $env));
    }

    /**
     * In development every request forces a sweep (eager reclamation while
     * iterating helps surface leaks early).
     */
    public function testCycleCollectionRunsEveryRequestInDevelopment(): void
    {
        $env = ['APP_ENV' => 'development'];

        self::assertTrue(WorkerRuntime::shouldCollectCycles(0, $env));
        self::assertTrue(WorkerRuntime::shouldCollectCycles(1, $env));
        self::assertTrue(WorkerRuntime::shouldCollectCycles(7, $env));
    }

    /**
     * A truthy DEBUG flag also forces a per-request sweep regardless of env.
     */
    public function testCycleCollectionRunsEveryRequestUnderDebug(): void
    {
        $env = ['APP_ENV' => 'production', 'DEBUG' => '1'];

        self::assertTrue(WorkerRuntime::shouldCollectCycles(1, $env));
        self::assertTrue(WorkerRuntime::shouldCollectCycles(3, $env));
    }

    /**
     * @return iterable<string, array{0: mixed}>
     */
    public static function truthyDebugProvider(): iterable
    {
        yield 'string 1' => ['1'];
        yield 'string true' => ['true'];
        yield 'string TRUE' => ['TRUE'];
        yield 'string yes' => ['yes'];
        yield 'string on' => ['on'];
        yield 'bool true' => [true];
    }

    /**
     * @return iterable<string, array{0: mixed}>
     */
    public static function falsyDebugProvider(): iterable
    {
        yield 'string 0' => ['0'];
        yield 'string false' => ['false'];
        yield 'string off' => ['off'];
        yield 'string no' => ['no'];
        yield 'empty string' => [''];
        yield 'bool false' => [false];
    }
}
