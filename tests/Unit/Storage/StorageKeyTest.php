<?php

declare(strict_types=1);

namespace Tests\Unit\Storage;

use PHPUnit\Framework\TestCase;
use Whity\Storage\StorageKey;

class StorageKeyTest extends TestCase
{
    // ------------------------------------------------------------------
    // build()
    // ------------------------------------------------------------------

    public function testBuildProducesCanonicalKey(): void
    {
        $key = StorageKey::build(42, 'elmak', 'exam.pdf');

        $this->assertSame('tenants/42/elmak/exam.pdf', $key);
    }

    public function testBuildSanitizesTraversalInPlugin(): void
    {
        $key = StorageKey::build(42, '../admin', '../secret.php');

        $this->assertStringNotContainsString('..', $key);
        $this->assertStringStartsWith('tenants/42/', $key);
    }

    public function testBuildSanitizesBackslashesInPlugin(): void
    {
        $key = StorageKey::build(1, 'some\\plugin', 'file.txt');

        $this->assertStringNotContainsString('\\', $key);
    }

    public function testBuildSanitizesNullBytesInFilename(): void
    {
        $key = StorageKey::build(1, 'myplugin', "file\0name.txt");

        $this->assertStringNotContainsString("\0", $key);
    }

    public function testBuildReducesFilenameToBasename(): void
    {
        // Even if a traversal survives segment sanitization, basename() strips path info
        $key = StorageKey::build(5, 'plugin', 'subdir/actual.pdf');

        // The filename segment must only be the basename
        $this->assertSame('tenants/5/plugin/actual.pdf', $key);
    }

    public function testBuildStripsLeadingSlashesFromPlugin(): void
    {
        $key = StorageKey::build(3, '/leading/plugin', 'file.txt');

        $this->assertStringStartsWith('tenants/3/', $key);
        $this->assertStringNotContainsString('//', $key);
    }

    // ------------------------------------------------------------------
    // tenantId()
    // ------------------------------------------------------------------

    public function testTenantIdExtractsCorrectValue(): void
    {
        $result = StorageKey::tenantId('tenants/42/elmak/exam.pdf');

        $this->assertSame(42, $result);
    }

    public function testTenantIdReturnsNullForUnrecognisedKey(): void
    {
        $result = StorageKey::tenantId('bad-key');

        $this->assertNull($result);
    }

    public function testTenantIdReturnsNullForPartialKey(): void
    {
        $result = StorageKey::tenantId('tenants/42');

        $this->assertNull($result);
    }

    public function testTenantIdReturnsNullForNonNumericSegment(): void
    {
        $result = StorageKey::tenantId('tenants/abc/plugin/file.txt');

        $this->assertNull($result);
    }

    // ------------------------------------------------------------------
    // Round-trip
    // ------------------------------------------------------------------

    public function testRoundTripTenantIdMatchesBuildInput(): void
    {
        $key    = StorageKey::build(99, 'acme', 'report.xlsx');
        $result = StorageKey::tenantId($key);

        $this->assertSame(99, $result);
    }
}
