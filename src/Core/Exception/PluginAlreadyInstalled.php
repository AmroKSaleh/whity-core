<?php

declare(strict_types=1);

namespace Whity\Core\Exception;

/**
 * Raised when a plugin with the derived name already exists on disk (WC-220).
 *
 * v1 rejects any collision (`plugins/<Name>/`, `plugins/<Name>.php`, or
 * `plugins/<Name>.php.disabled`) rather than overwriting or upgrading. Maps to
 * HTTP 409.
 */
final class PluginAlreadyInstalled extends PluginInstallException
{
}
