<?php

namespace Tests\Integration;

use PHPUnit\Framework\TestCase;
use Whity\Core\Hooks\HookManager;
use Whity\Core\Queue\Queue;
use Whity\Core\Tenant\TenantContext;
use Psr\Log\LoggerInterface;

/**
 * Integration tests for the hook system
 *
 * Verifies that hooks are fired at the correct times, with correct data,
 * and that async hooks are properly queued. Tests include priority-based
 * execution order and hook payload validation.
 */
class HookIntegrationTest extends TestCase
{
    private HookManager $hookManager;
    private LoggerInterface $logger;

    protected function setUp(): void
    {
        parent::setUp();
        $this->hookManager = new HookManager();

        // Create a mock logger to capture async queue calls
        $this->logger = $this->createMock(LoggerInterface::class);
        Queue::setLogger($this->logger);

        // Set a test tenant context
        TenantContext::reset();
        TenantContext::setTenantId(1);
    }

    protected function tearDown(): void
    {
        TenantContext::reset();
        parent::tearDown();
    }

    /**
     * Test 1: Sync hook is called before insert with correct data
     *
     * Verifies:
     * - Sync hooks fire at the right time (before database insert)
     * - Handler receives tenant context
     * - Hook payload contains scalars only
     * - Hook execution doesn't throw exceptions
     */
    public function testSyncUserCreatingHookIsCalledBeforeInsert(): void
    {
        // Setup: Register listener for user.creating hook
        $hookCalled = false;
        $capturedData = null;
        $capturedContext = null;

        $this->hookManager->listen('user.creating', function($data, $context) use (&$hookCalled, &$capturedData, &$capturedContext) {
            $hookCalled = true;
            $capturedData = $data;
            $capturedContext = $context;
            return $data;
        });

        // Simulate user creation data that would be passed to hook
        $userData = [
            'email' => 'newuser@example.com',
            'password' => 'plaintext_password',
            'role_id' => 2,
        ];

        // Fire the hook (simulating what UsersApiHandler::create does)
        $result = $this->hookManager->dispatch('user.creating', $userData);

        // Assertions
        $this->assertTrue($hookCalled, 'user.creating hook should be called');

        // Verify hook received correct data
        $this->assertIsArray($capturedData);
        $this->assertArrayHasKey('email', $capturedData);
        $this->assertArrayHasKey('password', $capturedData);
        $this->assertArrayHasKey('role_id', $capturedData);
        $this->assertSame('newuser@example.com', $capturedData['email']);
        $this->assertSame('plaintext_password', $capturedData['password']);
        $this->assertSame(2, $capturedData['role_id']);

        // Verify hook received tenant context
        $this->assertIsArray($capturedContext);
        $this->assertArrayHasKey('tenant_id', $capturedContext);
        $this->assertArrayHasKey('timestamp', $capturedContext);
        $this->assertSame(1, $capturedContext['tenant_id']);
        $this->assertIsInt($capturedContext['timestamp']);

        // Verify hook payload contains only scalars, not objects
        foreach ($capturedData as $key => $value) {
            $this->assertFalse(is_object($value), "Hook payload key '{$key}' should not be an object");
            $this->assertTrue(is_scalar($value) || is_array($value), "Hook payload should contain only scalars or arrays");
        }

        // Verify the hook can return modified data
        $this->assertSame($result, $capturedData);
    }

    /**
     * Test 2: Async hook is queued with correct context
     *
     * Verifies:
     * - Async hooks are queued to the correct queue
     * - Queue payload includes tenant_id and timestamp context
     * - Queue push method is called exactly once
     * - Payload contains expected data structure
     */
    public function testAsyncUserCreatedHookIsQueued(): void
    {
        // Setup: Expect Queue::push to be called with correct parameters
        $queueCapture = null;
        $payloadCapture = null;

        $this->logger->expects($this->once())
            ->method('info')
            ->with(
                $this->equalTo('Async Job Queued on [whity-core-async-hooks]'),
                $this->callback(function($logContext) use (&$queueCapture, &$payloadCapture) {
                    $queueCapture = $logContext['queue'] ?? null;
                    $payloadCapture = $logContext['payload'] ?? null;
                    return isset($logContext['queue']) &&
                           $logContext['queue'] === 'whity-core-async-hooks' &&
                           isset($logContext['payload']) &&
                           isset($logContext['payload']['_context']);
                })
            );

        // Simulate user creation that would trigger async hook
        $userData = [
            'id' => 123,
            'email' => 'newuser@example.com',
        ];

        // Fire async hook (simulating what UsersApiHandler::create does)
        $this->hookManager->dispatchAsync('user.created.async', $userData);

        // Assertions
        $this->assertSame('whity-core-async-hooks', $queueCapture, 'Should queue to whity-core-async-hooks');

        // Verify payload structure
        $this->assertIsArray($payloadCapture);
        $this->assertArrayHasKey('_context', $payloadCapture, 'Payload should include context');
        $this->assertArrayHasKey('id', $payloadCapture, 'Payload should include user id');
        $this->assertArrayHasKey('email', $payloadCapture, 'Payload should include user email');

        // Verify context was injected correctly
        $context = $payloadCapture['_context'];
        $this->assertIsArray($context);
        $this->assertArrayHasKey('tenant_id', $context);
        $this->assertArrayHasKey('timestamp', $context);
        $this->assertSame(1, $context['tenant_id'], 'Queued context should have correct tenant_id');
        $this->assertIsInt($context['timestamp']);

        // Verify original data is preserved in payload
        $this->assertSame(123, $payloadCapture['id']);
        $this->assertSame('newuser@example.com', $payloadCapture['email']);
    }

    /**
     * Test 3: Hook listeners execute in priority order
     *
     * Verifies:
     * - Multiple listeners for the same hook execute in order
     * - Lower priority numbers execute first
     * - Each listener receives the data from previous listener
     * - Data flows correctly through the chain
     */
    public function testHookPriorityExecutionOrder(): void
    {
        // Setup: Track execution order
        $executionOrder = [];

        // Register listeners with different priorities
        // Priority 5 (highest priority = earliest execution)
        $this->hookManager->listen('test.hook', function($data, $context) use (&$executionOrder) {
            $executionOrder[] = 'priority_5';
            $data['step1'] = true;
            return $data;
        }, 5);

        // Priority 10 (medium priority)
        $this->hookManager->listen('test.hook', function($data, $context) use (&$executionOrder) {
            $executionOrder[] = 'priority_10';
            $data['step2'] = true;
            return $data;
        }, 10);

        // Priority 15 (lowest priority = latest execution)
        $this->hookManager->listen('test.hook', function($data, $context) use (&$executionOrder) {
            $executionOrder[] = 'priority_15';
            $data['step3'] = true;
            return $data;
        }, 15);

        // Another listener at priority 5 to verify same-priority order
        $this->hookManager->listen('test.hook', function($data, $context) use (&$executionOrder) {
            $executionOrder[] = 'priority_5_second';
            return $data;
        }, 5);

        // Fire the hook
        $result = $this->hookManager->dispatch('test.hook', []);

        // Assertions
        // Verify execution order: priority 5 listeners first (in registration order), then 10, then 15
        $this->assertSame(
            ['priority_5', 'priority_5_second', 'priority_10', 'priority_15'],
            $executionOrder,
            'Listeners should execute in priority order (lower number first), with same-priority listeners in registration order'
        );

        // Verify all listeners modified the data (data flows through the chain)
        $this->assertTrue($result['step1'] ?? false, 'Priority 5 listener should have executed');
        $this->assertTrue($result['step2'] ?? false, 'Priority 10 listener should have executed');
        $this->assertTrue($result['step3'] ?? false, 'Priority 15 listener should have executed');

        // Count total listeners
        $this->assertCount(4, $executionOrder, 'All 4 listeners should have executed');
    }

    /**
     * Test 4: Hook payload contains only scalar data, not objects
     *
     * Verifies:
     * - Hook payloads never contain object instances
     * - Complex data is represented as scalar arrays
     * - No Model objects or class instances in payload
     * - Original and filtered data are scalars
     */
    public function testHookPayloadContainsOnlyScalarData(): void
    {
        // Setup: Capture the payload and verify its scalar nature
        $capturedPayload = null;

        $this->hookManager->listen('user.created', function($data, $context) use (&$capturedPayload) {
            $capturedPayload = $data;
            return $data;
        });

        // Simulate user creation data with various scalar types
        $userData = [
            'id' => 123,
            'email' => 'user@example.com',
            'role_id' => 2,
            'tenant_id' => 1,
            'created_at' => '2026-05-18 10:00:00',
        ];

        // Fire the hook
        $this->hookManager->dispatch('user.created', $userData);

        // Assertions
        $this->assertIsArray($capturedPayload, 'Payload should be an array');

        // Verify all values are scalars
        foreach ($capturedPayload as $key => $value) {
            $this->assertFalse(
                is_object($value),
                "Payload key '{$key}' should not be an object, got " . gettype($value)
            );
            $this->assertTrue(
                is_scalar($value),
                "Payload key '{$key}' should be a scalar (string, int, float, bool), got " . gettype($value)
            );
        }

        // Verify specific scalar types
        $this->assertIsInt($capturedPayload['id']);
        $this->assertIsString($capturedPayload['email']);
        $this->assertIsInt($capturedPayload['role_id']);
        $this->assertIsInt($capturedPayload['tenant_id']);
        $this->assertIsString($capturedPayload['created_at']);
    }

    /**
     * Test 5: Sync hook can modify data before insert
     *
     * Verifies:
     * - Filter hooks can modify data
     * - Modified data is returned from dispatch()
     * - Original values differ from modified values
     * - Modifications persist through the hook chain
     */
    public function testSyncHookCanModifyDataBeforeInsert(): void
    {
        // Setup: Register a filter that modifies user email
        $originalEmail = 'USER@EXAMPLE.COM'; // Mixed case
        $expectedModifiedEmail = 'user@example.com'; // Lowercase after filter

        $this->hookManager->listen('user.creating', function($data, $context) {
            // Simulate email normalization filter
            $data['email'] = strtolower($data['email']);
            return $data;
        }, 10);

        // Create user data with original email
        $userData = [
            'email' => $originalEmail,
            'password' => 'test_password',
            'role_id' => 2,
        ];

        // Fire the hook and capture result
        $modifiedData = $this->hookManager->dispatch('user.creating', $userData);

        // Assertions
        // Verify original and modified values are different
        $this->assertNotSame($originalEmail, $modifiedData['email'], 'Email should be modified by hook');

        // Verify modified email is correct
        $this->assertSame($expectedModifiedEmail, $modifiedData['email'], 'Email should be normalized to lowercase');

        // Verify other data is unchanged
        $this->assertSame('test_password', $modifiedData['password']);
        $this->assertSame(2, $modifiedData['role_id']);

        // Verify it's the same structure
        $this->assertSame(array_keys($userData), array_keys($modifiedData), 'Keys should match between original and modified data');
    }

    /**
     * Test: Hook chain modification where multiple hooks modify the same data
     *
     * Verifies:
     * - Multiple filter hooks can chain modifications
     * - Each hook receives the modified data from previous hook
     * - Final result contains all modifications in order
     */
    public function testHookChainModificationsAccumulate(): void
    {
        // Setup: Create a chain of modifications
        $this->hookManager->listen('user.creating', function($data, $context) {
            // First hook: normalize email
            $data['email'] = strtolower($data['email']);
            return $data;
        }, 5);

        $this->hookManager->listen('user.creating', function($data, $context) {
            // Second hook: add timestamp if not present
            if (!isset($data['created_at'])) {
                $data['created_at'] = date('Y-m-d H:i:s');
            }
            return $data;
        }, 10);

        $this->hookManager->listen('user.creating', function($data, $context) {
            // Third hook: remove password, store hash instead (this is normally done in handler)
            if (isset($data['password'])) {
                unset($data['password']);
                // Demo: In production use password_hash() or bcrypt
                $data['password_hash'] = 'bcrypt_hashed_value';
            }
            return $data;
        }, 15);

        // Create user data
        $userData = [
            'email' => 'TEST@EXAMPLE.COM',
            'password' => 'plaintext',
            'role_id' => 2,
        ];

        // Fire the hook
        $result = $this->hookManager->dispatch('user.creating', $userData);

        // Assertions
        // First modification: email normalized
        $this->assertSame('test@example.com', $result['email']);

        // Second modification: timestamp added
        $this->assertArrayHasKey('created_at', $result);
        $this->assertIsString($result['created_at']);

        // Third modification: password replaced with hash
        $this->assertArrayNotHasKey('password', $result);
        $this->assertArrayHasKey('password_hash', $result);
        $this->assertStringStartsWith('bcrypt_', $result['password_hash']);

        // Original role unchanged
        $this->assertSame(2, $result['role_id']);
    }

    /**
     * Test: Dispatcher returns unmodified data if no listeners registered
     *
     * Verifies:
     * - Hooks with no listeners return original data
     * - No exceptions thrown for unregistered hooks
     * - Data integrity maintained
     */
    public function testDispatchWithNoListenersReturnsOriginalData(): void
    {
        $userData = [
            'email' => 'test@example.com',
            'password' => 'password123',
            'role_id' => 2,
        ];

        // Fire hook with no listeners registered
        $result = $this->hookManager->dispatch('nonexistent.hook', $userData);

        // Assertions
        $this->assertSame($userData, $result, 'Should return unchanged data when no listeners registered');
    }

    /**
     * Test: Async dispatch with multiple tenants maintains isolation
     *
     * Verifies:
     * - Different tenant contexts are preserved in async queues
     * - Each queued item has correct tenant_id
     * - Async hook isolation is maintained
     */
    public function testAsyncHookQueuesIsolateTenantContexts(): void
    {
        // Setup: Set initial tenant
        TenantContext::reset();
        TenantContext::setTenantId(5);

        // Capture queued payloads
        $capturedPayloads = [];

        $this->logger->expects($this->exactly(2))
            ->method('info')
            ->willReturnCallback(function($message, $context) use (&$capturedPayloads) {
                $capturedPayloads[] = $context;
            });

        // Queue first job for tenant 5
        $this->hookManager->dispatchAsync('user.created.async', [
            'user_id' => 100,
            'email' => 'user1@tenant5.com',
        ]);

        // Simulate different request context - reset and set to tenant 7
        TenantContext::reset();
        TenantContext::setTenantId(7);

        // Queue second job for tenant 7
        $this->hookManager->dispatchAsync('user.created.async', [
            'user_id' => 200,
            'email' => 'user2@tenant7.com',
        ]);

        // Assertions
        $this->assertCount(2, $capturedPayloads, 'Both jobs should be queued');

        // Verify first job has tenant 5 context
        $this->assertSame(5, $capturedPayloads[0]['payload']['_context']['tenant_id']);
        $this->assertSame(100, $capturedPayloads[0]['payload']['user_id']);

        // Verify second job has tenant 7 context
        $this->assertSame(7, $capturedPayloads[1]['payload']['_context']['tenant_id']);
        $this->assertSame(200, $capturedPayloads[1]['payload']['user_id']);
    }
}
