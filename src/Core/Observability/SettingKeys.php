<?php

declare(strict_types=1);

namespace Whity\Core\Observability;

use Whity\Core\Settings\SettingsRegistry;

/**
 * The error-tracking setting keys, re-exported locally (WC-error-tracking).
 *
 * Keeps {@see ErrorTrackerFactory} readable at its call sites and gives this
 * subsystem one place to look for "which settings drive me", without importing
 * the whole registry into every file.
 */
final class SettingKeys
{
    public const ENABLED = SettingsRegistry::ERROR_TRACKING_ENABLED;
    public const PROVIDER = SettingsRegistry::ERROR_TRACKING_PROVIDER;
    public const ENVIRONMENT = SettingsRegistry::ERROR_TRACKING_ENVIRONMENT;
    public const NOTIFY_ADMINS = SettingsRegistry::ERROR_TRACKING_NOTIFY_ADMINS;
    public const RETENTION_DAYS = SettingsRegistry::ERROR_TRACKING_RETENTION_DAYS;
}
