<?php

namespace Tests\Integration;

use PDO;
use PHPUnit\Framework\TestCase;
use Tests\Support\SchemaFromMigrations;
use Whity\Core\Events\DomainEventStore;
use Whity\Core\Hooks\HookManager;
use Whity\Core\Tenant\TenantContext;

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
    private PDO $pdo;

    protected function setUp(): void
    {
        parent::setUp();

        // dispatchAsync now PERSISTS to the durable event spine, so the hook
        // manager needs a real DomainEventStore backed by the full migration
        // schema (in-memory SQLite). Seed the tenants the async tests use.
        $this->pdo = SchemaFromMigrations::make(true);
        $this->pdo->exec("INSERT INTO tenants (id, name) VALUES (1, 't1'), (5, 't5'), (7, 't7')");
        $this->hookManager = new HookManager(new DomainEventStore($this->pdo));

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
     * Test 2: Async hook is PERSISTED to the durable event spine with context.
     *
     * Verifies (replacing the old log-only Queue assertions):
     * - dispatchAsync writes a domain_events row (event_name + payload)
     * - tenant (from TenantContext) and aggregate (from the dotted event name +
     *   payload id) are promoted to columns
     * - a pending event_outbox row is written for the relay to drain
     */
    public function testAsyncUserCreatedEventIsPersisted(): void
    {
        // Fire async hook (simulating what UsersApiHandler::create does post-commit).
        $this->hookManager->dispatchAsync('user.created.async', [
            'id' => 123,
            'email' => 'newuser@example.com',
        ]);

        $event = $this->fetchOne('SELECT * FROM domain_events WHERE tenant_id = 1');
        $this->assertNotNull($event, 'the async event was persisted to domain_events');
        $this->assertSame('user.created.async', $event['event_name']);
        $this->assertSame('user', $event['aggregate_type'], 'aggregate type derived from the dotted event name');
        $this->assertSame('123', (string) $event['aggregate_id'], 'aggregate id derived from the payload id');
        $this->assertSame(
            ['id' => 123, 'email' => 'newuser@example.com'],
            json_decode((string) $event['payload'], true),
            'the original payload round-trips (no _context wrapper — tenant/actor are columns now)'
        );

        $outbox = $this->fetchOne('SELECT * FROM event_outbox WHERE event_id = :id', [':id' => $event['id']]);
        $this->assertNotNull($outbox, 'a pending relay row was written for the event');
        $this->assertSame('pending', $outbox['status']);
        $this->assertSame(1, (int) $outbox['tenant_id'], 'the outbox row carries the origin tenant');
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
     * Test: Async dispatch under different tenant contexts persists each event
     * stamped with — and keeping — its own origin tenant (no cross-tenant bleed).
     */
    public function testAsyncEventsIsolateTenantContexts(): void
    {
        TenantContext::reset();
        TenantContext::setTenantId(5);
        $this->hookManager->dispatchAsync('user.created.async', ['id' => 100, 'email' => 'u1@t5.test']);

        // A fresh request context (as the persistent worker would reset between requests).
        TenantContext::reset();
        TenantContext::setTenantId(7);
        $this->hookManager->dispatchAsync('user.created.async', ['id' => 200, 'email' => 'u2@t7.test']);

        $t5 = $this->fetchOne('SELECT * FROM domain_events WHERE tenant_id = 5');
        $t7 = $this->fetchOne('SELECT * FROM domain_events WHERE tenant_id = 7');
        $this->assertNotNull($t5, 'tenant 5 event persisted');
        $this->assertNotNull($t7, 'tenant 7 event persisted');
        $this->assertSame('100', (string) $t5['aggregate_id'], 'tenant 5 event kept its own payload id');
        $this->assertSame('200', (string) $t7['aggregate_id'], 'tenant 7 event kept its own payload id');

        // Each outbox row carries the origin tenant of its event, never the other's.
        $o5 = $this->fetchOne('SELECT tenant_id FROM event_outbox WHERE event_id = :id', [':id' => $t5['id']]);
        $o7 = $this->fetchOne('SELECT tenant_id FROM event_outbox WHERE event_id = :id', [':id' => $t7['id']]);
        $this->assertNotNull($o5);
        $this->assertNotNull($o7);
        $this->assertSame(5, (int) $o5['tenant_id']);
        $this->assertSame(7, (int) $o7['tenant_id']);
    }

    /**
     * @param array<string, mixed> $params
     * @return array<string, mixed>|null
     */
    private function fetchOne(string $sql, array $params = []): ?array
    {
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row === false ? null : $row;
    }
}
