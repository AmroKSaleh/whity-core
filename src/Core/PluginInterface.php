<?php

declare(strict_types=1);

namespace Whity\Core;

/**
 * Host-side alias of the SDK plugin contract.
 *
 * @deprecated since WC-162 — implement {@see \Whity\Sdk\PluginInterface}
 * directly. The contract moved to the standalone `whity/plugin-sdk` package so
 * plugins never depend on whity-core. This alias keeps pre-SDK fixtures and
 * type references loading (every core-interface plugin IS an SDK plugin); the
 * loader and all host plumbing type-hint the SDK interface.
 */
interface PluginInterface extends \Whity\Sdk\PluginInterface
{
}
