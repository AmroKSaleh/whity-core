<?php

declare(strict_types=1);

namespace DemoCatalog;

use DemoCatalog\Api\DemoCatalogApiHandler;
use DemoCatalog\Migrations\CreateDemoCatalogItemsTable;
use DemoCatalog\Migrations\GrantDemoCatalogPermissionsToAdmin;
use Whity\Sdk\Http\Request;
use Whity\Sdk\Http\Response;
use Whity\Sdk\PluginFrontendInterface;
use Whity\Sdk\PluginInterface;
use Whity\Sdk\PluginRequirementsInterface;

/**
 * DemoCatalogPlugin (multi-client feature-extraction pilot).
 *
 * A small, deliberately GENERIC example plugin — NOT modeled on any specific
 * downstream product's domain (exams/roster/question-bank, etc. belong to
 * downstream products like Elmak, never to whity-core itself). It exists
 * purely to give the `packages/features` shared-component extraction pilot a
 * real backend to bind against while proving the pattern:
 *
 *   this plugin's tenant-scoped `items` REST resource
 *     -> a `DemoCatalogAdapter` TS interface (defined in packages/features)
 *       -> `DemoCatalogList` / `DemoCatalogDetail` components (packages/features,
 *          zero Next.js deps, client-safe)
 *         -> wired into web/ via a server/api-client-backed adapter
 *            implementation (screen: 'custom', registered in
 *            web/lib/plugin-screens.tsx)
 *         -> wired into a minimal Vite SPA harness via an in-memory adapter
 *            implementation, proving the same components render outside Next.
 *
 * It contributes a tenant-scoped CRUD resource (list/get/create/update) over
 * its own `demo_catalog_items` table, gated on `demo_catalog:view` (reads) /
 * `demo_catalog:manage` (writes), and declares `screen: 'custom'` so the host
 * application supplies the bespoke UI (see PluginFrontendInterface) rather
 * than the generic schema-driven CRUD screen — the pilot's whole point is a
 * hand-built, multi-client-reusable UI, not the generic renderer.
 *
 * It lives in its own directory (`plugins/DemoCatalog/`) so the PluginLoader
 * resolves it under the `DemoCatalog` namespace prefix (directory name) and
 * auto-discovers it without any manual registration.
 */
final class DemoCatalogPlugin implements PluginInterface, PluginRequirementsInterface, PluginFrontendInterface
{
    /**
     * @inheritDoc
     */
    public function getName(): string
    {
        return 'DemoCatalog';
    }

    /**
     * @inheritDoc
     */
    public function getVersion(): string
    {
        return '1.0.0';
    }

    /**
     * @inheritDoc
     */
    public function getSdkConstraint(): string
    {
        return '^1.2';
    }

    /**
     * No host core-version constraint: the pilot runs against any core that
     * ships the SDK range it requires.
     *
     * @inheritDoc
     */
    public function getCoreConstraint(): string
    {
        return '';
    }

    /**
     * The pilot depends on no other plugin.
     *
     * @inheritDoc
     */
    public function getPluginDependencies(): array
    {
        return [];
    }

    /**
     * @inheritDoc
     */
    public function getRoutes(): array
    {
        return [
            [
                'method' => 'GET',
                'path' => '/api/demo-catalog/items',
                'handler' => [$this, 'listItems'],
                'requiredRole' => null,
                'requiredPermission' => 'demo_catalog:view',
                'schema' => [
                    'summary' => 'List the tenant\'s demo-catalog items (newest first)',
                    'tags' => ['demo-catalog'],
                    'responses' => [
                        200 => 'DemoCatalogItemListResponse',
                        403 => ['description' => 'Missing demo_catalog:view or unresolved tenant context'],
                    ],
                    'components' => self::itemComponents(),
                ],
            ],
            [
                'method' => 'GET',
                'path' => '/api/demo-catalog/items/{id:\d+}',
                'handler' => [$this, 'getItem'],
                'requiredRole' => null,
                'requiredPermission' => 'demo_catalog:view',
                'schema' => [
                    'summary' => 'Fetch one demo-catalog item (tenant-scoped 404 semantics)',
                    'tags' => ['demo-catalog'],
                    'responses' => [
                        200 => 'DemoCatalogItemResponse',
                        403 => ['description' => 'Missing demo_catalog:view or unresolved tenant context'],
                        404 => ['description' => 'Item not found in the caller\'s tenant'],
                    ],
                    'components' => self::itemComponents(),
                ],
            ],
            [
                'method' => 'POST',
                'path' => '/api/demo-catalog/items',
                'handler' => [$this, 'createItem'],
                'requiredRole' => null,
                'requiredPermission' => 'demo_catalog:manage',
                'schema' => [
                    'summary' => 'Create a demo-catalog item in the caller\'s tenant',
                    'tags' => ['demo-catalog'],
                    'request' => 'DemoCatalogItemInput',
                    'responses' => [
                        201 => 'DemoCatalogItemResponse',
                        400 => ['description' => 'Invalid name, description, or status'],
                        403 => ['description' => 'Missing demo_catalog:manage or unresolved tenant context'],
                    ],
                    'components' => self::itemComponents(),
                ],
            ],
            [
                'method' => 'PATCH',
                'path' => '/api/demo-catalog/items/{id:\d+}',
                'handler' => [$this, 'updateItem'],
                'requiredRole' => null,
                'requiredPermission' => 'demo_catalog:manage',
                'schema' => [
                    'summary' => 'Update a demo-catalog item (tenant-scoped 404 semantics)',
                    'tags' => ['demo-catalog'],
                    'request' => 'DemoCatalogItemInput',
                    'responses' => [
                        200 => 'DemoCatalogItemResponse',
                        400 => ['description' => 'Invalid name, description, or status'],
                        403 => ['description' => 'Missing demo_catalog:manage or unresolved tenant context'],
                        404 => ['description' => 'Item not found in the caller\'s tenant'],
                    ],
                    'components' => self::itemComponents(),
                ],
            ],
        ];
    }

    /**
     * The OpenAPI component schemas the items resource publishes.
     *
     * @return array<string, array<string, mixed>>
     */
    private static function itemComponents(): array
    {
        return [
            'DemoCatalogItem' => [
                'type' => 'object',
                'required' => ['id', 'tenantId', 'name', 'description', 'status', 'createdAt', 'updatedAt'],
                'properties' => [
                    'id' => ['type' => 'integer'],
                    'tenantId' => ['type' => 'integer'],
                    'name' => ['type' => 'string'],
                    'description' => ['type' => 'string', 'nullable' => true],
                    'status' => ['type' => 'string', 'enum' => ['active', 'archived']],
                    'createdAt' => ['type' => 'string', 'nullable' => true],
                    'updatedAt' => ['type' => 'string', 'nullable' => true],
                ],
            ],
            'DemoCatalogItemListResponse' => [
                'type' => 'object',
                'required' => ['data'],
                'properties' => [
                    'data' => [
                        'type' => 'array',
                        'items' => ['$ref' => '#/components/schemas/DemoCatalogItem'],
                    ],
                ],
            ],
            'DemoCatalogItemResponse' => [
                'type' => 'object',
                'required' => ['data'],
                'properties' => [
                    'data' => ['$ref' => '#/components/schemas/DemoCatalogItem'],
                ],
            ],
            'DemoCatalogItemInput' => [
                'type' => 'object',
                'required' => ['name'],
                'properties' => [
                    'name' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 255],
                    'description' => ['type' => 'string', 'maxLength' => 2000, 'nullable' => true],
                    'status' => ['type' => 'string', 'enum' => ['active', 'archived']],
                ],
            ],
        ];
    }

    /**
     * Declare the `screen: 'custom'` feature: the host app must register a
     * bespoke component for this id (see web/lib/plugin-screens.tsx).
     *
     * @inheritDoc
     */
    public function getFrontendFeatures(): array
    {
        return [
            [
                'id' => 'demo-catalog',
                'label' => 'Demo Catalog',
                'icon' => 'box',
                'group' => 'plugins',
                'order' => 30,
                'screen' => 'custom',
                'requiredPermission' => 'demo_catalog:view',
            ],
        ];
    }

    /**
     * @inheritDoc
     */
    public function getPermissions(): array
    {
        return [
            'demo_catalog:view',
            'demo_catalog:manage',
        ];
    }

    /**
     * No hooks — the pilot plugin observes no platform events.
     *
     * @inheritDoc
     */
    public function getHooks(): array
    {
        return [];
    }

    /**
     * @inheritDoc
     */
    public function getMigrations(): array
    {
        return [
            CreateDemoCatalogItemsTable::class,
            GrantDemoCatalogPermissionsToAdmin::class,
        ];
    }

    /**
     * Handle GET /api/demo-catalog/items (requires demo_catalog:view).
     *
     * @param Request $request The incoming HTTP request.
     * @param array<string, string> $params Captured path parameters.
     * @return Response The tenant-scoped item list.
     */
    public function listItems(Request $request, array $params = []): Response
    {
        return $this->handler()->list($request);
    }

    /**
     * Handle GET /api/demo-catalog/items/{id} (requires demo_catalog:view).
     *
     * @param Request $request The incoming HTTP request.
     * @param array<string, string> $params Captured path parameters ('id').
     * @return Response The item or a tenant-scoped 404.
     */
    public function getItem(Request $request, array $params = []): Response
    {
        return $this->handler()->get($request, $params);
    }

    /**
     * Handle POST /api/demo-catalog/items (requires demo_catalog:manage).
     *
     * @param Request $request The incoming HTTP request.
     * @param array<string, string> $params Captured path parameters.
     * @return Response The created item (201) or a validation error.
     */
    public function createItem(Request $request, array $params = []): Response
    {
        return $this->handler()->create($request);
    }

    /**
     * Handle PATCH /api/demo-catalog/items/{id} (requires demo_catalog:manage).
     *
     * @param Request $request The incoming HTTP request.
     * @param array<string, string> $params Captured path parameters ('id').
     * @return Response The updated item or a tenant-scoped 404.
     */
    public function updateItem(Request $request, array $params = []): Response
    {
        return $this->handler()->update($request, $params);
    }

    /**
     * Build the items handler with a freshly resolved PDO.
     *
     * Resolved PER REQUEST (not cached) so the host's connection self-healing
     * and recycling are honoured — a cached handle would pin a connection the
     * host may have already replaced.
     *
     * @return DemoCatalogApiHandler The DB-backed CRUD handler.
     */
    private function handler(): DemoCatalogApiHandler
    {
        return new DemoCatalogApiHandler($this->resolvePdo());
    }

    /**
     * Resolve a live PDO from the host's service container.
     *
     * @return \PDO Live database connection.
     */
    private function resolvePdo(): \PDO
    {
        $database = \Whity\app(\Whity\Database\Database::class);
        if (!$database instanceof \Whity\Database\Database) {
            throw new \RuntimeException('The host did not register the shared Database service');
        }

        return $database->getPdo();
    }
}
