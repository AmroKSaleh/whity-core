<?php

declare(strict_types=1);

namespace Whity\Core\Plugin\Tenant;

use Whity\Core\Hooks\HookManager;
use Whity\Core\PluginNavigationBridge;
use Whity\Core\Tenant\TenantContext;

/**
 * Menu entries for a workspace's own plugin screens (WHIT-760), built the same
 * way {@see PluginNavigationBridge} builds them for install-wide plugins.
 *
 * Read at dispatch time from the request's workspace, so a publish shows up on
 * the next request and one workspace's screens never appear in another's menu.
 * The navigation handler then applies each item's `requiredPermission` as it
 * does for every other item.
 */
final class TenantPluginNavigation
{
    private function __construct()
    {
    }

    public static function subscribe(HookManager $hooks, TenantManifestHost $host): void
    {
        $hooks->listen(
            'navigation.register',
            /**
             * @param array<string, mixed> $data
             * @return array<string, mixed>
             */
            static function (array $data) use ($host): array {
                $tenantId = TenantContext::getTenantId();
                if ($tenantId === null) {
                    return $data;
                }
                $items = is_array($data['items'] ?? null) ? $data['items'] : [];
                $taken = [];
                foreach ($items as $item) {
                    if (is_array($item) && isset($item['id'])) {
                        $taken[(string) $item['id']] = true;
                    }
                }
                foreach ($host->frontendFeatures($tenantId) as $feature) {
                    $item = PluginNavigationBridge::toNavItem($feature);
                    if (!isset($taken[$item['id']])) {
                        $items[] = $item;
                        $taken[$item['id']] = true;
                    }
                }

                return ['items' => $items] + $data;
            }
        );
    }
}
