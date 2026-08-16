<?php

declare(strict_types=1);

namespace Whity\Core\DesktopPlugins;

use RuntimeException;

/**
 * Raised for any release-pipeline failure the operator must see and fix: an
 * invalid name/version, a namespace that would not autoload on the device, a
 * package that trips a size/entry guard, or a duplicate (plugin_name, version).
 */
final class DesktopPluginReleaseException extends RuntimeException
{
}
