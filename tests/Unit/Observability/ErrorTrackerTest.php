<?php

declare(strict_types=1);

namespace Tests\Unit\Observability;

use PHPUnit\Framework\TestCase;
use Whity\Core\CoreVersion;
use Whity\Core\Tenant\TenantContext;
use Whity\Core\Observability\ErrorContext;
use Whity\Core\Observability\ErrorTracker;
use Whity\Core\Observability\ErrorTrackerFactory;
use Whity\Core\Observability\NullErrorTracker;

/**
 * Unit tests for the error-tracker seam (WC-d): context gathering, the no-op
 * default, and config-driven provider selection.
 */
final class ErrorTrackerTest extends TestCase
{
    protected function tearDown(): void
    {
        TenantContext::reset();
    }

    public function testGatherAssemblesReleaseTenantRequestIdAndSortedPlugins(): void
    {
        TenantContext::setTenantId(7);

        $context = ErrorContext::gather(
            [
                ['id' => 'HelloWorld', 'name' => 'Hello World', 'version' => '1.2.0', 'status' => 'active'],
                ['id' => 'Acme', 'version' => '0.9.1'],
            ],
            'req-abc'
        );

        self::assertSame(CoreVersion::VERSION, $context['release']);
        self::assertSame(7, $context['tenant_id']);
        self::assertSame('req-abc', $context['request_id']);
        // Plugins are id => version, sorted by id.
        self::assertSame(['Acme' => '0.9.1', 'HelloWorld' => '1.2.0'], $context['plugins']);
    }

    public function testGatherSkipsMalformedPluginEntriesAndHandlesEmpty(): void
    {
        self::assertSame([], ErrorContext::gather([], 'r')['plugins']);

        $plugins = ErrorContext::gather(
            [
                'not-an-array',
                ['name' => ''],                       // no id/name → skipped
                ['id' => 'Ok', 'version' => '2.0.0'], // kept
            ],
            'r'
        )['plugins'];

        self::assertSame(['Ok' => '2.0.0'], $plugins);
    }

    public function testGatherTenantIdIsNullWhenUnresolved(): void
    {
        TenantContext::reset();
        self::assertNull(ErrorContext::gather([], 'r')['tenant_id']);
    }

    public function testNullTrackerIsANoOpAndNeverThrows(): void
    {
        $tracker = new NullErrorTracker();
        self::assertInstanceOf(ErrorTracker::class, $tracker);
        $tracker->captureException(new \RuntimeException('boom'), ['release' => '1.0.0']);
        $this->addToAssertionCount(1); // reached here without throwing
    }

    public function testFactoryReturnsNullTrackerWithoutADsn(): void
    {
        self::assertInstanceOf(NullErrorTracker::class, ErrorTrackerFactory::fromEnv([]));
        self::assertInstanceOf(NullErrorTracker::class, ErrorTrackerFactory::fromEnv(['SENTRY_DSN' => '  ']));
    }

    public function testFactoryFailsSafeToNullWhenDsnSetButNoProviderInstalled(): void
    {
        // No concrete provider class exists yet, so a configured DSN must fail
        // safe to the no-op tracker rather than fatal.
        $tracker = ErrorTrackerFactory::fromEnv(['ERROR_TRACKER_DSN' => 'https://key@example.test/1']);
        self::assertInstanceOf(NullErrorTracker::class, $tracker);
    }
}
