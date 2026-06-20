<?php

declare(strict_types=1);

namespace Tests\Unit\Storage;

use PHPUnit\Framework\TestCase;
use Whity\Storage\LocalStorageDriver;

final class LocalStorageDriverTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/branding-test-' . bin2hex(random_bytes(6));
        mkdir($this->root, 0700, true);
    }

    protected function tearDown(): void
    {
        // Best-effort recursive cleanup.
        $it = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($this->root, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($it as $f) {
            $f->isDir() ? @rmdir($f->getPathname()) : @unlink($f->getPathname());
        }
        @rmdir($this->root);
    }

    public function testPutGetExistsDelete(): void
    {
        $d = new LocalStorageDriver($this->root);
        $key = 'tenants/1/branding/logo_wide-abc.png';
        self::assertFalse($d->exists($key));
        $d->put($key, 'PNGBYTES');
        self::assertTrue($d->exists($key));
        self::assertSame('PNGBYTES', $d->get($key));
        self::assertSame(8, $d->size($key));
        $d->delete($key);
        self::assertFalse($d->exists($key));
    }

    public function testMimeTypeFromExtension(): void
    {
        $d = new LocalStorageDriver($this->root);
        $d->put('tenants/1/branding/favicon-x.ico', 'x');
        self::assertSame('image/x-icon', $d->mimeType('tenants/1/branding/favicon-x.ico'));
        $d->put('tenants/1/branding/logo-x.svg', '<svg/>');
        self::assertSame('image/svg+xml', $d->mimeType('tenants/1/branding/logo-x.svg'));
    }

    public function testKeyCannotEscapeRoot(): void
    {
        $d = new LocalStorageDriver($this->root);
        // StorageKey-style sanitization happens upstream; the driver must also
        // refuse traversal so a crafted key can never write outside root.
        $this->expectException(\Whity\Storage\StorageException::class);
        $d->put('../../etc/evil', 'x');
    }

    public function testBareDotDotKeyIsRejected(): void
    {
        $d = new LocalStorageDriver($this->root);
        // A bare '..' (no trailing slash) must also be rejected — the original
        // guard only checked for '../' and would have missed this edge case.
        $this->expectException(\Whity\Storage\StorageException::class);
        $d->put('..', 'x');
    }

    public function testPublicUrlNotSupported(): void
    {
        $d = new LocalStorageDriver($this->root);
        $this->expectException(\RuntimeException::class);
        $d->publicUrl('tenants/1/branding/logo_wide-abc.png');
    }
}
