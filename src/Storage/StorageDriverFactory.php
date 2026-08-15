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
 * Each value is read from SETTINGS FIRST, then the ENVIRONMENT (#786). A stored
 * value is an operator decision made through the admin UI and always wins; the
 * env is the BOOTSTRAP path, for a deployment whose database carries no storage
 * rows yet. Before that fallback existed a fresh database silently used local
 * disk with the deployment's S3 environment sitting unread — and every upload
 * still answered 200, so nothing surfaced it until somebody went looking in the
 * bucket for files that were never there.
 *
 * The S3 SECRET KEY is the one value that is env-ONLY: it is a deployment secret,
 * never stored in app_settings nor exposed on the settings API. (A future
 * secret-settings kind can move it into the admin UI via EncryptedSecretStore;
 * until then the secret lives only in the environment.)
 *
 * Fail-safe: any non-'s3' driver value yields the local driver, so normal boot is
 * unaffected. Selecting 's3' with incomplete config throws a clear
 * {@see StorageException} rather than silently degrading (which could split writes
 * and reads across backends and lose data).
 */
final class StorageDriverFactory
{
    /**
     * @param array<string, mixed> $env             Environment map (bootstrap config + the S3 secret).
     * @param string               $defaultLocalRoot Root for the local driver.
     */
    public static function fromSettings(
        SettingsService $settings,
        array $env,
        string $defaultLocalRoot,
    ): StorageDriverInterface {
        // STORED globals, deliberately not the merged view: getGlobal() fills
        // every unset key from the registry, so `storage.driver` reads 'local'
        // on a database that has never been configured — indistinguishable from
        // an operator choosing local, and enough to make the env fallback below
        // dead code. The registry defaults are reapplied here as the last
        // resort, so an unconfigured instance still resolves exactly as before.
        $global = $settings->storedGlobals();

        $driver = self::setting($global, SettingsRegistry::STORAGE_DRIVER, $env, 'STORAGE_DRIVER', 'local');

        if ($driver !== 's3') {
            return new LocalStorageDriver($defaultLocalRoot);
        }

        $endpoint  = self::setting($global, SettingsRegistry::STORAGE_S3_ENDPOINT, $env, 'STORAGE_S3_ENDPOINT');
        $region    = self::setting($global, SettingsRegistry::STORAGE_S3_REGION, $env, 'STORAGE_S3_REGION');
        $bucket    = self::setting($global, SettingsRegistry::STORAGE_S3_BUCKET, $env, 'STORAGE_S3_BUCKET');
        $accessKey = self::setting($global, SettingsRegistry::STORAGE_S3_ACCESS_KEY, $env, 'STORAGE_S3_ACCESS_KEY');
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

        $publicBaseUrl = self::setting($global, SettingsRegistry::STORAGE_S3_PUBLIC_BASE_URL, $env, 'STORAGE_S3_PUBLIC_BASE_URL');
        // Path-style addressing defaults to ON: it is what self-hosted S3-compatible
        // stores (MinIO, Ceph) need, and those are the ones reached by env bootstrap.
        $pathStyle = self::setting($global, SettingsRegistry::STORAGE_S3_PATH_STYLE, $env, 'STORAGE_S3_PATH_STYLE', 'true');

        $config = new S3Config(
            endpoint: $endpoint,
            region: $region,
            bucket: $bucket,
            accessKey: $accessKey,
            secretKey: $secretKey,
            pathStyle: $pathStyle === 'true',
            publicBaseUrl: $publicBaseUrl !== '' ? $publicBaseUrl : null,
        );

        return new S3StorageDriver($config, new StreamObjectHttpTransport());
    }

    /**
     * A stored setting, falling back to the environment, then to a default.
     *
     * An EMPTY stored value counts as absent: a settings row written blank by an
     * admin form should not shadow a configured environment, which would leave a
     * deployment silently on local disk with its S3 environment ignored — the
     * exact failure this fallback exists to remove.
     *
     * @param array<string, mixed> $global The global settings map.
     * @param array<string, mixed> $env    Environment map.
     */
    private static function setting(
        array $global,
        string $settingKey,
        array $env,
        string $envKey,
        string $default = '',
    ): string {
        $stored = trim((string) ($global[$settingKey] ?? ''));
        if ($stored !== '') {
            return $stored;
        }

        $fromEnv = trim((string) ($env[$envKey] ?? ''));
        if ($fromEnv === '') {
            $fromEnv = trim((string) (getenv($envKey) ?: ''));
        }

        return $fromEnv !== '' ? $fromEnv : $default;
    }
}
