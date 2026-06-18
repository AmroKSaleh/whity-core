<?php

declare(strict_types=1);

namespace Whity\Core\Exception;

/**
 * Raised when a plugin's derived name is not a safe filesystem path segment (WC-220).
 *
 * The plugin name (from {@see \Whity\Sdk\PluginInterface::getName()} and/or the
 * archive's top-level directory) becomes a path under `plugins/`, so it is a
 * security boundary: it MUST match `^[A-Za-z0-9_-]+$` — no separators, no dots,
 * no traversal. Maps to HTTP 400.
 */
final class PluginNameUnsafe extends PluginInstallException
{
}
