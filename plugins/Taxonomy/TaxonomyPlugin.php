<?php

declare(strict_types=1);

namespace Taxonomy;

use PDO;
use Taxonomy\Api\TagGroupsApiHandler;
use Taxonomy\Api\TagsApiHandler;
use Taxonomy\Migrations\CreateTaxonomyTables;
use Whity\Sdk\Http\Request;
use Whity\Sdk\Http\Response;
use Whity\Sdk\PluginFrontendInterface;
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
 * SLICE 1a — the adopt-and-augment migration (offline-SQLite-safe). SLICE 1b —
 * `tag_groups` and `tags` as two-way-syncable resources via
 * {@see \Whity\Sdk\Sync\SyncController}, with CRUD routes. SLICE 1c (this) — the
 * BLOCK UI: {@see getFrontendFeatures()} declares two CRUD screens (tag groups,
 * and tags whose owning group is picked via a `referenceSelect`), full
 * create/edit/delete on the modal overlays. Cross-store sync of a tag's
 * `group_id` reference (client→server id remapping) is deferred to the same
 * reference-remapping work the Relations edges slice defers.
 *
 * As with Relations, the `/api/tag-groups` + `/api/tags` routes + the
 * `tags:read`/`tags:manage` permissions collide with core Taxonomy on the SERVER
 * (core owns them) — so the plugin is inert there until the R3 cutover; it is the
 * sole provider on the OFFLINE host, where no core Taxonomy exists.
 *
 * That inertness reaches the frontend too (#969): the block screen below is
 * refused by whity-core's loader, and — measured, not assumed — the rule that
 * refuses it is the core-permission OWNERSHIP rule, which fires before (and
 * independently of) the route collisions. The device host applies neither rule,
 * which is why the screen renders there.
 * `scripts/ci-plugin-frontend-features.php` records that expectation and fails if
 * the reason ever changes.
 */
final class TaxonomyPlugin implements PluginInterface, PluginRequirementsInterface, PluginFrontendInterface
{
    public function getName(): string
    {
        return 'Taxonomy';
    }

    public function getVersion(): string
    {
        return '0.2.0';
    }

    public function getSdkConstraint(): string
    {
        // Requires SyncController AND the modal overlay block type — SDK 1.27+.
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

    /**
     * SLICE 1c: the Taxonomy block UI. One `screen: 'blocks'` feature gated on
     * `tags:read`; write affordances carry `tags:manage`.
     *
     * @return list<array<string, mixed>>
     */
    public function getFrontendFeatures(): array
    {
        return [
            [
                'id' => 'taxonomy',
                'label' => 'Taxonomy',
                'icon' => 'tags',
                'group' => 'plugins',
                'order' => 40,
                'screen' => 'blocks',
                'requiredPermission' => 'tags:read',
                'blocks' => $this->blocks(),
            ],
        ];
    }

    /**
     * Two CRUD screens: tag groups, and tags (each tag picks its owning group via
     * a referenceSelect fed from /api/tag-groups). Create/edit run on modal
     * overlays; edit seeds via defaultFrom and PATCHes the templated per-row
     * endpoint; delete tombstones. Validated against {@see
     * \Whity\Sdk\Frontend\Blocks\BlockValidator} at registration.
     *
     * @return list<array<string, mixed>>
     */
    private function blocks(): array
    {
        return [
            [
                'type' => 'section',
                'title' => 'Tag groups',
                'children' => [
                    [
                        'type' => 'text',
                        'value' => 'Groups that organise the tenant\'s tags. Changes sync offline-first.',
                        'tone' => 'muted',
                    ],
                    $this->tagGroupModal('create-tag-group', 'New tag group', 'New tag group', 'POST', '/api/tag-groups', false),
                    $this->tagGroupModal('edit-tag-group', 'Edit tag group', null, 'PATCH', '/api/tag-groups/{edit-tag-group.id}', true),
                    [
                        'type' => 'dataTable',
                        'source' => '/api/tag-groups',
                        'columns' => [
                            ['key' => 'groupKey', 'label' => 'Key', 'sortable' => true, 'filterable' => true],
                            ['key' => 'displayName', 'label' => 'Display name', 'sortable' => true],
                        ],
                        'pageSize' => 20,
                        'emptyText' => 'No tag groups yet — add the first one.',
                        'rowActions' => [
                            ['label' => 'Edit', 'open' => 'edit-tag-group'],
                            ['label' => 'Delete', 'method' => 'DELETE', 'endpoint' => '/api/tag-groups/{id}',
                                'confirm' => 'Delete this tag group? Its tags are removed too.'],
                        ],
                    ],
                ],
            ],
            [
                'type' => 'section',
                'title' => 'Tags',
                'children' => [
                    $this->tagModal('create-tag', 'New tag', 'New tag', 'POST', '/api/tags', false),
                    $this->tagModal('edit-tag', 'Edit tag', null, 'PATCH', '/api/tags/{edit-tag.id}', true),
                    [
                        'type' => 'dataTable',
                        'source' => '/api/tags',
                        'columns' => [
                            ['key' => 'name', 'label' => 'Name', 'sortable' => true, 'filterable' => true],
                        ],
                        'pageSize' => 20,
                        'emptyText' => 'No tags yet — add the first one.',
                        'rowActions' => [
                            ['label' => 'Edit', 'open' => 'edit-tag'],
                            ['label' => 'Delete', 'method' => 'DELETE', 'endpoint' => '/api/tags/{id}',
                                'confirm' => 'Delete this tag?'],
                        ],
                    ],
                ],
            ],
        ];
    }

    /**
     * A create/edit modal for a tag group. When $seeded, inputs pull from the
     * opened row via defaultFrom (a full-replace edit must carry every field).
     *
     * @return array<string, mixed>
     */
    private function tagGroupModal(string $id, string $title, ?string $trigger, string $method, string $endpoint, bool $seeded): array
    {
        $key  = ['type' => 'textInput', 'name' => 'groupKey', 'label' => 'Key', 'required' => true];
        $name = ['type' => 'textInput', 'name' => 'displayName', 'label' => 'Display name'];
        if ($seeded) {
            $key['defaultFrom']  = "{$id}.groupKey";
            $name['defaultFrom'] = "{$id}.displayName";
        }

        return $this->modal($id, $title, $trigger, [
            'type' => 'form',
            'submit' => ['method' => $method, 'endpoint' => $endpoint],
            'requiredPermission' => 'tags:manage',
            'children' => [$key, $name, $this->submit($seeded)],
        ]);
    }

    /**
     * A create/edit modal for a tag: its owning group is chosen via a
     * referenceSelect fed from /api/tag-groups.
     *
     * @return array<string, mixed>
     */
    private function tagModal(string $id, string $title, ?string $trigger, string $method, string $endpoint, bool $seeded): array
    {
        $group = ['type' => 'referenceSelect', 'name' => 'groupId', 'label' => 'Group',
            'source' => '/api/tag-groups', 'valueField' => 'id', 'labelField' => 'groupKey', 'required' => true];
        $name  = ['type' => 'textInput', 'name' => 'name', 'label' => 'Name', 'required' => true];
        if ($seeded) {
            $group['defaultFrom'] = "{$id}.groupId";
            $name['defaultFrom']  = "{$id}.name";
        }

        return $this->modal($id, $title, $trigger, [
            'type' => 'form',
            'submit' => ['method' => $method, 'endpoint' => $endpoint],
            'requiredPermission' => 'tags:manage',
            'children' => [$group, $name, $this->submit($seeded)],
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
    private function submit(bool $seeded): array
    {
        return ['type' => 'submitButton', 'label' => $seeded ? 'Save changes' : 'Create', 'requiredPermission' => 'tags:manage'];
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
