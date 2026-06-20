<?php

declare(strict_types=1);

namespace Tests\Unit\Core\Branding;

use PHPUnit\Framework\TestCase;
use Tests\Support\SchemaFromMigrations;
use Whity\Core\Branding\BrandingAssetValidator;
use Whity\Core\Branding\BrandingService;
use Whity\Core\Branding\SvgSanitizer;
use Whity\Core\Settings\GlobalSettingsRepository;
use Whity\Core\Settings\SettingsService;
use Whity\Core\Settings\TenantSettingsRepository;
use Whity\Storage\LocalStorageDriver;

final class BrandingServiceUploadTest extends TestCase
{
    private function build(\PDO $pdo, string $root): BrandingService
    {
        $settings = new SettingsService(new GlobalSettingsRepository($pdo), new TenantSettingsRepository($pdo));
        return new BrandingService($settings, new LocalStorageDriver($root), new BrandingAssetValidator(new SvgSanitizer()));
    }

    private function png(): string
    {
        return "\x89PNG\r\n\x1a\n" . str_repeat("\0", 16);
    }

    public function testUploadStoresAndReferences(): void
    {
        $pdo = SchemaFromMigrations::make(true);
        $pdo->exec("INSERT INTO tenants (id, name) VALUES (1, 't1')");
        $root = sys_get_temp_dir() . '/bsu-' . bin2hex(random_bytes(5));
        $svc = $this->build($pdo, $root);

        $key = $svc->uploadAsset(1, 'logo_wide', $this->png());
        self::assertStringStartsWith('tenants/1/branding/logo_wide-', $key);
        self::assertStringEndsWith('.png', $key);

        $b = $svc->effective(1);
        self::assertSame('/api/v1/branding/asset/1/' . basename($key), $b->logoWideUrl);
    }

    public function testReuploadReplacesOldObject(): void
    {
        $pdo = SchemaFromMigrations::make(true);
        $pdo->exec("INSERT INTO tenants (id, name) VALUES (1, 't1')");
        $root = sys_get_temp_dir() . '/bsu-' . bin2hex(random_bytes(5));
        $storage = new LocalStorageDriver($root);
        $settings = new SettingsService(new GlobalSettingsRepository($pdo), new TenantSettingsRepository($pdo));
        $svc = new BrandingService($settings, $storage, new BrandingAssetValidator(new SvgSanitizer()));

        $first = $svc->uploadAsset(1, 'logo_wide', $this->png());
        $second = $svc->uploadAsset(1, 'logo_wide', $this->png() . 'different');
        self::assertNotSame($first, $second);
        self::assertFalse($storage->exists($first));   // old object removed
        self::assertTrue($storage->exists($second));
    }

    public function testClearRemovesObjectAndSetting(): void
    {
        $pdo = SchemaFromMigrations::make(true);
        $pdo->exec("INSERT INTO tenants (id, name) VALUES (1, 't1')");
        $root = sys_get_temp_dir() . '/bsu-' . bin2hex(random_bytes(5));
        $storage = new LocalStorageDriver($root);
        $settings = new SettingsService(new GlobalSettingsRepository($pdo), new TenantSettingsRepository($pdo));
        $svc = new BrandingService($settings, $storage, new BrandingAssetValidator(new SvgSanitizer()));

        $key = $svc->uploadAsset(1, 'logo_wide', $this->png());
        $svc->clearAsset(1, 'logo_wide');
        self::assertFalse($storage->exists($key));
        self::assertNull($svc->effective(1)->logoWideUrl);
    }
}
