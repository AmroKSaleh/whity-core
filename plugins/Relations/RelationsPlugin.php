<?php

declare(strict_types=1);

namespace Relations;

use PDO;
use Relations\Api\PersonsApiHandler;
use Relations\Migrations\CreatePersonsTable;
use Whity\Sdk\Http\Request;
use Whity\Sdk\Http\Response;
use Whity\Sdk\PluginFrontendInterface;
use Whity\Sdk\PluginInterface;
use Whity\Sdk\PluginRequirementsInterface;
use Whity\Sdk\Sql\SequenceAllocator;

/**
 * Relations plugin (WC-65 → offline port).
 *
 * The strangler-fig extraction of core's Family Relations feature into an
 * offline-capable, syncable plugin that runs unchanged on the desktop's PHP
 * host (ADR 0003 / desktop feature-parity effort). Core Relations keeps serving
 * the web app until cutover; this plugin owns the offline (and eventually the
 * server) surface.
 *
 * SLICE 1 — persons as a two-way-syncable resource via
 * {@see \Whity\Sdk\Sync\SyncController}.
 *
 * SLICE 2 — the persons BLOCK UI: {@see getFrontendFeatures()} declares a
 * `screen: 'blocks'` tree rendered identically on web and desktop from the shared
 * block contract. Full create/edit/delete: an add-person modal (POST), a per-row
 * edit modal opened from the row and seeded via `defaultFrom`, PATCHing
 * `/api/persons/{edit-person.id}` for that row (the submitSpec endpoint-templating
 * added in slice 2b), and a per-row soft-delete. A rich in-place detail view is
 * still deferred (no read-only "show the published row" display block yet).
 *
 * Subsequent slices: the graph edges (relations + relationship types) and their
 * UI, repository-backed derived reads (relationCount, reciprocal relations,
 * search), the profile-linkage guards; the cutover (removing core Relations) is last.
 */
final class RelationsPlugin implements PluginInterface, PluginRequirementsInterface, PluginFrontendInterface
{
    public function getName(): string
    {
        return 'Relations';
    }

    public function getVersion(): string
    {
        // 0.3.1: fresh-build CREATE uses DEFAULT (NOW()) so the offline SQLite
        // host boots (bare NOW() crash-looped the host — see CreatePersonsTable).
        return '0.3.1';
    }

    public function getSdkConstraint(): string
    {
        // Requires the SDK carrying Whity\Sdk\Sync\SyncController AND the modal
        // block type (block UI overlays) — both present from SDK 1.27.
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
        $tag = ['tags' => ['relations']];

        return [
            [
                'method' => 'GET',
                'path' => '/api/persons',
                'handler' => [$this, 'listPersons'],
                'requiredRole' => null,
                'requiredPermission' => 'relations:read',
                'schema' => ['summary' => "The tenant's persons, or the ?updatedSince changes feed"] + $tag,
            ],
            [
                'method' => 'POST',
                'path' => '/api/persons',
                'handler' => [$this, 'createPerson'],
                'requiredRole' => null,
                'requiredPermission' => 'relations:manage',
                'schema' => ['summary' => 'Create a person (idempotent on clientUuid)'] + $tag,
            ],
            [
                'method' => 'GET',
                'path' => '/api/persons/{id:\d+}',
                'handler' => [$this, 'getPerson'],
                'requiredRole' => null,
                'requiredPermission' => 'relations:read',
                'schema' => ['summary' => 'One person (incl. tombstone)'] + $tag,
            ],
            [
                'method' => 'PATCH',
                'path' => '/api/persons/{id:\d+}',
                'handler' => [$this, 'updatePerson'],
                'requiredRole' => null,
                'requiredPermission' => 'relations:manage',
                'schema' => ['summary' => 'Update a person (If-Match/baseVersion → 409)'] + $tag,
            ],
            [
                'method' => 'DELETE',
                'path' => '/api/persons/{id:\d+}',
                'handler' => [$this, 'deletePerson'],
                'requiredRole' => null,
                'requiredPermission' => 'relations:manage',
                'schema' => ['summary' => 'Soft-delete a person (tombstone)'] + $tag,
            ],
        ];
    }

    /**
     * @return list<string>
     */
    public function getPermissions(): array
    {
        return ['relations:read', 'relations:manage'];
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
        return [CreatePersonsTable::class];
    }

    /**
     * SLICE 2a: the persons block UI. One `screen: 'blocks'` feature, gated on
     * `relations:read`; the write affordances (the add-person form + its submit)
     * carry `relations:manage`, so a read-only delegate never sees them.
     *
     * @return list<array<string, mixed>>
     */
    public function getFrontendFeatures(): array
    {
        return [
            [
                'id' => 'relations',
                'label' => 'Relations',
                'icon' => 'users-group',
                'group' => 'plugins',
                'order' => 30,
                'screen' => 'blocks',
                'requiredPermission' => 'relations:read',
                'blocks' => $this->blocks(),
            ],
        ];
    }

    /**
     * The persons tree: a synced list, an add-person modal, and a per-row delete.
     * Create closes its modal and refetches the list on success (the overlay
     * submit-success → refresh-signal path); delete tombstones and refreshes.
     * Validated against {@see \Whity\Sdk\Frontend\Blocks\BlockValidator} at
     * registration.
     *
     * @return list<array<string, mixed>>
     */
    private function blocks(): array
    {
        return [
            [
                'type' => 'section',
                'title' => 'People',
                'children' => [
                    [
                        'type' => 'text',
                        'value' => "Everyone in this tenant's relations graph. Changes sync offline-first.",
                        'tone' => 'muted',
                    ],
                    // Add-person modal — a top-level trigger that POSTs a new
                    // person (the sync engine generates the id + clientUuid).
                    [
                        'type' => 'modal',
                        'id' => 'create-person',
                        'title' => 'Add person',
                        'trigger' => 'Add person',
                        'variant' => 'primary',
                        'size' => 'md',
                        'children' => [
                            [
                                'type' => 'form',
                                'submit' => ['method' => 'POST', 'endpoint' => '/api/persons'],
                                'requiredPermission' => 'relations:manage',
                                'children' => [
                                    ['type' => 'textInput', 'name' => 'displayName', 'label' => 'Name', 'required' => true],
                                    ['type' => 'dateInput', 'name' => 'birthDate', 'label' => 'Birth date'],
                                    ['type' => 'checkbox', 'name' => 'deceased', 'label' => 'Deceased'],
                                    ['type' => 'textArea', 'name' => 'notes', 'label' => 'Notes', 'rows' => 3],
                                    ['type' => 'submitButton', 'label' => 'Add person', 'requiredPermission' => 'relations:manage'],
                                ],
                            ],
                        ],
                    ],
                    // Edit-person modal — no trigger; opened by the row's Edit
                    // action, which publishes the row under this id. The inputs
                    // seed from it via defaultFrom, and the form PATCHes the
                    // opened row (last-write-wins: no baseVersion sent). The whole
                    // row is submitted, matching the sync full-replace update.
                    [
                        'type' => 'modal',
                        'id' => 'edit-person',
                        'title' => 'Edit person',
                        'size' => 'md',
                        'children' => [
                            [
                                'type' => 'form',
                                'submit' => ['method' => 'PATCH', 'endpoint' => '/api/persons/{edit-person.id}'],
                                'requiredPermission' => 'relations:manage',
                                'children' => [
                                    ['type' => 'textInput', 'name' => 'displayName', 'label' => 'Name', 'required' => true, 'defaultFrom' => 'edit-person.displayName'],
                                    ['type' => 'dateInput', 'name' => 'birthDate', 'label' => 'Birth date', 'defaultFrom' => 'edit-person.birthDate'],
                                    ['type' => 'checkbox', 'name' => 'deceased', 'label' => 'Deceased', 'defaultFrom' => 'edit-person.deceased'],
                                    ['type' => 'textArea', 'name' => 'notes', 'label' => 'Notes', 'rows' => 3, 'defaultFrom' => 'edit-person.notes'],
                                    ['type' => 'submitButton', 'label' => 'Save changes', 'requiredPermission' => 'relations:manage'],
                                ],
                            ],
                        ],
                    ],
                    // The synced persons list, with a per-row soft-delete.
                    [
                        'type' => 'dataTable',
                        'source' => '/api/persons',
                        'columns' => [
                            ['key' => 'displayName', 'label' => 'Name', 'sortable' => true, 'filterable' => true],
                            ['key' => 'birthDate', 'label' => 'Birth date', 'sortable' => true],
                        ],
                        'pageSize' => 20,
                        'emptyText' => 'No people yet — add the first one.',
                        'rowActions' => [
                            // Opens the edit modal, publishing the row so the form
                            // seeds via defaultFrom and PATCHes /api/persons/{id}.
                            [
                                'label' => 'Edit',
                                'open' => 'edit-person',
                            ],
                            [
                                'label' => 'Delete',
                                'method' => 'DELETE',
                                'endpoint' => '/api/persons/{id}',
                                'confirm' => 'Delete this person? This removes them from the relations graph.',
                            ],
                        ],
                    ],
                ],
            ],
        ];
    }

    // ==================== route delegates ====================

    /** @param array<string, string> $params */
    public function listPersons(Request $request, array $params = []): Response
    {
        return $this->handler()->list($request);
    }

    /** @param array<string, string> $params */
    public function getPerson(Request $request, array $params = []): Response
    {
        return $this->handler()->get($request, $params);
    }

    /** @param array<string, string> $params */
    public function createPerson(Request $request, array $params = []): Response
    {
        return $this->handler()->create($request);
    }

    /** @param array<string, string> $params */
    public function updatePerson(Request $request, array $params = []): Response
    {
        return $this->handler()->update($request, $params);
    }

    /** @param array<string, string> $params */
    public function deletePerson(Request $request, array $params = []): Response
    {
        return $this->handler()->delete($request, $params);
    }

    // ==================== wiring ====================

    /** Built per request so the host's connection self-healing/recycling is honoured. */
    private function handler(): PersonsApiHandler
    {
        return new PersonsApiHandler($this->resolvePdo(), $this->resolveSequences());
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
