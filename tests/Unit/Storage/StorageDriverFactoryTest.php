<?php

declare(strict_types=1);

namespace Tests\Unit\Storage;

use PHPUnit\Framework\TestCase;
use Tests\Support\SchemaFromMigrations;
use Whity\Core\Settings\GlobalSettingsRepository;
use Whity\Core\Settings\SettingsRegistry;
use Whity\Core\Settings\SettingsService;
use Whity\Core\Settings\TenantSettingsRepository;
use Whity\Storage\LocalStorageDriver;
use Whity\Storage\S3\S3StorageDriver;
use Whity\Storage\StorageDriverFactory;
use Whity\Storage\StorageException;

/**
 * The settings-driven storage backend switch (WC-b8c5a271 / WC-28fb2e19).
 */
final class StorageDriverFactoryTest extends TestCase
{
    private function settings(): SettingsService
    {
        $pdo = SchemaFromMigrations::make(true);
        return new SettingsService(
            new GlobalSettingsRepository($pdo),
            new TenantSettingsRepository($pdo)
        );
    }

    public function testDefaultsToLocalDriver(): void
    {
        $driver = StorageDriverFactory::fromSettings($this->settings(), [], sys_get_temp_dir());
        self::assertInstanceOf(LocalStorageDriver::class, $driver);
    }

    public function testExplicitLocalDriver(): void
    {
        $settings = $this->settings();
        $settings->setGlobal(SettingsRegistry::STORAGE_DRIVER, 'local');
        $driver = StorageDriverFactory::fromSettings($settings, [], sys_get_temp_dir());
        self::assertInstanceOf(LocalStorageDriver::class, $driver);
    }

    public function testBuildsS3DriverFromCompleteConfig(): void
    {
        $settings = $this->settings();
        $settings->setGlobal(SettingsRegistry::STORAGE_DRIVER, 's3');
        $settings->setGlobal(SettingsRegistry::STORAGE_S3_ENDPOINT, 'https://s3.us-east-1.amazonaws.com');
        $settings->setGlobal(SettingsRegistry::STORAGE_S3_REGION, 'us-east-1');
        $settings->setGlobal(SettingsRegistry::STORAGE_S3_BUCKET, 'whity-bucket');
        $settings->setGlobal(SettingsRegistry::STORAGE_S3_ACCESS_KEY, 'AKIAEXAMPLE');

        $driver = StorageDriverFactory::fromSettings(
            $settings,
            ['STORAGE_S3_SECRET_KEY' => 'super-secret-key-value-1234567890'],
            sys_get_temp_dir()
        );
        self::assertInstanceOf(S3StorageDriver::class, $driver);
    }

    public function testS3WithoutSecretThrows(): void
    {
        $settings = $this->settings();
        $settings->setGlobal(SettingsRegistry::STORAGE_DRIVER, 's3');
        $settings->setGlobal(SettingsRegistry::STORAGE_S3_ENDPOINT, 'https://s3.us-east-1.amazonaws.com');
        $settings->setGlobal(SettingsRegistry::STORAGE_S3_REGION, 'us-east-1');
        $settings->setGlobal(SettingsRegistry::STORAGE_S3_BUCKET, 'whity-bucket');
        $settings->setGlobal(SettingsRegistry::STORAGE_S3_ACCESS_KEY, 'AKIAEXAMPLE');

        // No STORAGE_S3_SECRET_KEY in env → incomplete → throws (no silent fallback).
        $this->expectException(StorageException::class);
        StorageDriverFactory::fromSettings($settings, [], sys_get_temp_dir());
    }

    public function testS3WithMissingBucketThrows(): void
    {
        $settings = $this->settings();
        $settings->setGlobal(SettingsRegistry::STORAGE_DRIVER, 's3');
        $settings->setGlobal(SettingsRegistry::STORAGE_S3_ENDPOINT, 'https://s3.us-east-1.amazonaws.com');
        $settings->setGlobal(SettingsRegistry::STORAGE_S3_REGION, 'us-east-1');
        $settings->setGlobal(SettingsRegistry::STORAGE_S3_ACCESS_KEY, 'AKIAEXAMPLE');

        $this->expectException(StorageException::class);
        StorageDriverFactory::fromSettings(
            $settings,
            ['STORAGE_S3_SECRET_KEY' => 'super-secret-key-value-1234567890'],
            sys_get_temp_dir()
        );
    }

    public function testInvalidDriverValueIsRejectedAtSettingsWrite(): void
    {
        // The registry validates the enum, so a bad driver can never be persisted.
        $reason = SettingsRegistry::validate(SettingsRegistry::STORAGE_DRIVER, 'ftp');
        self::assertNotNull($reason);
        self::assertNull(SettingsRegistry::validate(SettingsRegistry::STORAGE_DRIVER, 's3'));
        self::assertNull(SettingsRegistry::validate(SettingsRegistry::STORAGE_DRIVER, 'local'));
    }
}
