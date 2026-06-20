<?php

declare(strict_types=1);

namespace Tests\Unit\Core\Settings;

use PHPUnit\Framework\TestCase;
use Tests\Support\SchemaFromMigrations;
use Whity\Core\Settings\GlobalSettingsRepository;
use Whity\Core\Settings\SettingsService;
use Whity\Core\Settings\SettingsValidationException;
use Whity\Core\Settings\TenantSettingsRepository;

final class SettingsServiceAssetTest extends TestCase
{
    private function service(\PDO $pdo): SettingsService
    {
        return new SettingsService(
            new GlobalSettingsRepository($pdo),
            new TenantSettingsRepository($pdo)
        );
    }

    public function testTextSetterRejectsAssetKey(): void
    {
        $pdo = SchemaFromMigrations::make(true);
        $this->expectException(SettingsValidationException::class);
        $this->service($pdo)->setGlobal('branding_logo_wide', 'x');
    }

    public function testSetGlobalAssetStoresAndClears(): void
    {
        $pdo = SchemaFromMigrations::make(true);
        $svc = $this->service($pdo);
        $svc->setGlobalAsset('branding_logo_wide', 'tenants/0/branding/logo_wide-abc.png');
        self::assertSame('tenants/0/branding/logo_wide-abc.png', $svc->getGlobal()['branding_logo_wide']);
        $svc->setGlobalAsset('branding_logo_wide', null);
        self::assertSame('', $svc->getGlobal()['branding_logo_wide']);
    }

    public function testSetTenantAssetRejectsSystemTenant(): void
    {
        $pdo = SchemaFromMigrations::make(true);
        $this->expectException(SettingsValidationException::class);
        $this->service($pdo)->setTenantAsset(0, 'branding_favicon', 'tenants/0/branding/favicon-x.ico');
    }

    public function testSetTenantAssetStoresPerTenant(): void
    {
        $pdo = SchemaFromMigrations::make(true);
        $pdo->exec("INSERT INTO tenants (id, name) VALUES (1, 't1')");
        $svc = $this->service($pdo);
        $svc->setTenantAsset(1, 'branding_favicon', 'tenants/1/branding/favicon-z.ico');
        self::assertSame('tenants/1/branding/favicon-z.ico', $svc->effective(1)['branding_favicon']);
    }
}
