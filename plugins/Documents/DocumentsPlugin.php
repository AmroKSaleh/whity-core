<?php

declare(strict_types=1);

namespace Documents;

use Documents\Api\DocumentBlocksApiHandler;
use Documents\Api\DocumentTemplatesApiHandler;
use Documents\Migrations\AugmentDocumentDesignerTables;
use PDO;
use Whity\Sdk\Http\Request;
use Whity\Sdk\Http\Response;
use Whity\Sdk\PluginFrontendInterface;
use Whity\Sdk\PluginInterface;
use Whity\Sdk\PluginRequirementsInterface;
use Whity\Sdk\Sql\SequenceAllocator;

/**
 * Documents plugin (Document Designer -> offline port).
 *
 * The offline-syncable adopt-and-augment port of core`s Document Designer
 * (migration 059): two identical-shape, tenant-owned tables --
 * `document_templates` (a saved DocTemplate) and `document_blocks` (a reusable
 * DocElement[] fragment) -- served as two-way-syncable resources via
 * {@see \Whity\Sdk\Sync\SyncController}, mirroring the Taxonomy tag-groups/tags
 * port (ADR 0003 / desktop feature-parity effort).
 *
 * The `/api/document-templates` + `/api/document-blocks` routes and the
 * `documents:read`/`documents:write` permissions are core`s own; the plugin is
 * inert on the SERVER (core owns them until the cutover) and is the sole provider
 * on the OFFLINE host, where no core Document Designer exists.
 *
 * SLICE (this): sync + the BLOCK UI -- {@see getFrontendFeatures()} declares two
 * `screen: 'blocks'` screens (Templates, Blocks) with list + create + delete. There
 * is no in-UI edit: renaming and content editing belong to the canvas designer
 * (follow-on), which submits the whole record; a metadata-only form PATCH would wipe
 * `data` under the full-row-replace sync UPDATE. Publish/render are out of the sync
 * slice; a form-created record starts from an empty `data` placeholder.
 */
final class DocumentsPlugin implements PluginInterface, PluginRequirementsInterface, PluginFrontendInterface
{
    public function getName(): string
    {
        return 'Documents';
    }

    public function getVersion(): string
    {
        return '0.1.0';
    }

    public function getSdkConstraint(): string
    {
        // Requires SyncController AND the modal overlay block type -- SDK 1.27+.
        return '^1.27';
    }

    public function getCoreConstraint(): string
    {
        return '';
    }

    /**
     * @return array<string, string>
     */
    public function getPluginDependencies(): array
    {
        return [];
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function getRoutes(): array
    {
        $tag = ['tags' => ['documents']];

        return [
            ['method' => 'GET', 'path' => '/api/document-templates', 'handler' => [$this, 'listTemplates'],
                'requiredRole' => null, 'requiredPermission' => 'documents:read',
                'schema' => ['summary' => "The tenant's document templates, or the ?updatedSince changes feed"] + $tag],
            ['method' => 'POST', 'path' => '/api/document-templates', 'handler' => [$this, 'createTemplate'],
                'requiredRole' => null, 'requiredPermission' => 'documents:write',
                'schema' => ['summary' => 'Create a document template (idempotent on clientUuid)'] + $tag],
            ['method' => 'GET', 'path' => '/api/document-templates/{id:\d+}', 'handler' => [$this, 'getTemplate'],
                'requiredRole' => null, 'requiredPermission' => 'documents:read',
                'schema' => ['summary' => 'One document template (incl. tombstone)'] + $tag],
            ['method' => 'PATCH', 'path' => '/api/document-templates/{id:\d+}', 'handler' => [$this, 'updateTemplate'],
                'requiredRole' => null, 'requiredPermission' => 'documents:write',
                'schema' => ['summary' => 'Update a document template (If-Match/baseVersion -> 409)'] + $tag],
            ['method' => 'DELETE', 'path' => '/api/document-templates/{id:\d+}', 'handler' => [$this, 'deleteTemplate'],
                'requiredRole' => null, 'requiredPermission' => 'documents:write',
                'schema' => ['summary' => 'Soft-delete a document template (tombstone)'] + $tag],

            ['method' => 'GET', 'path' => '/api/document-blocks', 'handler' => [$this, 'listBlocks'],
                'requiredRole' => null, 'requiredPermission' => 'documents:read',
                'schema' => ['summary' => "The tenant's document blocks, or the ?updatedSince changes feed"] + $tag],
            ['method' => 'POST', 'path' => '/api/document-blocks', 'handler' => [$this, 'createBlock'],
                'requiredRole' => null, 'requiredPermission' => 'documents:write',
                'schema' => ['summary' => 'Create a document block (idempotent on clientUuid)'] + $tag],
            ['method' => 'GET', 'path' => '/api/document-blocks/{id:\d+}', 'handler' => [$this, 'getBlock'],
                'requiredRole' => null, 'requiredPermission' => 'documents:read',
                'schema' => ['summary' => 'One document block (incl. tombstone)'] + $tag],
            ['method' => 'PATCH', 'path' => '/api/document-blocks/{id:\d+}', 'handler' => [$this, 'updateBlock'],
                'requiredRole' => null, 'requiredPermission' => 'documents:write',
                'schema' => ['summary' => 'Update a document block (If-Match/baseVersion -> 409)'] + $tag],
            ['method' => 'DELETE', 'path' => '/api/document-blocks/{id:\d+}', 'handler' => [$this, 'deleteBlock'],
                'requiredRole' => null, 'requiredPermission' => 'documents:write',
                'schema' => ['summary' => 'Soft-delete a document block (tombstone)'] + $tag],
        ];
    }

    /**
     * @return list<string>
     */
    public function getPermissions(): array
    {
        return ['documents:read', 'documents:write'];
    }

    /**
     * @return array<string, mixed>
     */
    public function getHooks(): array
    {
        return [];
    }

    /**
     * @return list<class-string>
     */
    public function getMigrations(): array
    {
        return [AugmentDocumentDesignerTables::class];
    }

    /**
     * Two `screen: 'blocks'` CRUD screens -- Document Templates and Document
     * Blocks -- each gated on `documents:read`; write affordances carry
     * `documents:write`.
     *
     * @return list<array<string, mixed>>
     */
    public function getFrontendFeatures(): array
    {
        return [
            $this->feature('document-templates', 'Document Templates', 'file-text', 60, 'template', '/api/document-templates'),
            $this->feature('document-blocks', 'Document Blocks', 'stack-2', 70, 'block', '/api/document-blocks'),
        ];
    }

    /**
     * One CRUD screen over a single Document Designer table.
     *
     * @return array<string, mixed>
     */
    private function feature(string $id, string $label, string $icon, int $order, string $noun, string $source): array
    {
        return [
            'id' => $id,
            'label' => $label,
            'icon' => $icon,
            'group' => 'plugins',
            'order' => $order,
            'screen' => 'blocks',
            'requiredPermission' => 'documents:read',
            'blocks' => $this->blocks($label, $noun, $source),
        ];
    }

    /**
     * A section with a create-modal overlay and a dataTable (list + delete).
     * There is deliberately NO edit modal: {@see \Whity\Sdk\Sync\SyncController}
     * UPDATE is a full-row replace, and the `data` canvas object cannot round-trip
     * through a block form input, so a metadata-only edit would wipe it. Renaming,
     * re-scoping and content editing all belong to the canvas designer (follow-on),
     * which submits the whole record. The PATCH route still exists for device sync
     * (which always sends every field). Validated against {@see
     * \Whity\Sdk\Frontend\Blocks\BlockValidator} at registration.
     *
     * @return list<array<string, mixed>>
     */
    private function blocks(string $title, string $noun, string $source): array
    {
        $createId = "create-{$noun}";

        return [
            [
                'type' => 'section',
                'title' => $title,
                'children' => [
                    [
                        'type' => 'text',
                        'value' => 'RBAC-scoped records that sync offline-first. Renaming and content editing arrive with the canvas designer.',
                        'tone' => 'muted',
                    ],
                    $this->recordModal($createId, "New {$noun}", "New {$noun}", $source),
                    [
                        'type' => 'dataTable',
                        'source' => $source,
                        'columns' => [
                            ['key' => 'name', 'label' => 'Name', 'sortable' => true, 'filterable' => true],
                            ['key' => 'scope', 'label' => 'Scope', 'sortable' => true, 'filterable' => true],
                            ['key' => 'isSystem', 'label' => 'System', 'sortable' => true],
                            ['key' => 'updatedAt', 'label' => 'Updated', 'sortable' => true],
                        ],
                        'pageSize' => 20,
                        'emptyText' => 'Nothing here yet -- add the first one.',
                        'rowActions' => [
                            ['label' => 'Delete', 'method' => 'DELETE', 'endpoint' => "{$source}/{id}",
                                'confirm' => 'Delete this record?'],
                        ],
                    ],
                ],
            ],
        ];
    }

    /**
     * The create modal: name + visibility scope, POSTed to the sync endpoint (the
     * SyncController mints id + client_uuid). `data` (the client canvas object) is
     * deliberately NOT an input -- a new record starts with the empty placeholder
     * and is populated by the canvas designer (follow-on). There is no edit form
     * (see {@see blocks()}).
     *
     * @return array<string, mixed>
     */
    private function recordModal(string $id, string $title, ?string $trigger, string $endpoint): array
    {
        $name  = ['type' => 'textInput', 'name' => 'name', 'label' => 'Name', 'required' => true];
        $scope = ['type' => 'select', 'name' => 'scope', 'label' => 'Scope', 'required' => true,
            'options' => [
                ['value' => 'personal', 'label' => 'Personal'],
                ['value' => 'tenant', 'label' => 'Tenant'],
                ['value' => 'global', 'label' => 'Global'],
                ['value' => 'system', 'label' => 'System'],
            ]];

        return $this->modal($id, $title, $trigger, [
            'type' => 'form',
            'submit' => ['method' => 'POST', 'endpoint' => $endpoint],
            'requiredPermission' => 'documents:write',
            'children' => [$name, $scope, $this->submit()],
        ]);
    }

    /**
     * @param array<string, mixed> $form
     *
     * @return array<string, mixed>
     */
    private function modal(string $id, string $title, ?string $trigger, array $form): array
    {
        $modal = ['type' => 'modal', 'id' => $id, 'title' => $title, 'size' => 'md', 'children' => [$form]];
        if ($trigger !== null) {
            $modal['trigger'] = $trigger;
            $modal['variant'] = 'primary';
        }

        return $modal;
    }

    /** @return array<string, mixed> */
    private function submit(): array
    {
        return ['type' => 'submitButton', 'label' => 'Create', 'requiredPermission' => 'documents:write'];
    }

    // ==================== route delegates: templates ====================

    /** @param array<string, string> $params */
    public function listTemplates(Request $request, array $params = []): Response
    {
        return $this->templatesHandler()->list($request);
    }

    /** @param array<string, string> $params */
    public function getTemplate(Request $request, array $params = []): Response
    {
        return $this->templatesHandler()->get($request, $params);
    }

    /** @param array<string, string> $params */
    public function createTemplate(Request $request, array $params = []): Response
    {
        return $this->templatesHandler()->create($request);
    }

    /** @param array<string, string> $params */
    public function updateTemplate(Request $request, array $params = []): Response
    {
        return $this->templatesHandler()->update($request, $params);
    }

    /** @param array<string, string> $params */
    public function deleteTemplate(Request $request, array $params = []): Response
    {
        return $this->templatesHandler()->delete($request, $params);
    }

    // ==================== route delegates: blocks ====================

    /** @param array<string, string> $params */
    public function listBlocks(Request $request, array $params = []): Response
    {
        return $this->blocksHandler()->list($request);
    }

    /** @param array<string, string> $params */
    public function getBlock(Request $request, array $params = []): Response
    {
        return $this->blocksHandler()->get($request, $params);
    }

    /** @param array<string, string> $params */
    public function createBlock(Request $request, array $params = []): Response
    {
        return $this->blocksHandler()->create($request);
    }

    /** @param array<string, string> $params */
    public function updateBlock(Request $request, array $params = []): Response
    {
        return $this->blocksHandler()->update($request, $params);
    }

    /** @param array<string, string> $params */
    public function deleteBlock(Request $request, array $params = []): Response
    {
        return $this->blocksHandler()->delete($request, $params);
    }

    // ==================== wiring ====================

    /** Built per request so the host's connection self-healing/recycling is honoured. */
    private function templatesHandler(): DocumentTemplatesApiHandler
    {
        return new DocumentTemplatesApiHandler($this->resolvePdo(), $this->resolveSequences());
    }

    private function blocksHandler(): DocumentBlocksApiHandler
    {
        return new DocumentBlocksApiHandler($this->resolvePdo(), $this->resolveSequences());
    }

    private function resolvePdo(): PDO
    {
        $database = \Whity\app(\Whity\Database\Database::class);
        if (!$database instanceof \Whity\Database\Database) {
            throw new \RuntimeException('The host did not register the shared Database service');
        }

        return $database->getPdo();
    }

    private function resolveSequences(): SequenceAllocator
    {
        $sequences = \Whity\app(SequenceAllocator::class);
        if (!$sequences instanceof SequenceAllocator) {
            throw new \RuntimeException('The host did not provide a Whity\Sdk\Sql\SequenceAllocator');
        }

        return $sequences;
    }
}
