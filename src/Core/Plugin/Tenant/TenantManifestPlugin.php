<?php

declare(strict_types=1);

namespace Whity\Core\Plugin\Tenant;

use Whity\Sdk\Manifest\PluginManifest;
use Whity\Sdk\PluginInterface;
use Whity\Sdk\PluginRolesInterface;

/**
 * A workspace plugin seen through the plugin contract, so code that takes a
 * plugin (role seeding today) handles it the same way as a code plugin.
 *
 * It has no routes, hooks or migrations: a workspace plugin is data, and its
 * behaviour comes from core's fixed entry points.
 */
final class TenantManifestPlugin implements PluginInterface, PluginRolesInterface
{
    public function __construct(private readonly PluginManifest $manifest)
    {
    }

    public function manifest(): PluginManifest
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

    public function getRoutes(): array
    {
        return [];
    }

    public function getPermissions(): array
    {
        return $this->manifest->permissions();
    }

    public function getHooks(): array
    {
        return [];
    }

    public function getMigrations(): array
    {
        return [];
    }

    public function getRoles(): array
    {
        return $this->manifest->roles();
    }

    public function getRolePermissions(): array
    {
        return $this->manifest->rolePermissions();
    }
}
