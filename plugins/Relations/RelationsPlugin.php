<?php

declare(strict_types=1);

namespace Relations;

use PDO;
use Relations\Api\PersonsApiHandler;
use Relations\Migrations\CreatePersonsTable;
use Whity\Sdk\Http\Request;
use Whity\Sdk\Http\Response;
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
 * {@see \Whity\Sdk\Sync\SyncController}. The graph edges (relations +
 * relationship types), the repository-backed derived reads (relationCount,
 * reciprocal relations, search), the profile-linkage guards, and the block UI
 * are subsequent slices; the cutover (removing core Relations) is last.
 */
final class RelationsPlugin implements PluginInterface, PluginRequirementsInterface
{
    public function getName(): string
    {
        return 'Relations';
    }

    public function getVersion(): string
    {
        return '0.1.0';
    }

    public function getSdkConstraint(): string
    {
        // Requires the SDK carrying Whity\Sdk\Sync\SyncController.
        return '^1.2';
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
