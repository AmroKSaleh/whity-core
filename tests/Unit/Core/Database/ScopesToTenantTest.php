<?php

namespace Tests\Unit\Core\Database;

use PHPUnit\Framework\TestCase;
use Whity\Core\Database\ScopesToTenant;
use Whity\Core\Tenant\TenantContext;

/**
 * Tests for ScopesToTenant trait
 */
class ScopesToTenantTest extends TestCase
{
    /**
     * Reset context after each test
     */
    protected function tearDown(): void
    {
        TenantContext::reset();
    }

    /**
     * Test that bootScopesToTenant method exists and is callable
     */
    public function testBootScopesToTenantMethodExists(): void
    {
        $testModel = new TestModel();

        // Verify the trait is applied to the model
        $this->assertTrue(
            in_array(ScopesToTenant::class, class_uses($testModel)),
            'ScopesToTenant trait should be applied to TestModel'
        );

        // Verify bootScopesToTenant method can be called (through reflection)
        $reflection = new \ReflectionClass($testModel);
        $this->assertTrue(
            $reflection->hasMethod('bootScopesToTenant'),
            'bootScopesToTenant method should exist'
        );
    }

    /**
     * Test that ScopesToTenant trait is properly attached to model class
     */
    public function testScopesToTenantAttachesToModel(): void
    {
        $testModel = new TestModel();

        // Verify trait is attached
        $traits = class_uses($testModel);
        $this->assertArrayHasKey(ScopesToTenant::class, $traits, 'ScopesToTenant should be in class uses');

        // Verify helper methods exist
        $reflection = new \ReflectionClass($testModel);
        $this->assertTrue(
            $reflection->hasMethod('setTenantIdBeforePersist'),
            'setTenantIdBeforePersist method should exist'
        );
        $this->assertTrue(
            $reflection->hasMethod('validateTenantBoundary'),
            'validateTenantBoundary method should exist'
        );
    }
}

/**
 * Test model that uses the ScopesToTenant trait
 *
 * This is a simple in-memory model for testing trait functionality
 */
class TestModel
{
    use ScopesToTenant;

    public ?int $tenant_id = null;
    public ?int $id = null;

    public function __construct()
    {
        $this->id = 1;
    }
}
