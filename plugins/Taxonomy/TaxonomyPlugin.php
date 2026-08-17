<?php

declare(strict_types=1);

namespace Taxonomy;

use PDO;
use Taxonomy\Api\TagGroupsApiHandler;
use Taxonomy\Api\TagsApiHandler;
use Taxonomy\Migrations\CreateTaxonomyTables;
use Whity\Sdk\Http\Request;
use Whity\Sdk\Http\Response;
use Whity\Sdk\PluginInterface;
use Whity\Sdk\PluginRequirementsInterface;
use Whity\Sdk\Sql\SequenceAllocator;

/**
 * Taxonomy plugin (WC-63 → offline port).
 *
 * The strangler-fig extraction of core's Tags/Tag-Groups feature into an
 * offline-capable, syncable plugin that runs unchanged on the desktop's PHP
 * host (ADR 0003 / desktop feature-parity effort), mirroring the Relations
 * port. Core Taxonomy keeps serving the web app until cutover.
 *
 * SLICE 1a — the adopt-and-augment migration (offline-SQLite-safe). SLICE 1b
 * (this) — `tag_groups` and `tags` as two-way-syncable resources via
 * {@see \Whity\Sdk\Sync\SyncController}, with CRUD routes. SLICE 1c adds the
 * block UI (a master-detail: pick a group → manage its tags). Cross-store sync
 * of a tag's `group_id` reference (client→server id remapping) is deferred to
 * the same reference-remapping work the Relations edges slice defers.
 *
 * As with Relations, the `/api/tag-groups` + `/api/tags` routes + the
 * `tags:read`/`tags:manage` permissions collide with core Taxonomy on the SERVER
 * (core owns them) — so the plugin is inert there until the R3 cutover; it is the
 * sole provider on the OFFLINE host, where no core Taxonomy exists.
 */
final class TaxonomyPlugin implements PluginInterface, PluginRequirementsInterface
{
    public function getName(): string
    {
        return 'Taxonomy';
    }

    public function getVersion(): string
    {
        return '0.1.0';
    }

    public function getSdkConstraint(): string
    {
        // Requires the SDK carrying Whity\Sdk\Sync\SyncController (^1.2); the
        // block-UI slice will raise this to ^1.27 (modal/drawer) when it lands.
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
        $tag = ['tags' => ['taxonomy']];

        return [
            ['method' => 'GET', 'path' => '/api/tag-groups', 'handler' => [$this, 'listTagGroups'],
                'requiredRole' => null, 'requiredPermission' => 'tags:read',
                'schema' => ['summary' => "The tenant's tag groups, or the ?updatedSince changes feed"] + $tag],
            ['method' => 'POST', 'path' => '/api/tag-groups', 'handler' => [$this, 'createTagGroup'],
                'requiredRole' => null, 'requiredPermission' => 'tags:manage',
                'schema' => ['summary' => 'Create a tag group (idempotent on clientUuid)'] + $tag],
            ['method' => 'GET', 'path' => '/api/tag-groups/{id:\d+}', 'handler' => [$this, 'getTagGroup'],
                'requiredRole' => null, 'requiredPermission' => 'tags:read',
                'schema' => ['summary' => 'One tag group (incl. tombstone)'] + $tag],
            ['method' => 'PATCH', 'path' => '/api/tag-groups/{id:\d+}', 'handler' => [$this, 'updateTagGroup'],
                'requiredRole' => null, 'requiredPermission' => 'tags:manage',
                'schema' => ['summary' => 'Update a tag group (If-Match/baseVersion → 409)'] + $tag],
            ['method' => 'DELETE', 'path' => '/api/tag-groups/{id:\d+}', 'handler' => [$this, 'deleteTagGroup'],
                'requiredRole' => null, 'requiredPermission' => 'tags:manage',
                'schema' => ['summary' => 'Soft-delete a tag group (tombstone)'] + $tag],

            ['method' => 'GET', 'path' => '/api/tags', 'handler' => [$this, 'listTags'],
                'requiredRole' => null, 'requiredPermission' => 'tags:read',
                'schema' => ['summary' => "The tenant's tags, or the ?updatedSince changes feed"] + $tag],
            ['method' => 'POST', 'path' => '/api/tags', 'handler' => [$this, 'createTag'],
                'requiredRole' => null, 'requiredPermission' => 'tags:manage',
                'schema' => ['summary' => 'Create a tag (idempotent on clientUuid)'] + $tag],
            ['method' => 'GET', 'path' => '/api/tags/{id:\d+}', 'handler' => [$this, 'getTag'],
                'requiredRole' => null, 'requiredPermission' => 'tags:read',
                'schema' => ['summary' => 'One tag (incl. tombstone)'] + $tag],
            ['method' => 'PATCH', 'path' => '/api/tags/{id:\d+}', 'handler' => [$this, 'updateTag'],
                'requiredRole' => null, 'requiredPermission' => 'tags:manage',
                'schema' => ['summary' => 'Update a tag (If-Match/baseVersion → 409)'] + $tag],
            ['method' => 'DELETE', 'path' => '/api/tags/{id:\d+}', 'handler' => [$this, 'deleteTag'],
                'requiredRole' => null, 'requiredPermission' => 'tags:manage',
                'schema' => ['summary' => 'Soft-delete a tag (tombstone)'] + $tag],
        ];
    }

    /**
     * @return list<string>
     */
    public function getPermissions(): array
    {
        return ['tags:read', 'tags:manage'];
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
        return [CreateTaxonomyTables::class];
    }

    // ==================== route delegates: tag groups ====================

    /** @param array<string, string> $params */
    public function listTagGroups(Request $request, array $params = []): Response
    {
        return $this->tagGroupsHandler()->list($request);
    }

    /** @param array<string, string> $params */
    public function getTagGroup(Request $request, array $params = []): Response
    {
        return $this->tagGroupsHandler()->get($request, $params);
    }

    /** @param array<string, string> $params */
    public function createTagGroup(Request $request, array $params = []): Response
    {
        return $this->tagGroupsHandler()->create($request);
    }

    /** @param array<string, string> $params */
    public function updateTagGroup(Request $request, array $params = []): Response
    {
        return $this->tagGroupsHandler()->update($request, $params);
    }

    /** @param array<string, string> $params */
    public function deleteTagGroup(Request $request, array $params = []): Response
    {
        return $this->tagGroupsHandler()->delete($request, $params);
    }

    // ==================== route delegates: tags ====================

    /** @param array<string, string> $params */
    public function listTags(Request $request, array $params = []): Response
    {
        return $this->tagsHandler()->list($request);
    }

    /** @param array<string, string> $params */
    public function getTag(Request $request, array $params = []): Response
    {
        return $this->tagsHandler()->get($request, $params);
    }

    /** @param array<string, string> $params */
    public function createTag(Request $request, array $params = []): Response
    {
        return $this->tagsHandler()->create($request);
    }

    /** @param array<string, string> $params */
    public function updateTag(Request $request, array $params = []): Response
    {
        return $this->tagsHandler()->update($request, $params);
    }

    /** @param array<string, string> $params */
    public function deleteTag(Request $request, array $params = []): Response
    {
        return $this->tagsHandler()->delete($request, $params);
    }

    // ==================== wiring ====================

    /** Built per request so the host's connection self-healing/recycling is honoured. */
    private function tagGroupsHandler(): TagGroupsApiHandler
    {
        return new TagGroupsApiHandler($this->resolvePdo(), $this->resolveSequences());
    }

    private function tagsHandler(): TagsApiHandler
    {
        return new TagsApiHandler($this->resolvePdo(), $this->resolveSequences());
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
