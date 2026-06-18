<?php

declare(strict_types=1);

namespace Tests\Unit\Core\Settings;

use PDO;
use PHPUnit\Framework\TestCase;
use Whity\Core\Settings\GlobalSettingsRepository;
use Whity\Core\Settings\SettingsService;
use Whity\Core\Settings\SettingsValidationException;
use Whity\Core\Settings\TenantSettingsRepository;

/**
 * Unit tests for {@see SettingsService} resolution precedence and validated
 * writes, driving the REAL repositories against in-memory SQLite (so the
 * tenant_settings tenant predicate is exercised on a genuine engine).
 */
final class SettingsServiceTest extends TestCase
{
    private const TENANT_A = 1;
    private const TENANT_B = 2;

    private PDO $pdo;
    private SettingsService $service;

    protected function setUp(): void
    {
        $this->pdo = new PDO('sqlite::memory:');
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->pdo->setAttribute(PDO::ATTR_STRINGIFY_FETCHES, true);
        $this->pdo->sqliteCreateFunction('NOW', static fn (): string => date('Y-m-d H:i:s'), 0);

        $this->pdo->exec('
            CREATE TABLE app_settings (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                setting_key TEXT NOT NULL UNIQUE,
                value TEXT,
                updated_at TEXT NOT NULL DEFAULT (datetime(\'now\'))
            )
        ');
        $this->pdo->exec('
            CREATE TABLE tenant_settings (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                tenant_id INTEGER NOT NULL,
                setting_key TEXT NOT NULL,
                value TEXT,
                updated_at TEXT NOT NULL DEFAULT (datetime(\'now\')),
                UNIQUE (tenant_id, setting_key)
            )
        ');

        $this->service = new SettingsService(
            new GlobalSettingsRepository($this->pdo),
            new TenantSettingsRepository($this->pdo)
        );
    }

    public function testEffectiveFallsBackToRegistryDefaultsWhenNothingStored(): void
    {
        $effective = $this->service->effective(self::TENANT_A);

        self::assertSame('Whity', $effective['site_name']);
        self::assertSame('UTC', $effective['timezone']);
        self::assertSame('en', $effective['locale']);
        self::assertSame('', $effective['support_email']);
    }

    public function testEffectivePrefersGlobalOverDefault(): void
    {
        $this->service->setGlobal('site_name', 'Platform');

        self::assertSame('Platform', $this->service->effective(self::TENANT_A)['site_name']);
    }

    public function testEffectivePrefersTenantOverrideOverGlobalAndDefault(): void
    {
        $this->service->setGlobal('site_name', 'Platform');
        $this->service->setTenant(self::TENANT_A, 'site_name', 'Tenant A Co');

        self::assertSame('Tenant A Co', $this->service->effective(self::TENANT_A)['site_name']);
        // Tenant B has no override → sees the global.
        self::assertSame('Platform', $this->service->effective(self::TENANT_B)['site_name']);
    }

    public function testSystemTenantResolvesGlobalsOnlyAndSkipsTenantLayer(): void
    {
        $this->service->setGlobal('site_name', 'Platform');
        // Even if a row somehow existed for tenant 0, it must be ignored — but
        // setTenant for tenant 0 is itself rejected (asserted separately). Here
        // the system tenant simply sees the global, never a tenant override.
        $this->service->setTenant(self::TENANT_A, 'site_name', 'Tenant A Co');

        self::assertSame(
            'Platform',
            $this->service->effective(SettingsService::SYSTEM_TENANT_ID)['site_name']
        );
        self::assertSame([], $this->service->overriddenKeys(SettingsService::SYSTEM_TENANT_ID));
    }

    public function testOverriddenKeysReportsOnlyTenantLayerKeys(): void
    {
        $this->service->setGlobal('timezone', 'Europe/Berlin');
        $this->service->setTenant(self::TENANT_A, 'site_name', 'Tenant A Co');

        self::assertSame(['site_name'], $this->service->overriddenKeys(self::TENANT_A));
    }

    public function testClearingTenantOverrideFallsBackToGlobalThenDefault(): void
    {
        $this->service->setGlobal('site_name', 'Platform');
        $this->service->setTenant(self::TENANT_A, 'site_name', 'Tenant A Co');
        self::assertSame('Tenant A Co', $this->service->effective(self::TENANT_A)['site_name']);

        // Clear the override (null) → falls back to the global.
        $this->service->setTenant(self::TENANT_A, 'site_name', null);
        self::assertSame('Platform', $this->service->effective(self::TENANT_A)['site_name']);

        // Clear the global too → falls back to the registry default.
        $this->service->setGlobal('site_name', null);
        self::assertSame('Whity', $this->service->effective(self::TENANT_A)['site_name']);
    }

    public function testGetGlobalResolvesGlobalsThenDefaultsIgnoringTenantLayer(): void
    {
        $this->service->setGlobal('timezone', 'Europe/Berlin');
        $this->service->setTenant(self::TENANT_A, 'timezone', 'Asia/Tokyo');

        $global = $this->service->getGlobal();
        self::assertSame('Europe/Berlin', $global['timezone']);
        self::assertSame('Whity', $global['site_name']); // unset → default
    }

    public function testSetTenantRejectsInvalidValueAndPersistsNothing(): void
    {
        try {
            $this->service->setTenant(self::TENANT_A, 'timezone', 'Mars/Phobos');
            self::fail('Expected a validation exception for an invalid timezone.');
        } catch (SettingsValidationException $e) {
            self::assertSame('timezone', $e->settingKey());
        }

        self::assertSame('UTC', $this->service->effective(self::TENANT_A)['timezone']);
    }

    public function testSetGlobalRejectsUnknownKey(): void
    {
        $this->expectException(SettingsValidationException::class);
        $this->service->setGlobal('not_a_key', 'x');
    }

    public function testSetTenantRejectsSystemTenant(): void
    {
        $this->expectException(SettingsValidationException::class);
        $this->service->setTenant(SettingsService::SYSTEM_TENANT_ID, 'site_name', 'Nope');
    }
}
