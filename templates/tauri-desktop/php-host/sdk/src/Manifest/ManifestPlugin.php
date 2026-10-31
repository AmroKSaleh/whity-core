<?php

declare(strict_types=1);

namespace Whity\Sdk\Manifest;

use ReflectionClass;
use Whity\Sdk\DataType\PluginDataTypesInterface;
use Whity\Sdk\PluginEntitlementsInterface;
use Whity\Sdk\PluginFrontendInterface;
use Whity\Sdk\PluginInterface;
use Whity\Sdk\PluginRequirementsInterface;
use Whity\Sdk\PluginRolesInterface;
use Whity\Sdk\Tenant\PluginTablesInterface;

/**
 * A plugin declared in `plugin.json` (SDK 1.47, WHIT-758).
 *
 * Extend it with an empty class next to the manifest and the host loads the
 * plugin like any other:
 *
 * ```php
 * namespace CakeOrders;
 *
 * final class CakeOrdersPlugin extends \Whity\Sdk\Manifest\ManifestPlugin
 * {
 * }
 * ```
 *
 * Every capability is answered from the manifest in the shape the matching
 * interface already defines, so nothing in the host knows or cares that the
 * declarations came from JSON. That is what "one runtime" means: a builder-made
 * plugin, an exported one and a hand-written one go through the same loader,
 * the same registries and the same checks.
 *
 * HYBRID BY OVERRIDING. A subclass may override any method to add what JSON
 * can't express (routes and their handlers, hooks, migrations, jobs), and may
 * call the parent to keep the declared part:
 *
 * ```php
 * public function getPermissions(): array
 * {
 *     return [...parent::getPermissions(), 'cake_orders:export'];
 * }
 * ```
 *
 * AN INVALID MANIFEST REFUSES THE WHOLE PLUGIN. The constructor throws
 * {@see InvalidManifestException} with every error, and the host's loader
 * already treats a plugin that throws while being constructed as one that
 * doesn't load. Loading half a manifest would leave a role granting a
 * permission that was dropped, or a screen gated by one, which is worse than
 * not loading.
 */
abstract class ManifestPlugin implements
    PluginInterface,
    PluginRequirementsInterface,
    PluginRolesInterface,
    PluginFrontendInterface,
    PluginTablesInterface,
    PluginDataTypesInterface,
    PluginEntitlementsInterface
{
    /** The file name the manifest is read from, next to the subclass. */
    public const FILE_NAME = 'plugin.json';

    private readonly PluginManifest $manifest;

    /**
     * @throws InvalidManifestException
     */
    public function __construct()
    {
        $this->manifest = PluginManifest::fromFile($this->manifestPath());
        if ($this->manifest->records() !== []) {
            // The record store serves workspace plugins (WHIT-761). An
            // install-wide plugin keeps its records in its own tables, declared
            // under `tables` and `dataTypes`; accepting `records` here and
            // serving nothing would be a silent no-op.
            throw new InvalidManifestException($this->manifestPath(), [
                'records: only a workspace plugin keeps records in the shared store; an installed plugin declares tables and dataTypes',
            ]);
        }
    }

    /**
     * Where the manifest lives: `plugin.json` in the subclass's own directory.
     * Override to read it from elsewhere.
     */
    protected function manifestPath(): string
    {
        $file = (new ReflectionClass($this))->getFileName();

        return dirname((string) $file) . DIRECTORY_SEPARATOR . self::FILE_NAME;
    }

    /** The validated manifest, for subclasses that build on it. */
    final protected function manifest(): PluginManifest
    {
        return $this->manifest;
    }

    public function getName(): string
    {
        return $this->manifest->name();
    }

    public function getVersion(): string
    {
        return $this->manifest->version();
    }

    /** JSON declares no handlers; a subclass adds routes in PHP. */
    public function getRoutes(): array
    {
        return [];
    }

    public function getPermissions(): array
    {
        return $this->manifest->permissions();
    }

    /** JSON declares no callbacks; a subclass adds hooks in PHP. */
    public function getHooks(): array
    {
        return [];
    }

    /** Tables declared under `tables` are created by migrations a subclass ships. */
    public function getMigrations(): array
    {
        return [];
    }

    public function getSdkConstraint(): string
    {
        return $this->manifest->sdkConstraint();
    }

    public function getCoreConstraint(): string
    {
        return $this->manifest->coreConstraint();
    }

    public function getPluginDependencies(): array
    {
        return $this->manifest->pluginDependencies();
    }

    public function getRoles(): array
    {
        return $this->manifest->roles();
    }

    public function getRolePermissions(): array
    {
        return $this->manifest->rolePermissions();
    }

    public function getFrontendFeatures(): array
    {
        return $this->manifest->frontendFeatures();
    }

    public function getOwnedTables(): array
    {
        return $this->manifest->ownedTables();
    }

    public function getDataTypes(): array
    {
        return $this->manifest->dataTypes();
    }

    public function getEntitlements(): array
    {
        return $this->manifest->entitlements();
    }
}
