<?php

declare(strict_types=1);

namespace Whity\Core\Plugin\Tenant;

use Whity\Sdk\Manifest\PluginManifest;
use Whity\Sdk\PluginNamespace;

/**
 * What a WORKSPACE's own plugin may declare, on top of the manifest format
 * (Whity Builder, WHIT-760; docs/design/WHITY-BUILDER.md 2.8).
 *
 * The format is shared with code plugins, which an operator installs and
 * trusts. A workspace plugin is written by a customer or their agent and runs
 * for one workspace, so it may not reach anything that would act beyond it:
 *
 * - no `tables`, `dataTypes` or `limits`: a workspace plugin owns no tables
 *   (its records live in the shared record store, WHIT-761), and limits are
 *   the operator's to set, in a catalogue sealed at boot;
 * - no dependencies on other plugins yet;
 * - a name no install-wide plugin uses, so nothing it declares can shadow one;
 * - permissions only under its own name (`cake_orders:read` for a plugin
 *   called CakeOrders or cake_orders), so it can't declare a slug core or
 *   another plugin gates something with;
 * - roles whose parent is one of its own roles, so a role it suggests can't
 *   inherit an administrator's permissions;
 * - screens that read and write only its own record routes, `/api/x/<slug>/`.
 */
final class TenantManifestPolicy
{
    /** Static helper only — never instantiated. */
    private function __construct()
    {
    }

    /**
     * @param list<string> $installedPluginNames Names of install-wide plugins.
     * @return list<string> Errors; empty when the manifest is allowed.
     */
    public static function check(PluginManifest $manifest, array $installedPluginNames): array
    {
        $errors = [];
        $data = $manifest->toArray();
        $name = $manifest->name();
        $slug = PluginNamespace::slug($name);

        foreach (['tables', 'dataTypes', 'limits'] as $key) {
            if (isset($data[$key])) {
                $errors[] = "{$key}: a workspace plugin can't declare {$key}";
            }
        }
        if (($data['requires']['plugins'] ?? []) !== []) {
            $errors[] = 'requires.plugins: a workspace plugin can\'t depend on other plugins yet';
        }

        $lower = strtolower($name);
        foreach ($installedPluginNames as $installed) {
            if (strtolower($installed) === $lower || PluginNamespace::slug($installed) === $slug) {
                $errors[] = "plugin.name: \"{$name}\" is taken by a plugin installed for every workspace";
                break;
            }
        }

        foreach ($manifest->permissions() as $i => $permission) {
            $resource = explode(':', $permission, 2)[0];
            if ($slug === null || ($resource !== $slug && !str_starts_with($resource, $slug . '_'))) {
                $errors[] = "permissions[{$i}].slug: \"{$permission}\" must start with \"{$slug}:\" or \"{$slug}_\"";
            }
        }

        $roleNames = array_keys($manifest->roles());
        foreach ($manifest->roles() as $role => $declaration) {
            $parent = $declaration['parent'] ?? null;
            if ($parent !== null && !in_array($parent, $roleNames, true)) {
                $errors[] = "roles.{$role}.parent: must be another role this plugin declares";
            }
        }

        $prefix = '/api/x/' . ($slug ?? '') . '/';
        foreach ($manifest->frontendFeatures() as $i => $feature) {
            foreach (self::apiPaths($feature['blocks'] ?? []) as $path) {
                if (!str_starts_with($path, $prefix)) {
                    $errors[] = "screens[{$i}].blocks: \"{$path}\" is outside this plugin's own routes ({$prefix})";
                }
            }
        }

        return $errors;
    }

    /**
     * Every string in a block tree that names an API path. Scanning every
     * value, not a list of known props, means a block type added later can't
     * open a path this check doesn't know about.
     *
     * @return list<string>
     */
    private static function apiPaths(mixed $node): array
    {
        if (is_string($node)) {
            $trimmed = ltrim($node);
            return str_starts_with($trimmed, '/api/') || str_starts_with($trimmed, 'api/') ? [$trimmed] : [];
        }
        if (!is_array($node)) {
            return [];
        }
        $paths = [];
        foreach ($node as $value) {
            $paths = [...$paths, ...self::apiPaths($value)];
        }

        return $paths;
    }
}
