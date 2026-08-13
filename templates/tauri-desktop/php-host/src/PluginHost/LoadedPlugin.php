<?php

declare(strict_types=1);

namespace Whity\PluginHost;

use Whity\Sdk\PluginInterface;

/**
 * A plugin instance the loader has instantiated, paired with its FQCN
 * (needed for logging/migration tracking without re-deriving it via
 * reflection every time).
 */
final class LoadedPlugin
{
    /**
     * @param class-string<PluginInterface> $fqcn
     */
    public function __construct(
        public readonly PluginInterface $plugin,
        public readonly string $fqcn,
    ) {
    }
}
