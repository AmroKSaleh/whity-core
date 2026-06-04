<?php

declare(strict_types=1);

namespace Whity\Core;

/**
 * Lifecycle states a plugin can occupy within a worker process.
 *
 * The state machine progresses:
 *
 *   discovered -> loaded -> active -> failed -> disabled
 *                              ^                    |
 *                              \------ re-enable ---/
 *
 * - discovered: the plugin class was found on disk but not yet instantiated.
 * - loaded:     the plugin was instantiated and its capabilities registered.
 * - active:     the plugin is serving requests/hooks normally.
 * - failed:     the plugin exceeded the consecutive-error threshold and has been
 *               automatically taken out of service.
 * - disabled:   the plugin was administratively taken out of service.
 */
enum PluginState: string
{
    case Discovered = 'discovered';
    case Loaded = 'loaded';
    case Active = 'active';
    case Failed = 'failed';
    case Disabled = 'disabled';
}
