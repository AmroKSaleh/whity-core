<?php

declare(strict_types=1);

namespace Whity\Sdk\Tenant;

/**
 * The set of tables the {@see TenantPredicateScanner} reasons about (WC-194).
 *
 * Tenant isolation is enforced logically: a TENANT-OWNED table carries a
 * `tenant_id` column and every SELECT/UPDATE/DELETE on it must bind a
 * `tenant_id` predicate. A small set of tables are, by design, GLOBAL (no
 * `tenant_id` column; platform-unique rows) and are exempt.
 *
 * This registry is the SDK-portable, dependency-free model of those two sets.
 * It is what makes the scanner reusable across hosts and plugins:
 *
 *  - whity-core builds a registry from its OWN schema (users, roles, …) plus
 *    its sanctioned globals (revoked_tokens, …);
 *  - a distributable plugin builds a registry from ITS own migration tables
 *    (e.g. `announcements`) and {@see merge()}s in the host's table sets so a
 *    handler that reads a CORE tenant table without scoping is still flagged.
 *
 * The registry is immutable: {@see withTenantOwned()} / {@see withGlobal()} /
 * {@see merge()} return new instances, so a shared base set can be safely
 * extended per call site without spooky action at a distance.
 */
final class TenantTableRegistry
{
    /**
     * Tenant-owned table names (carry a `tenant_id` column), keyed by lowercase
     * name with a short provenance/rationale string.
     *
     * @var array<string, string>
     */
    private array $tenantOwned;

    /**
     * Sanctioned global table names (no `tenant_id` column, platform-unique),
     * keyed by lowercase name with a documented rationale.
     *
     * @var array<string, string>
     */
    private array $global;

    /**
     * @param array<string, string> $tenantOwned table => provenance/rationale
     * @param array<string, string> $global      table => rationale
     */
    public function __construct(array $tenantOwned = [], array $global = [])
    {
        $this->tenantOwned = self::lowercaseKeys($tenantOwned);
        $this->global = self::lowercaseKeys($global);
    }

    /**
     * A registry declaring the given tenant-owned tables (each with a reason),
     * and optionally the sanctioned globals. Convenience for plugins that only
     * need to name their own tables.
     *
     * @param array<string, string> $tenantOwned
     * @param array<string, string> $global
     */
    public static function for(array $tenantOwned, array $global = []): self
    {
        return new self($tenantOwned, $global);
    }

    /**
     * Return a copy with an extra tenant-owned table declared.
     */
    public function withTenantOwned(string $table, string $reason = ''): self
    {
        $tenantOwned = $this->tenantOwned;
        $tenantOwned[strtolower($table)] = $reason;

        return new self($tenantOwned, $this->global);
    }

    /**
     * Return a copy with an extra sanctioned-global table declared.
     */
    public function withGlobal(string $table, string $reason = ''): self
    {
        $global = $this->global;
        $global[strtolower($table)] = $reason;

        return new self($this->tenantOwned, $global);
    }

    /**
     * Return a copy that also knows every table the other registry knows.
     *
     * A plugin merges the host's registry into its own so an unscoped query
     * against a HOST tenant table (not just the plugin's tables) is still
     * flagged. On a key collision the OTHER registry's entry wins for globals
     * (a host may sanction a table the plugin naively treated as owned) while
     * tenant-owned membership is the union.
     */
    public function merge(self $other): self
    {
        return new self(
            [...$this->tenantOwned, ...$other->tenantOwned],
            [...$this->global, ...$other->global],
        );
    }

    /**
     * Whether the table carries a `tenant_id` column and must therefore bind a
     * tenant predicate on every SELECT/UPDATE/DELETE.
     */
    public function isTenantOwned(string $table): bool
    {
        return array_key_exists(strtolower($table), $this->tenantOwned);
    }

    /**
     * Whether the table is a sanctioned global (non-tenant-scoped) table.
     */
    public function isGlobal(string $table): bool
    {
        return array_key_exists(strtolower($table), $this->global);
    }

    /**
     * The tenant-owned table names.
     *
     * @return list<string>
     */
    public function tenantOwnedTables(): array
    {
        return array_keys($this->tenantOwned);
    }

    /**
     * The sanctioned global table names.
     *
     * @return list<string>
     */
    public function globalTables(): array
    {
        return array_keys($this->global);
    }

    /**
     * @param array<string, string> $map
     * @return array<string, string>
     */
    private static function lowercaseKeys(array $map): array
    {
        $out = [];
        foreach ($map as $table => $reason) {
            $out[strtolower($table)] = $reason;
        }

        return $out;
    }
}
