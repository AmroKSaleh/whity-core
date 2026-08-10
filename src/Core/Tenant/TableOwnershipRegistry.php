<?php

declare(strict_types=1);

namespace Whity\Core\Tenant;

use Whity\Core\Container\HostWiredService;
use Whity\Core\Hooks\HookManager;
use Whity\Core\Support\SourceSlug;
use Whity\Sdk\Tenant\PluginTablesInterface;
use Whity\Sdk\Tenant\TenantTableRegistry;

/**
 * The runtime catalogue of WHO OWNS each database table (WC-723, Piece 1).
 *
 * The gap this closes
 * -------------------
 * {@see TenantTableRegistry} in the SDK models the two SHAPES a table can have
 * — tenant-owned or sanctioned-global — and that is all the tenant-predicate
 * scanner needs. It cannot answer "who owns this table?", for three reasons,
 * each fatal on its own:
 *
 *  1. it stores a free-text RATIONALE per table, not a structured owner;
 *  2. {@see TenantTableRegistry::merge()} folds the host's tables into a
 *     plugin's set with no record of origin, so after merging a plugin's
 *     registry answers `isTenantOwned('memberships') === true`;
 *  3. it is SELF-ASSERTED — the plugin constructs it, so nothing stops one
 *     declaring `memberships` as its own.
 *
 * It is also test-only today: nothing builds one while serving a request.
 *
 * Attribution comes from the LOADER, not the declaration
 * -----------------------------------------------------
 * That is the whole design. A plugin declares WHICH tables it claims; the host
 * stamps WHO claimed them, from `$plugin->getName()` — a value the loader holds
 * and the plugin cannot influence through its return value. A plugin may
 * therefore claim any table name it likes, but it cannot claim to be somebody
 * else. Same model as {@see \Whity\Core\RBAC\PermissionRegistry::register()} and
 * {@see \Whity\Core\RBAC\ResourceTypeRegistry::register()}.
 *
 * Claims are FIRST-COME and non-transferable: core registers its own tables at
 * boot before any plugin loads, so a plugin claiming `memberships` is refused, and
 * refusal discards its ENTIRE declaration rather than the contested entry alone
 * — a half-applied claim would make ownership depend on iteration order.
 *
 * What ownership is for
 * ---------------------
 * A plugin may declare a referential guard ("these rows still point at that
 * record") only over tables it owns. Without that gate a guard is a way to
 * count rows in another plugin's — or core's — data by declaration alone. That
 * is the constraint this registry exists to make enforceable, and it is why
 * ownership must be a fact the host stamped rather than a claim the plugin
 * made.
 *
 * Deliberately an INSTANCE service, mirroring the RBAC registries
 * --------------------------------------------------------------
 * Not a static catalogue. Process-level statics are per FrankenPHP worker, so a
 * registration performed while serving one request is invisible to the other
 * workers — the hazard behind the stale-permission bug in PR #701. An instance
 * resolved from the container is rebuilt per boot from the same plugin
 * bootstrap every worker runs, so every worker agrees.
 */
class TableOwnershipRegistry implements HostWiredService
{
    /** Source name for tables shipped by whity-core's own migrations. Reserved. */
    public const CORE_SOURCE = 'core';

    /** A table carrying a `tenant_id` column. */
    public const SCOPE_TENANT = PluginTablesInterface::SCOPE_TENANT;

    /** A table holding no tenant data. */
    public const SCOPE_GLOBAL = PluginTablesInterface::SCOPE_GLOBAL;

    /**
     * The PostgreSQL identifier length limit. A longer name could not name a
     * real table, so accepting it would only defer the failure to query time.
     */
    private const MAX_IDENTIFIER_LENGTH = 63;

    /**
     * Ownership records, keyed by lowercase table name.
     *
     * @var array<string, array{source: string, scope: string}>
     */
    private array $owners = [];

    /**
     * Whether core's tables have already been registered.
     */
    private bool $coreRegistered = false;

    private ?HookManager $hookManager;

    /**
     * @param HookManager|null $hookManager Announces registrations on the
     *                                      platform's durable event spine.
     */
    public function __construct(?HookManager $hookManager = null)
    {
        $this->hookManager = $hookManager;
    }

    /**
     * Claim tables for a source.
     *
     * ALL entries are validated before ANY is stored, so a declaration is
     * accepted whole or refused whole.
     *
     * @param string                $source The claiming source — a plugin name
     *                                      supplied by the loader. `core` is reserved.
     * @param array<string, string> $tables table name => self::SCOPE_TENANT|self::SCOPE_GLOBAL
     *
     * @throws TableOwnershipException When the source is unusable or reserved,
     *                                 a table name or scope is malformed, or a
     *                                 table is already owned by another source.
     */
    public function register(string $source, array $tables): void
    {
        if ($source === self::CORE_SOURCE) {
            throw TableOwnershipException::forReservedSource($source);
        }

        $slug = SourceSlug::from($source);
        if ($slug === null) {
            throw TableOwnershipException::forSource($source);
        }

        $this->ensureCoreRegistered();
        $this->validateAndStore($slug, $tables);
    }

    /**
     * Register every table whity-core's migrations create. Idempotent and
     * bootstrap-safe.
     *
     * Core claims its tables FIRST so that a plugin claiming one loses the race
     * by construction rather than by load order. The complete set comes from
     * {@see CoreTables}; the tenant scope of each comes from
     * {@see TenantOwnedTables}, which is the migration-pinned answer to whether
     * the table carries a `tenant_id` column.
     */
    public function registerCoreTables(): void
    {
        if ($this->coreRegistered) {
            return;
        }

        // Set first so a hook subscriber cannot recurse back into lazy core
        // registration (the same guard PermissionRegistry uses).
        $this->coreRegistered = true;

        $tables = [];
        foreach (CoreTables::all() as $table) {
            $tables[$table] = TenantOwnedTables::isTenantOwned($table)
                ? self::SCOPE_TENANT
                : self::SCOPE_GLOBAL;
        }

        $this->validateAndStore(self::CORE_SOURCE, $tables);
    }

    /**
     * The source that owns a table, or null when nobody has claimed it.
     *
     * @param string $table The table name.
     */
    public function ownerOf(string $table): ?string
    {
        $this->ensureCoreRegistered();

        return $this->owners[strtolower($table)]['source'] ?? null;
    }

    /**
     * Whether a source owns a table.
     *
     * The gate every referential-guard declaration passes through. `$source` is
     * normalised the same way a claim was, so a plugin is recognised by the
     * same name it registered under.
     *
     * @param string $table  The table in question.
     * @param string $source The source claiming to own it.
     */
    public function isOwnedBy(string $table, string $source): bool
    {
        $owner = $this->ownerOf($table);
        if ($owner === null) {
            return false;
        }

        if ($source === self::CORE_SOURCE) {
            return $owner === self::CORE_SOURCE;
        }

        $slug = SourceSlug::from($source);

        return $slug !== null && $owner === $slug;
    }

    /**
     * Whether a table carries a `tenant_id` column, per its owner's declaration.
     *
     * An unowned table answers false: the host will not build a tenant-scoped
     * query against a table nobody has vouched for.
     *
     * @param string $table The table name.
     */
    public function isTenantScoped(string $table): bool
    {
        $this->ensureCoreRegistered();

        return ($this->owners[strtolower($table)]['scope'] ?? null) === self::SCOPE_TENANT;
    }

    /**
     * The tables one source owns.
     *
     * @param string $source The source name.
     * @return list<string> Table names, ascending.
     */
    public function tablesOf(string $source): array
    {
        $this->ensureCoreRegistered();

        $slug = $source === self::CORE_SOURCE ? self::CORE_SOURCE : SourceSlug::from($source);
        if ($slug === null) {
            return [];
        }

        $tables = [];
        foreach ($this->owners as $table => $record) {
            if ($record['source'] === $slug) {
                $tables[] = $table;
            }
        }
        sort($tables);

        return $tables;
    }

    /**
     * Every owned table with its owner, ascending by table name.
     *
     * @return array<string, string> table => owning source
     */
    public function all(): array
    {
        $this->ensureCoreRegistered();

        $out = [];
        foreach ($this->owners as $table => $record) {
            $out[$table] = $record['source'];
        }
        ksort($out);

        return $out;
    }

    /**
     * Project the live ownership map onto the SDK's portable
     * {@see TenantTableRegistry}.
     *
     * This is what "promote to runtime" buys: the tenant-predicate scanner and
     * the migration linter were previously limited to whity-core's own
     * compile-time lists plus whatever a plugin hand-wrote in its test. Built
     * from this registry they see every table every LOADED plugin actually
     * declared, with no plugin-authored assembly.
     *
     * The projection is deliberately lossy — ownership does not survive it,
     * because the SDK type has nowhere to put it. Ownership questions must be
     * asked HERE.
     */
    public function toTenantTableRegistry(): TenantTableRegistry
    {
        $this->ensureCoreRegistered();

        $tenantOwned = [];
        $global = [];
        foreach ($this->owners as $table => $record) {
            if ($record['scope'] === self::SCOPE_TENANT) {
                $tenantOwned[$table] = "owned by {$record['source']}";
            } else {
                $global[$table] = "owned by {$record['source']}";
            }
        }

        return new TenantTableRegistry($tenantOwned, $global);
    }

    /**
     * Validate an entire declaration, then store it and announce it.
     *
     * @param string                $slug   The normalised owner slug.
     * @param array<string, string> $tables table => scope
     *
     * @throws TableOwnershipException
     */
    private function validateAndStore(string $slug, array $tables): void
    {
        /** @var array<string, array{source: string, scope: string}> $accepted */
        $accepted = [];

        foreach ($tables as $table => $scope) {
            $name = strtolower(trim($table));
            if (!self::isValidTableName($name)) {
                throw TableOwnershipException::forTableName($table);
            }
            if ($scope !== self::SCOPE_TENANT && $scope !== self::SCOPE_GLOBAL) {
                throw TableOwnershipException::forScope($table, $scope);
            }

            $existing = $this->owners[$name] ?? null;
            if ($existing !== null && $existing['source'] !== $slug) {
                throw TableOwnershipException::forConflict($name, $existing['source'], $slug);
            }

            $accepted[$name] = ['source' => $slug, 'scope' => $scope];
        }

        foreach ($accepted as $name => $record) {
            $this->owners[$name] = $record;
        }

        if ($accepted === []) {
            return;
        }

        $this->hookManager?->dispatch('tenant.tables.registered', [
            'source' => $slug,
            'tables' => array_keys($accepted),
        ]);
    }

    /**
     * A valid table name: lowercase, starts with a letter, then letters, digits
     * or underscores, within the identifier length limit.
     *
     * The host interpolates owned table names into generated SQL — validation
     * here is what makes that safe, so it is deliberately strict rather than
     * merely indicative.
     *
     * @param string $table The candidate name (already lowercased).
     */
    public static function isValidTableName(string $table): bool
    {
        return strlen($table) <= self::MAX_IDENTIFIER_LENGTH
            && preg_match('/^[a-z][a-z0-9_]*$/', $table) === 1;
    }

    /**
     * Lazily register core's tables on first read, so a reader never sees a map
     * missing `memberships` merely because bootstrap order changed.
     */
    private function ensureCoreRegistered(): void
    {
        if (!$this->coreRegistered) {
            $this->registerCoreTables();
        }
    }
}
