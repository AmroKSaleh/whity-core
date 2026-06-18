<?php

declare(strict_types=1);

namespace Whity\Core\Exception;

/**
 * Raised when a plugin's declared migrations fail to apply during Enable (WC-220).
 *
 * The migration-on-enable path applies a plugin's not-yet-recorded migrations
 * BEFORE it serves traffic. If any migration's up() fails, the plugin is left
 * DISABLED (sentinel intact) and this typed error is surfaced — never the raw
 * migration/PDO message. Maps to HTTP 422 (the plugin is valid, but its schema
 * could not be applied on this host).
 */
final class PluginEnableMigrationFailed extends PluginInstallException
{
}
