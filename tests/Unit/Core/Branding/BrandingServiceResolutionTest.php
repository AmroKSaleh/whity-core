<?php

declare(strict_types=1);

namespace Tests\Unit\Core\Branding;

use PHPUnit\Framework\TestCase;
use Tests\Support\SchemaFromMigrations;
use Whity\Core\Branding\BrandingService;
use Whity\Core\Settings\GlobalSettingsRepository;
use Whity\Core\Settings\SettingsService;
use Whity\Core\Settings\TenantSettingsRepository;

final class BrandingServiceResolutionTest extends TestCase
{
    private function service(\PDO $pdo): BrandingService
    {
        $settings = new SettingsService(
            new GlobalSettingsRepository($pdo),
            new TenantSettingsRepository($pdo)
        );
        return new BrandingService($settings);
    }

    public function testDefaultsWhenNothingSet(): void
    {
        $pdo = SchemaFromMigrations::make(true);
        $pdo->exec("INSERT INTO tenants (id, name) VALUES (1, 't1')");
        $b = $this->service($pdo)->effective(1);
        self::assertSame('Whity', $b->siteName);
        self::assertNull($b->logoWideUrl);
        self::assertNull($b->logoSquareUrl);
        self::assertNull($b->faviconUrl);
    }

    public function testTenantOverridesGlobal(): void
    {
        $pdo = SchemaFromMigrations::make(true);
        $pdo->exec("INSERT INTO tenants (id, name) VALUES (1, 't1')");
        $settings = new SettingsService(new GlobalSettingsRepository($pdo), new TenantSettingsRepository($pdo));
        $settings->setGlobal('site_name', 'Platform');
        $settings->setTenant(1, 'site_name', 'Acme');
        $settings->setGlobalAsset('branding_logo_wide', 'tenants/0/branding/logo_wide-aaa.png');
        $settings->setTenantAsset(1, 'branding_logo_wide', 'tenants/1/branding/logo_wide-bbb.png');
        $b = (new BrandingService($settings))->effective(1);
        self::assertSame('Acme', $b->siteName);
        self::assertSame('/api/v1/branding/asset/1/logo_wide-bbb.png', $b->logoWideUrl);
    }

    public function testFaviconFallsBackToSquareLogo(): void
    {
        $pdo = SchemaFromMigrations::make(true);
        $pdo->exec("INSERT INTO tenants (id, name) VALUES (1, 't1')");
        $settings = new SettingsService(new GlobalSettingsRepository($pdo), new TenantSettingsRepository($pdo));
        $settings->setTenantAsset(1, 'branding_logo_square', 'tenants/1/branding/logo_square-sq.png');
        $b = (new BrandingService($settings))->effective(1);
        self::assertSame('/api/v1/branding/asset/1/logo_square-sq.png', $b->faviconUrl);
    }

    public function testEachKeyResolvesIndependently(): void
    {
        $pdo = SchemaFromMigrations::make(true);
        $pdo->exec("INSERT INTO tenants (id, name) VALUES (1, 't1')");
        $settings = new SettingsService(new GlobalSettingsRepository($pdo), new TenantSettingsRepository($pdo));
        $settings->setGlobal('site_name', 'Platform');
        $settings->setTenantAsset(1, 'branding_logo_wide', 'tenants/1/branding/logo_wide-ind.png');
        $b = (new BrandingService($settings))->effective(1);
        // site_name has no tenant override → falls back to global layer
        self::assertSame('Platform', $b->siteName);
        // branding_logo_wide is set at the tenant layer → resolves from tenant
        self::assertSame('/api/v1/branding/asset/1/logo_wide-ind.png', $b->logoWideUrl);
    }

    public function testEffectiveExposesOnlyBrandingFields(): void
    {
        $pdo = SchemaFromMigrations::make(true);
        $pdo->exec("INSERT INTO tenants (id, name) VALUES (1, 't1')");
        $settings = new SettingsService(new GlobalSettingsRepository($pdo), new TenantSettingsRepository($pdo));
        $settings->setTenant(1, 'support_email', 'secret@acme.com');
        $arr = (new BrandingService($settings))->effective(1)->toArray();
        self::assertSame(['siteName', 'logoWideUrl', 'logoSquareUrl', 'faviconUrl'], array_keys($arr));
        self::assertStringNotContainsString('secret@acme.com', (string) json_encode($arr));
    }
}
