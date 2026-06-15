<?php

declare(strict_types=1);

namespace Whity\Core\Tenant;

use Whity\Sdk\Tenant\TenantPredicateScanner;

/**
 * whity-core's adapter over the SDK tenant-predicate scanner (WC-192/WC-194).
 *
 * Enforces the platform's #1 isolation invariant: every SELECT/UPDATE/DELETE on
 * a TENANT-OWNED table must bind a `tenant_id` predicate, unless the table is a
 * sanctioned global one or the statement carries an explicit, reasoned
 * `@tenant-guard-ignore: <reason>` annotation.
 *
 * Since WC-194 the scan ENGINE lives in the standalone SDK
 * ({@see TenantPredicateScanner}) so it is the single source of truth shared by
 * core CI and out-of-repo plugins alike — there is no duplicate scanner to
 * drift. This class is a thin, backward-compatible facade that pre-loads the
 * scanner with core's own schema via {@see CoreTenantTableRegistry}, preserving
 * the original `scanDirectory()` / `scanSource()` / {@see IGNORE_TAG} surface so
 * scripts/ci-tenant-predicate-guard.php and existing callers are unchanged.
 *
 * @phpstan-import-type Violation from TenantPredicateScanner
 */
final class TenantPredicateGuard
{
    /**
     * Inline annotation that suppresses a flag (re-exported from the SDK
     * scanner so the constant's call sites and the CI script need not change).
     */
    public const IGNORE_TAG = TenantPredicateScanner::IGNORE_TAG;

    private TenantPredicateScanner $scanner;

    public function __construct()
    {
        $this->scanner = new TenantPredicateScanner(CoreTenantTableRegistry::build());
    }

    /**
     * Scan a directory tree of PHP files and return every violation found.
     *
     * @return list<Violation>
     */
    public function scanDirectory(string $dir): array
    {
        return $this->scanner->scanDirectory($dir);
    }

    /**
     * Scan a single PHP source string. Exposed for unit testing.
     *
     * @return list<Violation>
     */
    public function scanSource(string $source, string $file = '<source>'): array
    {
        return $this->scanner->scanSource($source, $file);
    }
}
