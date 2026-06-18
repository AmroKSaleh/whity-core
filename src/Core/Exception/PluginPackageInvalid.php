<?php

declare(strict_types=1);

namespace Whity\Core\Exception;

/**
 * Raised when an uploaded package is not a usable plugin (WC-220).
 *
 * Covers: a missing/empty upload, a transport error, a file that is neither a
 * `.zip` nor a `.php` (by content), an archive that contains zero plugin
 * classes implementing {@see \Whity\Sdk\PluginInterface}, or more than one
 * plugin class (v1 installs exactly one plugin per package). Maps to HTTP 400.
 */
final class PluginPackageInvalid extends PluginInstallException
{
}
