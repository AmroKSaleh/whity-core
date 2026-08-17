<?php

declare(strict_types=1);

namespace Taxonomy;

use Taxonomy\Migrations\CreateTaxonomyTables;
use Whity\Sdk\PluginInterface;
use Whity\Sdk\PluginRequirementsInterface;

/**
 * Taxonomy plugin (WC-63 → offline port).
 *
 * The strangler-fig extraction of core's Tags/Tag-Groups feature into an
 * offline-capable, syncable plugin that runs unchanged on the desktop's PHP
 * host (ADR 0003 / desktop feature-parity effort), mirroring the Relations
 * port. Core Taxonomy keeps serving the web app until cutover.
 *
 * SLICE 1a (this) — the adopt-and-augment migration only: `tag_groups` and
 * `tags` gain the {@see \Whity\Sdk\Sync\SyncController} sync columns, verified
 * to build cleanly on the offline SQLite engine ({@see
 * \Tests\Plugins\TaxonomyPluginOfflineConformanceTest}). SLICE 1b adds the
 * syncable resources + CRUD routes (tag_groups, then tags scoped to a group);
 * SLICE 1c the block UI (a master-detail: pick a group → manage its tags).
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
        // SLICE 1b: tag_groups + tags CRUD via SyncController-backed handlers.
        return [];
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
}
