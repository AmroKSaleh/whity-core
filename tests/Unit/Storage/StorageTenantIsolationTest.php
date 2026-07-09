<?php

declare(strict_types=1);

namespace Tests\Unit\Storage;

use PHPUnit\Framework\TestCase;
use Whity\Storage\LocalStorageDriver;
use Whity\Storage\StorageException;
use Whity\Storage\StorageKey;

/**
 * Tenant-isolation guarantees for the storage layer (WC-bd65aa0bb).
 *
 * The existing unit tests cover key building, per-segment sanitization, and the
 * driver's escape-root guard in isolation. This suite asserts the higher-level
 * SECURITY property they combine to provide: a tenant's stored objects are
 * confined to its own `tenants/{id}/…` prefix, and NOTHING a caller can put in a
 * plugin/filename segment — traversal, absolute paths, backslashes, null bytes —
 * lets it read, overwrite, or reach another tenant's objects. Tenant isolation
 * is the platform's #1 risk, so it gets an explicit, adversarial test.
 */
final class StorageTenantIsolationTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/storage-isolation-' . bin2hex(random_bytes(6));
        mkdir($this->root, 0700, true);
    }

    protected function tearDown(): void
    {
        if (!is_dir($this->root)) {
            return;
        }
        $it = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($this->root, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($it as $f) {
            $f->isDir() ? @rmdir($f->getPathname()) : @unlink($f->getPathname());
        }
        @rmdir($this->root);
    }

    // ── keys are confined to the caller's tenant ────────────────────────────────

    public function testSameFilenameForTwoTenantsProducesDistinctIsolatedObjects(): void
    {
        $driver = new LocalStorageDriver($this->root);

        $keyA = StorageKey::build(1, 'docs', 'report.pdf');
        $keyB = StorageKey::build(2, 'docs', 'report.pdf');
        self::assertNotSame($keyA, $keyB, 'identical filenames for different tenants must not collide');

        $driver->put($keyA, 'TENANT-A-BYTES');
        $driver->put($keyB, 'TENANT-B-BYTES');

        // Neither tenant's write clobbered the other's, and each reads only its own.
        self::assertSame('TENANT-A-BYTES', $driver->get($keyA));
        self::assertSame('TENANT-B-BYTES', $driver->get($keyB));
    }

    public function testTenantCannotReadAnotherTenantsObjectViaItsOwnKeyspace(): void
    {
        $driver = new LocalStorageDriver($this->root);
        $driver->put(StorageKey::build(1, 'docs', 'secret.pdf'), 'A-SECRET');

        // Tenant 2 building the "same" logical file gets a key into ITS prefix,
        // which does not exist — it can never resolve to tenant 1's bytes.
        $tenant2Key = StorageKey::build(2, 'docs', 'secret.pdf');
        self::assertFalse($driver->exists($tenant2Key));
    }

    /**
     * A caller that stuffs traversal into the PLUGIN segment trying to climb from
     * its own prefix into another tenant's directory is neutralised: the built key
     * still parses back to the caller's own tenant id.
     *
     * @dataProvider maliciousPluginSegments
     */
    public function testMaliciousPluginSegmentStaysWithinCallerTenant(string $evilPlugin): void
    {
        $key = StorageKey::build(1, $evilPlugin, 'file.pdf');
        self::assertSame(
            1,
            StorageKey::tenantId($key),
            "a crafted plugin segment must not re-home the key to another tenant (key={$key})"
        );
        self::assertStringStartsWith('tenants/1/', $key);
        self::assertStringNotContainsString('..', $key);
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function maliciousPluginSegments(): array
    {
        return [
            'parent traversal to tenant 2' => ['../2/docs'],
            'deep traversal'               => ['../../tenants/2/docs'],
            'backslash traversal'          => ['..\\..\\tenants\\2\\docs'],
            'absolute path'                => ['/tenants/2/docs'],
            'null byte + traversal'        => ["docs\0/../2"],
        ];
    }

    /**
     * The same guarantee for the FILENAME segment — reduced to its basename, so no
     * embedded path can point at another tenant.
     *
     * @dataProvider maliciousFilenames
     */
    public function testMaliciousFilenameStaysWithinCallerTenant(string $evilFilename): void
    {
        $key = StorageKey::build(1, 'docs', $evilFilename);
        self::assertSame(1, StorageKey::tenantId($key), "filename must not escape tenant (key={$key})");
        self::assertStringStartsWith('tenants/1/docs/', $key);
        self::assertStringNotContainsString('..', $key);
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function maliciousFilenames(): array
    {
        return [
            'traversal to sibling tenant' => ['../../2/docs/evil.pdf'],
            'absolute path'               => ['/tenants/2/docs/evil.pdf'],
            'backslash path'              => ['..\\..\\2\\evil.pdf'],
        ];
    }

    // ── the driver is a second line of defence ──────────────────────────────────

    public function testDriverRejectsRawCrossTenantTraversalKey(): void
    {
        // A key hand-crafted to bypass StorageKey and climb from tenant 1 into
        // tenant 2 (`tenants/1/../2/...`) contains '..' and MUST be refused by the
        // driver itself — defence in depth, independent of StorageKey.
        $driver = new LocalStorageDriver($this->root);
        $this->expectException(StorageException::class);
        $driver->put('tenants/1/../2/docs/evil.pdf', 'x');
    }

    public function testDriverContainsAllTenantObjectsUnderRoot(): void
    {
        // Every legitimately-built key resolves to a path under the configured
        // root — no tenant's objects can land outside the storage boundary.
        $driver = new LocalStorageDriver($this->root);
        $driver->put(StorageKey::build(7, 'docs', 'a.pdf'), 'x');

        $expected = $this->root . '/tenants/7/docs/a.pdf';
        self::assertFileExists($expected);
        $real = realpath($expected);
        $rootReal = realpath($this->root);
        self::assertNotFalse($real);
        self::assertNotFalse($rootReal);
        self::assertNotSame('', $rootReal);
        self::assertStringStartsWith($rootReal, $real);
    }

    // ── tenantId() supports an ownership check at the serving boundary ───────────

    public function testTenantIdEnablesOwnershipCheckAcrossTenants(): void
    {
        $callerTenant = 1;
        $ownKey     = StorageKey::build($callerTenant, 'docs', 'mine.pdf');
        $foreignKey = StorageKey::build(2, 'docs', 'theirs.pdf');

        // A serving handler can authorise by comparing the parsed tenant id.
        self::assertSame($callerTenant, StorageKey::tenantId($ownKey));
        self::assertNotSame($callerTenant, StorageKey::tenantId($foreignKey));
    }
}
