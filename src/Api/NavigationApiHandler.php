<?php

namespace Whity\Api;

use Whity\Core\Request;
use Whity\Core\Response;
use Whity\Core\Hooks\HookManager;

class NavigationApiHandler
{
    private HookManager $hookManager;

    public function __construct(HookManager $hookManager)
    {
        $this->hookManager = $hookManager;
    }

    /**
     * GET /api/navigation - Get all registered navigation items
     */
    public function list(Request $request): Response
    {
        try {
            // Dispatch hook for plugins to register navigation items
            $result = $this->hookManager->dispatch('navigation.register', [
                'items' => [],
            ]);
            $items = $result['items'] ?? [];

            // Sort items by group then by order
            usort($items, function ($a, $b) {
                $groupCompare = ($a['group'] ?? 'default') <=> ($b['group'] ?? 'default');
                if ($groupCompare !== 0) {
                    return $groupCompare;
                }
                return ($a['order'] ?? 999) <=> ($b['order'] ?? 999);
            });

            return Response::json(['data' => $items], 200);
        } catch (\Exception $e) {
            return Response::error('Failed to fetch navigation: ' . $e->getMessage(), 500);
        }
    }
}
