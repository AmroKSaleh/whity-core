<?php

declare(strict_types=1);

// The top-level namespace MUST equal the plugin's directory name
// (WC221UploadFixture): the host PluginLoader maps each plugins/<Dir>/ to the
// PSR-4 prefix <Dir>\, so a directory plugin's class must live under that
// prefix for the loader to resolve it on Enable (mirrors the HelloWorld
// reference plugin's `namespace HelloWorld;`). A mismatched namespace stages
// fine (introspection uses the SDK autoloader) but cannot be enabled in-host.
namespace WC221UploadFixture;

use Whity\Sdk\PluginInterface;

/**
 * E2E upload fixture (WC-221).
 *
 * A minimal, dependency-free plugin used ONLY by the plugin-upload E2E spec to
 * exercise the upload -> staged-disabled -> Enable flow against the real stack.
 * It declares NO routes, NO permissions, NO hooks, and NO migrations, so
 * staging and enabling it have no RBAC or database side effects — the spec can
 * upload and uninstall it cleanly on a shared dev environment.
 *
 * It is NOT a product plugin and is deliberately kept OUT of the repo `plugins/`
 * directory (plugin-repo hygiene). The spec packages this directory into a .zip
 * at runtime and uploads it; cleanup uninstalls it so re-runs never collide.
 */
final class Plugin implements PluginInterface
{
    public function getName(): string
    {
        // Must match the archive's single top-level directory name and the
        // filesystem-safe allowlist /^[A-Za-z0-9_-]+$/ enforced by the installer.
        return 'WC221UploadFixture';
    }

    public function getVersion(): string
    {
        return '1.0.0';
    }

    public function getRoutes(): array
    {
        return [];
    }

    public function getPermissions(): array
    {
        return [];
    }

    public function getHooks(): array
    {
        return [];
    }

    public function getMigrations(): array
    {
        return [];
    }
}
