<?php

declare(strict_types=1);

namespace Whity\Api;

use Whity\PluginHost\PluginRuntimeLoader;
use Whity\Sdk\Frontend\Blocks\BlockValidator;
use Whity\Sdk\PluginFrontendInterface;
use Whity\Sdk\Rbac\PermissionResolver;

/**
 * Serves `GET /__whity/frontend-features` — the offline host's counterpart
 * to production's `GET /api/v1/frontend/features` (`FrontendFeaturesApiHandler`),
 * letting the desktop app's generic block renderer discover and render an
 * installed plugin's `screen:'blocks'` UI with zero per-feature TypeScript
 * (see templates/tauri-desktop/src/plugin-blocks/).
 *
 * Deliberately NARROWER than production's handler: this offline host runs a
 * single trusted, server-entitled plugin set per device, so the
 * cross-plugin permission-OWNERSHIP policing production does (a permission
 * must be declared via the SAME plugin's getPermissions(), and the first
 * plugin to declare a given basePath/path "owns" it) doesn't pay for itself
 * here — every loaded plugin was already vetted by the server's release
 * pipeline before it ever reached this device. What's kept: the same
 * fail-closed permission GATE (an unresolvable/ungranted `requiredPermission`
 * drops the feature) and the same BlockValidator re-validation (a plugin
 * that somehow declares an invalid tree is dropped, logged, never 500s).
 *
 * Also narrower in SCOPE: only `screen:'blocks'` features carry their full
 * declaration (the desktop renderer doesn't yet support `crud`/`action`/
 * `embed`/`custom`) — other screens still list minimally (id/label/screen)
 * so a future desktop renderer for them doesn't need a new offline endpoint,
 * but `plugin-nav-provider.tsx` skips them for now (see its own doc comment).
 */
final class FrontendFeaturesHandler
{
    public function __construct(
        private readonly PluginRuntimeLoader $loader,
        private readonly PermissionResolver $permissionResolver,
        private readonly int $profileId,
        private readonly int $tenantId,
    ) {
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function list(): array
    {
        $features = [];

        foreach ($this->loader->getLoadedPlugins() as $loaded) {
            if (!$loaded->plugin instanceof PluginFrontendInterface) {
                continue;
            }

            try {
                $declared = $loaded->plugin->getFrontendFeatures();
            } catch (\Throwable $e) {
                error_log("[php-host] {$loaded->plugin->getName()}::getFrontendFeatures() threw: " . $e->getMessage());
                continue;
            }

            foreach ($declared as $feature) {
                $public = $this->toPublicFeature($feature, $loaded->plugin->getName());
                if ($public !== null) {
                    $features[] = $public;
                }
            }
        }

        return $features;
    }

    /**
     * @param mixed $feature One entry from a plugin's getFrontendFeatures().
     * @return array<string, mixed>|null Null when the descriptor is malformed,
     *   the caller lacks its permission, or (for a `blocks` screen) the tree
     *   fails validation — every case is logged, never fatal.
     */
    private function toPublicFeature(mixed $feature, string $pluginName): ?array
    {
        if (!is_array($feature)) {
            return null;
        }

        $id = $feature['id'] ?? null;
        $label = $feature['label'] ?? null;
        $screen = $feature['screen'] ?? null;
        $permission = $feature['requiredPermission'] ?? null;

        if (!is_string($id) || $id === '' || !is_string($label) || $label === '' || !is_string($screen) || !is_string($permission) || $permission === '') {
            error_log("[php-host] {$pluginName} frontend feature dropped: missing id/label/screen/requiredPermission");
            return null;
        }

        if (!$this->permissionResolver->hasPermission($this->profileId, $this->tenantId, $permission)) {
            return null;
        }

        $public = [
            'id' => $id,
            'plugin' => $pluginName,
            'label' => $label,
            'icon' => is_string($feature['icon'] ?? null) ? $feature['icon'] : null,
            'group' => is_string($feature['group'] ?? null) && $feature['group'] !== '' ? $feature['group'] : 'plugins',
            'order' => is_int($feature['order'] ?? null) ? $feature['order'] : 100,
            'screen' => $screen,
            'requiredPermission' => $permission,
        ];

        if ($screen === 'blocks') {
            $blocks = $feature['blocks'] ?? null;
            if (!is_array($blocks)) {
                error_log("[php-host] {$pluginName} frontend feature '{$id}' dropped: screen 'blocks' with no blocks array");
                return null;
            }
            $result = BlockValidator::validate($blocks);
            if (!$result['ok']) {
                error_log("[php-host] {$pluginName} frontend feature '{$id}' dropped: invalid block tree — " . implode('; ', $result['errors']));
                return null;
            }
            $public['blocks'] = $blocks;
        }

        return $public;
    }
}
