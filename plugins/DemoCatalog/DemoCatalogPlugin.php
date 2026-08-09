<?php

declare(strict_types=1);

namespace DemoCatalog;

use DemoCatalog\Api\DemoCatalogApiHandler;
use DemoCatalog\Migrations\AddSyncColumnsToDemoCatalogItems;
use DemoCatalog\Migrations\CreateDemoCatalogItemNotesTable;
use DemoCatalog\Migrations\CreateDemoCatalogItemsTable;
use DemoCatalog\Migrations\GrantDemoCatalogPermissionsToAdmin;
use Whity\Sdk\DataType\PluginDataTypesInterface;
use Whity\Sdk\Http\Request;
use Whity\Sdk\Http\Response;
use Whity\Sdk\PluginFrontendInterface;
use Whity\Sdk\PluginInterface;
use Whity\Sdk\PluginRequirementsInterface;
use Whity\Sdk\Rbac\PluginResourceTypesInterface;
use Whity\Sdk\Tenant\PluginTablesInterface;

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
 * It contributes a tenant-scoped, SYNC-CAPABLE resource (list/get/create/update/
 * delete + a changes feed) over its own `demo_catalog_items` table, gated on
 * `demo_catalog:view` (reads) / `demo_catalog:manage` (writes), and declares
 * `screen: 'custom'` so the host application supplies the bespoke UI (see
 * PluginFrontendInterface) rather than the generic schema-driven CRUD screen —
 * the pilot's whole point is a hand-built, multi-client-reusable UI.
 *
 * It lives in its own directory (`plugins/DemoCatalog/`) so the PluginLoader
 * resolves it under the `DemoCatalog` namespace prefix (directory name) and
 * auto-discovers it without any manual registration.
 */
final class DemoCatalogPlugin implements
    PluginInterface,
    PluginRequirementsInterface,
    PluginFrontendInterface,
    PluginResourceTypesInterface,
    PluginTablesInterface,
    PluginDataTypesInterface
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
        return '1.1.0';
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
                    'summary' => 'List the tenant\'s live items (newest first), or — with '
                        . '?updatedSince=<cursor>&includeDeleted=1&limit=N — the incremental '
                        . 'changes feed (rows incl. tombstones with change_seq > cursor)',
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
                    'summary' => 'Create an item in the caller\'s tenant (idempotent on clientUuid)',
                    'tags' => ['demo-catalog'],
                    'request' => 'DemoCatalogItemInput',
                    'responses' => [
                        201 => 'DemoCatalogItemResponse',
                        200 => 'DemoCatalogItemResponse',
                        400 => ['description' => 'Invalid name, description, or status'],
                        403 => ['description' => 'Missing demo_catalog:manage or unresolved/system tenant context'],
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
                    'summary' => 'Update an item; If-Match/baseVersion enables optimistic concurrency',
                    'tags' => ['demo-catalog'],
                    'request' => 'DemoCatalogItemInput',
                    'responses' => [
                        200 => 'DemoCatalogItemResponse',
                        400 => ['description' => 'Invalid name, description, or status'],
                        403 => ['description' => 'Missing demo_catalog:manage or unresolved tenant context'],
                        404 => ['description' => 'Item not found in the caller\'s tenant'],
                        409 => 'DemoCatalogConflictResponse',
                    ],
                    'components' => self::itemComponents(),
                ],
            ],
            [
                'method' => 'DELETE',
                'path' => '/api/demo-catalog/items/{id:\d+}',
                'handler' => [$this, 'deleteItem'],
                'requiredRole' => null,
                'requiredPermission' => 'demo_catalog:manage',
                'schema' => [
                    'summary' => 'Soft-delete (tombstone) an item; If-Match/baseVersion guarded, idempotent',
                    'tags' => ['demo-catalog'],
                    'responses' => [
                        200 => 'DemoCatalogItemResponse',
                        403 => ['description' => 'Missing demo_catalog:manage or unresolved tenant context'],
                        404 => ['description' => 'Item not found in the caller\'s tenant'],
                        409 => 'DemoCatalogConflictResponse',
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
                'required' => [
                    'id', 'tenantId', 'clientUuid', 'name', 'description', 'status',
                    'version', 'deletedAt', 'updatedBy', 'createdAt', 'updatedAt',
                ],
                'properties' => [
                    'id' => ['type' => 'integer'],
                    'tenantId' => ['type' => 'integer'],
                    'clientUuid' => ['type' => 'string', 'nullable' => true],
                    'name' => ['type' => 'string'],
                    'description' => ['type' => 'string', 'nullable' => true],
                    'status' => ['type' => 'string', 'enum' => ['active', 'archived']],
                    'version' => ['type' => 'integer'],
                    'deletedAt' => ['type' => 'string', 'nullable' => true],
                    'updatedBy' => ['type' => 'integer', 'nullable' => true],
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
            'DemoCatalogConflictResponse' => [
                'type' => 'object',
                'required' => ['error', 'serverItem'],
                'properties' => [
                    'error' => ['type' => 'string'],
                    'serverItem' => ['$ref' => '#/components/schemas/DemoCatalogItem'],
                ],
            ],
            'DemoCatalogItemInput' => [
                'type' => 'object',
                'required' => ['name'],
                'properties' => [
                    'name' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 255],
                    'description' => ['type' => 'string', 'maxLength' => 2000, 'nullable' => true],
                    'status' => ['type' => 'string', 'enum' => ['active', 'archived']],
                    'clientUuid' => ['type' => 'string', 'maxLength' => 36],
                    'baseVersion' => ['type' => 'integer'],
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
     * The resource types this plugin owns (WC-712 §2).
     *
     * Declared as the BARE slug. The host namespaces it under this plugin's
     * name, so the canonical type is `democatalog:item` — another plugin
     * declaring `item` gets its own, and neither can shadow a core type.
     *
     * Declaring the type is what makes `resource_role_assignments` usable for
     * catalogue items: authority can be addressed at ONE item rather than
     * requiring a private grant table.
     *
     * @inheritDoc
     */
    public function getResourceTypes(): array
    {
        return ['item'];
    }

    /**
     * The tables this plugin owns (WC-723 Piece 1).
     *
     * Declaring them is what earns the right to declare a referential guard
     * over them. The host stamps the OWNER from this plugin's name — this method
     * says which tables, never who said so — and refuses anything core or an
     * earlier plugin already claimed.
     *
     * `demo_catalog_change_seq` is deliberately GLOBAL: it is a one-row
     * monotonic counter with no tenant column, so no tenant predicate could be
     * bound to it. Declaring that honestly is what stops a data type or a guard
     * ever being built over it.
     *
     * @inheritDoc
     */
    public function getOwnedTables(): array
    {
        return [
            'demo_catalog_items' => self::SCOPE_TENANT,
            'demo_catalog_item_notes' => self::SCOPE_TENANT,
            'demo_catalog_change_seq' => self::SCOPE_GLOBAL,
        ];
    }

    /**
     * The data types this plugin owns — the Door 2 reference implementation
     * (WC-723).
     *
     * One bare slug, `item`, which the host namespaces to `democatalog:item`.
     * The declaration hands core exactly three things and nothing more:
     *
     *  - WHERE the record lives (`demo_catalog_items`, keyed by `id`, scoped by
     *    `tenant_id`);
     *  - WHAT its lifecycle states mean — `trashed` is a mistake pending
     *    removal, `retired` is a finished item that other rows still resolve
     *    against, and the two are not the same thing;
     *  - WHICH rows still point at it (`demo_catalog_item_notes.item_id`), and
     *    what to CALL them when refusing a delete ("catalogue notes").
     *
     * `ignore_when` says a note that is itself trashed does not keep its item
     * alive — without it a trashed child would pin its parent forever.
     *
     * Note on the sync tombstone: `deleted_at` remains the offline-sync
     * transport's own concern (it is how a deletion propagates to a client) and
     * is untouched by the lifecycle. Lifecycle state lives in `status`, and the
     * two answer different questions — "what is this record now?" versus "what
     * must the next pull be told?".
     *
     * @inheritDoc
     */
    public function getDataTypes(): array
    {
        return [
            'item' => [
                'table' => 'demo_catalog_items',
                'key' => 'id',
                'tenant_column' => 'tenant_id',
                'label' => ['en' => 'Catalogue item', 'ar' => 'عنصر الكتالوج'],
                'lifecycle' => [
                    'column' => 'status',
                    'states' => ['active', 'archived', 'retired', 'trashed'],
                    'default_state' => 'active',
                    'trashable' => true,
                    'retirable' => true,
                    'trashed_state' => 'trashed',
                    'retired_state' => 'retired',
                ],
                'blocks_delete' => [
                    [
                        'table' => 'demo_catalog_item_notes',
                        'column' => 'item_id',
                        'label' => 'catalogue notes',
                        'tenant_column' => 'tenant_id',
                        'ignore_when' => ['status' => ['trashed']],
                    ],
                ],
                'permissions' => [
                    'read' => 'demo_catalog:view',
                    'trash' => 'demo_catalog:manage',
                    'restore' => 'demo_catalog:manage',
                    'retire' => 'demo_catalog:manage',
                    'delete' => 'demo_catalog:manage',
                ],
            ],
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
            AddSyncColumnsToDemoCatalogItems::class,
            GrantDemoCatalogPermissionsToAdmin::class,
            CreateDemoCatalogItemNotesTable::class,
        ];
    }

    /**
     * Handle GET /api/demo-catalog/items (requires demo_catalog:view).
     *
     * @param Request $request The incoming HTTP request.
     * @param array<string, string> $params Captured path parameters.
     * @return Response The tenant-scoped item list or changes feed.
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
     * @return Response The created (201) or idempotently-replayed (200) item.
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
     * @return Response The updated item, a tenant-scoped 404, or a 409 conflict.
     */
    public function updateItem(Request $request, array $params = []): Response
    {
        return $this->handler()->update($request, $params);
    }

    /**
     * Handle DELETE /api/demo-catalog/items/{id} (requires demo_catalog:manage).
     *
     * @param Request $request The incoming HTTP request.
     * @param array<string, string> $params Captured path parameters ('id').
     * @return Response The tombstone (200), a tenant-scoped 404, or a 409 conflict.
     */
    public function deleteItem(Request $request, array $params = []): Response
    {
        return $this->handler()->delete($request, $params);
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
