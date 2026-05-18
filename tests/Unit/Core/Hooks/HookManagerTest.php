<?php

namespace Tests\Unit\Core\Hooks;

use PHPUnit\Framework\TestCase;
use Whity\Core\Hooks\HookManager;
use Whity\Core\Tenant\TenantContext;
use Whity\Core\Queue\Queue;

/**
 * Tests for HookManager class
 */
class HookManagerTest extends TestCase
{
    private HookManager $hookManager;

    protected function setUp(): void
    {
        $this->hookManager = new HookManager();
        // Reset TenantContext for each test
        TenantContext::reset();
    }

    protected function tearDown(): void
    {
        TenantContext::reset();
    }

    /**
     * Test listen() stores callback in listeners array
     */
    public function testListenRegistersCallback(): void
    {
        $callback = fn() => null;
        $this->hookManager->listen('test_event', $callback);

        $listeners = $this->hookManager->getListeners('test_event');
        $this->assertNotEmpty($listeners);
    }

    /**
     * Test dispatch() executes listeners in priority order
     */
    public function testDispatchExecutesListenersInPriorityOrder(): void
    {
        $execution = [];

        // Register listeners with different priorities
        $this->hookManager->listen('test_event', function() use (&$execution) {
            $execution[] = 'first';
        }, 5);

        $this->hookManager->listen('test_event', function() use (&$execution) {
            $execution[] = 'second';
        }, 10);

        $this->hookManager->listen('test_event', function() use (&$execution) {
            $execution[] = 'third';
        }, 15);

        $this->hookManager->dispatch('test_event', []);

        $this->assertEquals(['first', 'second', 'third'], $execution);
    }

    /**
     * Test dispatch() returns modified data
     */
    public function testDispatchReturnsModifiedData(): void
    {
        $this->hookManager->listen('test_event', function($data, $context) {
            $data['name'] = 'modified';
            return $data;
        });

        $result = $this->hookManager->dispatch('test_event', ['name' => 'original']);

        $this->assertEquals('modified', $result['name']);
    }

    /**
     * Test dispatch() includes context metadata
     */
    public function testDispatchIncludesContextMetadata(): void
    {
        TenantContext::setTenantId(42);

        $capturedContext = null;
        $this->hookManager->listen('test_event', function($data, $context) use (&$capturedContext) {
            $capturedContext = $context;
            return $data;
        });

        $this->hookManager->dispatch('test_event', []);

        $this->assertIsArray($capturedContext);
        $this->assertArrayHasKey('tenant_id', $capturedContext);
        $this->assertArrayHasKey('timestamp', $capturedContext);
        $this->assertEquals(42, $capturedContext['tenant_id']);
        $this->assertIsInt($capturedContext['timestamp']);
    }

    /**
     * Test dispatchAsync() queues payload without throwing
     */
    public function testDispatchAsyncQueuesPayload(): void
    {
        // Mock the Queue class
        $queueMock = $this->createMock(Queue::class);
        $queueMock->expects($this->once())
            ->method('push')
            ->with(
                $this->equalTo('whity-core-async-hooks'),
                $this->callback(function($payload) {
                    return is_array($payload) && isset($payload['_context']);
                })
            );

        TenantContext::setTenantId(1);

        // Use reflection to inject mock
        $reflection = new \ReflectionClass($this->hookManager);
        $property = $reflection->getProperty('queue');
        $property->setAccessible(true);
        $property->setValue($this->hookManager, $queueMock);

        // This should not throw
        $this->hookManager->dispatchAsync('test_event', ['key' => 'value']);
    }

    /**
     * Test multiple listeners at same priority execute sequentially
     */
    public function testMultipleListenersExecuteSequentially(): void
    {
        $execution = [];

        $this->hookManager->listen('test_event', function($data, $context) use (&$execution) {
            $execution[] = 'listener1';
            $data['step'] = 1;
            return $data;
        }, 10);

        $this->hookManager->listen('test_event', function($data, $context) use (&$execution) {
            $execution[] = 'listener2';
            $data['step'] = 2;
            return $data;
        }, 10);

        $result = $this->hookManager->dispatch('test_event', ['step' => 0]);

        $this->assertEquals(['listener1', 'listener2'], $execution);
        $this->assertEquals(2, $result['step']);
    }

    /**
     * Test getListeners() returns empty array for unregistered event
     */
    public function testGetListenersReturnsEmptyForUnregisteredEvent(): void
    {
        $listeners = $this->hookManager->getListeners('nonexistent_event');

        $this->assertIsArray($listeners);
        $this->assertEmpty($listeners);
    }
}
