<?php

declare(strict_types=1);

namespace Tests\Core;

use PHPUnit\Framework\TestCase;
use Whity\Core\PluginLifecycle;
use Whity\Core\PluginState;
use Whity\Core\Exception\InvalidPluginStateTransitionException;

/**
 * Unit tests for the PluginLifecycle state machine.
 */
class PluginLifecycleTest extends TestCase
{
    public function testStartsInDiscoveredState(): void
    {
        $lifecycle = new PluginLifecycle('Acme\\Plugin');

        $this->assertSame(PluginState::Discovered, $lifecycle->getState());
        $this->assertSame(0, $lifecycle->getConsecutiveErrors());
        $this->assertNull($lifecycle->getLastError());
    }

    public function testTransitionsThroughHappyPath(): void
    {
        $lifecycle = new PluginLifecycle('Acme\\Plugin');

        $lifecycle->markLoaded();
        $this->assertSame(PluginState::Loaded, $lifecycle->getState());

        $lifecycle->markActive();
        $this->assertSame(PluginState::Active, $lifecycle->getState());
    }

    public function testRecordErrorIncrementsCounterAndCapturesDetails(): void
    {
        $lifecycle = new PluginLifecycle('Acme\\Plugin');
        $lifecycle->markLoaded();
        $lifecycle->markActive();

        $lifecycle->recordError(new \RuntimeException('boom'));

        $this->assertSame(1, $lifecycle->getConsecutiveErrors());
        $this->assertSame(PluginState::Active, $lifecycle->getState());

        $error = $lifecycle->getLastError();
        $this->assertNotNull($error);
        $this->assertSame('boom', $error['message']);
        $this->assertSame(\RuntimeException::class, $error['type']);
        $this->assertNotSame('', $error['trace']);
    }

    public function testTransitionsToFailedAfterThreeConsecutiveErrors(): void
    {
        $lifecycle = new PluginLifecycle('Acme\\Plugin');
        $lifecycle->markLoaded();
        $lifecycle->markActive();

        $this->assertSame(3, PluginLifecycle::MAX_CONSECUTIVE_ERRORS);

        $lifecycle->recordError(new \RuntimeException('1'));
        $lifecycle->recordError(new \RuntimeException('2'));
        $this->assertSame(PluginState::Active, $lifecycle->getState());

        $lifecycle->recordError(new \RuntimeException('3'));
        $this->assertSame(PluginState::Failed, $lifecycle->getState());
        $this->assertSame(3, $lifecycle->getConsecutiveErrors());
        $this->assertTrue($lifecycle->isFailed());
    }

    public function testSuccessResetsConsecutiveErrorCounter(): void
    {
        $lifecycle = new PluginLifecycle('Acme\\Plugin');
        $lifecycle->markLoaded();
        $lifecycle->markActive();

        $lifecycle->recordError(new \RuntimeException('1'));
        $lifecycle->recordError(new \RuntimeException('2'));
        $this->assertSame(2, $lifecycle->getConsecutiveErrors());

        $lifecycle->recordSuccess();
        $this->assertSame(0, $lifecycle->getConsecutiveErrors());
        $this->assertSame(PluginState::Active, $lifecycle->getState());

        // Counter genuinely reset: it now takes 3 more errors to fail.
        $lifecycle->recordError(new \RuntimeException('a'));
        $lifecycle->recordError(new \RuntimeException('b'));
        $this->assertSame(PluginState::Active, $lifecycle->getState());
    }

    public function testReEnableRestoresActiveStateAndClearsErrors(): void
    {
        $lifecycle = new PluginLifecycle('Acme\\Plugin');
        $lifecycle->markLoaded();
        $lifecycle->markActive();
        $lifecycle->recordError(new \RuntimeException('1'));
        $lifecycle->recordError(new \RuntimeException('2'));
        $lifecycle->recordError(new \RuntimeException('3'));
        $this->assertTrue($lifecycle->isFailed());

        $lifecycle->reEnable();

        $this->assertSame(PluginState::Active, $lifecycle->getState());
        $this->assertSame(0, $lifecycle->getConsecutiveErrors());
        $this->assertNull($lifecycle->getLastError());
    }

    public function testRecordErrorOnFailedPluginIsIgnored(): void
    {
        $lifecycle = new PluginLifecycle('Acme\\Plugin');
        $lifecycle->markLoaded();
        $lifecycle->markActive();
        $lifecycle->recordError(new \RuntimeException('1'));
        $lifecycle->recordError(new \RuntimeException('2'));
        $lifecycle->recordError(new \RuntimeException('3'));
        $this->assertTrue($lifecycle->isFailed());

        // Further errors while failed should not throw or change the count.
        $lifecycle->recordError(new \RuntimeException('4'));
        $this->assertSame(PluginState::Failed, $lifecycle->getState());
        $this->assertSame(3, $lifecycle->getConsecutiveErrors());
    }

    public function testInvalidTransitionThrows(): void
    {
        $lifecycle = new PluginLifecycle('Acme\\Plugin');

        // Cannot go straight from discovered to active without loading.
        $this->expectException(InvalidPluginStateTransitionException::class);
        $lifecycle->markActive();
    }

    public function testToArrayExposesStatusDetails(): void
    {
        $lifecycle = new PluginLifecycle('Acme\\Plugin', 'Acme Plugin');
        $lifecycle->markLoaded();
        $lifecycle->markActive();
        $lifecycle->recordError(new \RuntimeException('explosion'));

        $array = $lifecycle->toArray();

        $this->assertSame('Acme\\Plugin', $array['id']);
        $this->assertSame('Acme Plugin', $array['name']);
        $this->assertSame('active', $array['state']);
        $this->assertSame(1, $array['consecutive_errors']);
        $this->assertIsArray($array['last_error']);
        $this->assertSame('explosion', $array['last_error']['message']);
    }
}
