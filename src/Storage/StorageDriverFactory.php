<?php

declare(strict_types=1);

namespace Whity\Storage;

use Whity\Core\Settings\SettingsRegistry;
use Whity\Core\Settings\SettingsService;
use Whity\Storage\S3\S3Config;
use Whity\Storage\S3\S3StorageDriver;
use Whity\Storage\S3\StreamObjectHttpTransport;

/**
 * Builds the active {@see StorageDriverInterface} from GLOBAL instance settings
 * (WC-b8c5a271 / WC-28fb2e19) — the operator's "local ↔ cloud" storage switch.
 *
 * `storage.driver` selects the backend:
 *   - 'local' (default) → {@see LocalStorageDriver} rooted at the configured path.
 *   - 's3'              → {@see S3StorageDriver} from the `storage.s3.*` settings.
 *
 * The S3 SECRET KEY is deliberately NOT a setting: it is a deployment secret read
 * from the `STORAGE_S3_SECRET_KEY` env, never stored in app_settings nor exposed
 * on the settings API. (A future secret-settings kind can move it into the admin
 * UI via EncryptedSecretStore; until then the secret lives in the environment.)
 *
 * Fail-safe: any non-'s3' driver value yields the local driver, so normal boot is
 * unaffected. Selecting 's3' with incomplete config throws a clear
 * {@see StorageException} rather than silently degrading (which could split writes
 * and reads across backends and lose data).
 */
final class StorageDriverFactory
{
    /**
     * @param array<string, mixed> $env             Environment map (for the S3 secret).
     * @param string               $defaultLocalRoot Root for the local driver.
     */
    public static function fromSettings(
        SettingsService $settings,
        array $env,
        string $defaultLocalRoot,
    ): StorageDriverInterface {
        $global = $settings->getGlobal();
        $driver = $global[SettingsRegistry::STORAGE_DRIVER] ?? 'local';

        if ($driver !== 's3') {
            return new LocalStorageDriver($defaultLocalRoot);
        }

        $endpoint  = trim((string) ($global[SettingsRegistry::STORAGE_S3_ENDPOINT] ?? ''));
        $region    = trim((string) ($global[SettingsRegistry::STORAGE_S3_REGION] ?? ''));
        $bucket    = trim((string) ($global[SettingsRegistry::STORAGE_S3_BUCKET] ?? ''));
        $accessKey = trim((string) ($global[SettingsRegistry::STORAGE_S3_ACCESS_KEY] ?? ''));
        $secretKey = (string) ($env['STORAGE_S3_SECRET_KEY'] ?? getenv('STORAGE_S3_SECRET_KEY') ?: '');

        $missing = [];
        if ($endpoint === '')  { $missing[] = 'storage.s3.endpoint'; }
        if ($region === '')    { $missing[] = 'storage.s3.region'; }
        if ($bucket === '')    { $missing[] = 'storage.s3.bucket'; }
        if ($accessKey === '') { $missing[] = 'storage.s3.access_key'; }
        if ($secretKey === '') { $missing[] = 'STORAGE_S3_SECRET_KEY (env)'; }
        if ($missing !== []) {
            throw new StorageException(
                'storage.driver is "s3" but the S3 configuration is incomplete: missing '
                . implode(', ', $missing)
            );
        }

        $publicBaseUrl = trim((string) ($global[SettingsRegistry::STORAGE_S3_PUBLIC_BASE_URL] ?? ''));

        $config = new S3Config(
            endpoint: $endpoint,
            region: $region,
            bucket: $bucket,
            accessKey: $accessKey,
            secretKey: $secretKey,
            pathStyle: ($global[SettingsRegistry::STORAGE_S3_PATH_STYLE] ?? 'true') === 'true',
            publicBaseUrl: $publicBaseUrl !== '' ? $publicBaseUrl : null,
        );

        return new S3StorageDriver($config, new StreamObjectHttpTransport());
    }
}
