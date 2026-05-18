<?php

namespace Tests\Unit\Core\Tenant;

use PHPUnit\Framework\TestCase;
use Whity\Core\Tenant\TenantContext;

/**
 * Tests for TenantContext class
 */
class TenantContextTest extends TestCase
{
    /**
     * Reset context after each test
     */
    protected function tearDown(): void
    {
        TenantContext::reset();
    }

    /**
     * Test that setTenantId stores the tenant ID
     */
    public function testSetTenantIdStoresTenantId(): void
    {
        TenantContext::setTenantId(42);
        $this->assertSame(42, TenantContext::getTenantId());
    }

    /**
     * Test that setTenantId locks the context after first set
     */
    public function testSetTenantIdLocksContextAfterFirstSet(): void
    {
        TenantContext::setTenantId(42);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/locked/i');

        TenantContext::setTenantId(99);
    }

    /**
     * Test that hasTenant returns correct status
     */
    public function testHasTenantReturnsTrueWhenSet(): void
    {
        $this->assertFalse(TenantContext::hasTenant());

        TenantContext::setTenantId(42);
        $this->assertTrue(TenantContext::hasTenant());
    }

    /**
     * Test that getTenantId returns null when not set
     */
    public function testGetTenantIdReturnsNullWhenNotSet(): void
    {
        $this->assertNull(TenantContext::getTenantId());
    }

    /**
     * Test that reset clears context and unlocks it
     */
    public function testResetClearsContextAndUnlocks(): void
    {
        TenantContext::setTenantId(42);
        $this->assertTrue(TenantContext::hasTenant());

        TenantContext::reset();
        $this->assertFalse(TenantContext::hasTenant());
        $this->assertNull(TenantContext::getTenantId());

        // Should be able to set again after reset
        TenantContext::setTenantId(99);
        $this->assertSame(99, TenantContext::getTenantId());
    }
}
