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

    // ── Environment bootstrap (#786) ─────────────────────────────────────────

    /**
     * A database with no storage rows honours the deployment's environment.
     *
     * This is the reported defect. The factory read the MERGED settings view,
     * where an unset `storage.driver` reads 'local' from the registry default —
     * so a deployment that configured S3 purely through its environment got the
     * local disk, and every upload still answered 200. Nothing surfaced it until
     * somebody went looking in the bucket for files that were never written.
     */
    public function testAFreshDatabaseTakesItsDriverFromTheEnvironment(): void
    {
        $driver = StorageDriverFactory::fromSettings(
            $this->settings(),
            [
                'STORAGE_DRIVER' => 's3',
                'STORAGE_S3_ENDPOINT' => 'https://s3.us-east-1.amazonaws.com',
                'STORAGE_S3_REGION' => 'us-east-1',
                'STORAGE_S3_BUCKET' => 'whity-bucket',
                'STORAGE_S3_ACCESS_KEY' => 'AKIAEXAMPLE',
                'STORAGE_S3_SECRET_KEY' => 'super-secret-key-value-1234567890',
            ],
            sys_get_temp_dir()
        );

        self::assertInstanceOf(S3StorageDriver::class, $driver);
    }

    /**
     * An explicitly stored value beats the environment.
     *
     * The env is the BOOTSTRAP path, not an override: once an operator has
     * chosen a backend in the admin UI, a leftover environment variable must not
     * quietly move storage out from under them. Note this is only decidable
     * against the STORED rows — 'local' here is also the registry default, so a
     * merged-view read could not tell this case from the one above.
     */
    public function testAStoredDriverBeatsTheEnvironment(): void
    {
        $settings = $this->settings();
        $settings->setGlobal(SettingsRegistry::STORAGE_DRIVER, 'local');

        $driver = StorageDriverFactory::fromSettings(
            $settings,
            [
                'STORAGE_DRIVER' => 's3',
                'STORAGE_S3_ENDPOINT' => 'https://s3.us-east-1.amazonaws.com',
                'STORAGE_S3_REGION' => 'us-east-1',
                'STORAGE_S3_BUCKET' => 'whity-bucket',
                'STORAGE_S3_ACCESS_KEY' => 'AKIAEXAMPLE',
                'STORAGE_S3_SECRET_KEY' => 'super-secret-key-value-1234567890',
            ],
            sys_get_temp_dir()
        );

        self::assertInstanceOf(LocalStorageDriver::class, $driver);
    }

    /** Settings and environment compose per-key: each stored value wins on its own. */
    public function testStoredAndEnvironmentValuesComposePerKey(): void
    {
        $settings = $this->settings();
        $settings->setGlobal(SettingsRegistry::STORAGE_DRIVER, 's3');
        $settings->setGlobal(SettingsRegistry::STORAGE_S3_BUCKET, 'bucket-from-settings');

        $driver = StorageDriverFactory::fromSettings(
            $settings,
            [
                'STORAGE_S3_BUCKET' => 'bucket-from-env',
                'STORAGE_S3_ENDPOINT' => 'https://s3.us-east-1.amazonaws.com',
                'STORAGE_S3_REGION' => 'us-east-1',
                'STORAGE_S3_ACCESS_KEY' => 'AKIAEXAMPLE',
                'STORAGE_S3_SECRET_KEY' => 'super-secret-key-value-1234567890',
            ],
            sys_get_temp_dir()
        );

        self::assertInstanceOf(S3StorageDriver::class, $driver);
        self::assertSame('bucket-from-settings', self::bucketOf($driver));
    }

    /**
     * Selecting s3 from the environment with the rest missing still throws.
     *
     * The fail-loud rule is not weakened by the fallback: an incomplete S3
     * configuration must never degrade to local, because writes and reads would
     * then split across two backends.
     */
    public function testEnvironmentDriverWithIncompleteConfigStillThrows(): void
    {
        $this->expectException(StorageException::class);
        StorageDriverFactory::fromSettings(
            $this->settings(),
            ['STORAGE_DRIVER' => 's3', 'STORAGE_S3_SECRET_KEY' => 'super-secret-key-value-1234567890'],
            sys_get_temp_dir()
        );
    }

    /** The configured bucket, read back off the built driver. */
    private static function bucketOf(S3StorageDriver $driver): string
    {
        $config = (new \ReflectionProperty($driver, 'config'))->getValue($driver);
        self::assertInstanceOf(\Whity\Storage\S3\S3Config::class, $config);

        return $config->bucket;
    }
}
