<?php

declare(strict_types=1);

namespace Whity\PluginHost;

/**
 * Stands in for production's Whity\Core\CoreVersion::VERSION so
 * PluginRequirementsGate can evaluate a plugin's getCoreConstraint() against
 * something, even though this offline host isn't whity-core itself. Inert
 * today: none of the plugins this host loads declare a core constraint.
 */
final class HostCoreVersion
{
    public const VERSION = '0.1.0';

    private function __construct()
    {
    }
}
