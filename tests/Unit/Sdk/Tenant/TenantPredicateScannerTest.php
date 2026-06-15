<?php

declare(strict_types=1);

namespace Tests\Unit\Sdk\Tenant;

use PHPUnit\Framework\TestCase;
use Whity\Sdk\Tenant\TenantPredicateScanner;
use Whity\Sdk\Tenant\TenantTableRegistry;

/**
 * Teeth for the WC-194 SDK tenant-predicate scanner used by the conformance
 * kit: an unscoped SELECT/UPDATE/DELETE on a PLUGIN's own tenant-owned table
 * (or, via a merged registry, a HOST tenant table) must FAIL; a scoped one, a
 * declared-global one, and an annotated one must NOT.
 *
 * The scanner is registry-driven (unlike core's hardwired guard), so these
 * exercise a PLUGIN-declared table set — proving the engine polices whatever
 * tables it is handed, which is what makes it reusable for out-of-repo plugins.
 */
final class TenantPredicateScannerTest extends TestCase
{
    /** A registry as a plugin would build it: own table + (here, a stand-in) host table. */
    private function registry(): TenantTableRegistry
    {
        return TenantTableRegistry::for(
            ['announcements' => 'plugin tenant table', 'users' => 'host tenant table (merged)'],
            ['app_settings' => 'platform-wide settings']
        );
    }

    /** @param non-empty-string $sql */
    private function scan(string $sql): array
    {
        $source = <<<PHP
        <?php
        class Probe
        {
            public function run(\$db): void
            {
                \$stmt = \$db->prepare('{$sql}');
            }
        }
        PHP;

        return (new TenantPredicateScanner($this->registry()))->scanSource($source, 'Probe.php');
    }

    public function testUnscopedQueryOnPluginTenantTableIsFlagged(): void
    {
        // THE TEETH: the plugin's own tenant table queried without a tenant_id.
        $violations = $this->scan('SELECT id, body FROM announcements WHERE id = ?');

        self::assertCount(1, $violations, 'An unscoped query on the plugin tenant table must FAIL.');
        self::assertSame(['announcements'], $violations[0]['tables']);
    }

    public function testUnscopedQueryOnMergedHostTableIsFlagged(): void
    {
        // A plugin that touches a HOST tenant table unscoped is flagged too,
        // because the conformance test merges the host registry in.
        self::assertCount(1, $this->scan('DELETE FROM users WHERE id = ?'));
    }

    public function testScopedQueryOnPluginTenantTableIsNotFlagged(): void
    {
        self::assertSame([], $this->scan('SELECT id FROM announcements WHERE id = ? AND tenant_id = ?'));
        self::assertSame([], $this->scan('UPDATE announcements SET body = ? WHERE id = ? AND tenant_id = :tid'));
    }

    public function testDeclaredGlobalTableIsNeverFlagged(): void
    {
        self::assertSame([], $this->scan('SELECT value FROM app_settings WHERE id = ?'));
    }

    public function testUnknownTableIsNotPoliced(): void
    {
        // A table the registry knows nothing about is out of scope (the plugin
        // only owns the isolation invariant for tables it declares).
        self::assertSame([], $this->scan('SELECT * FROM some_other_thing WHERE id = ?'));
    }

    public function testAnnotationSuppressesAFlagWithReason(): void
    {
        $source = <<<'PHP'
        <?php
        class Probe
        {
            public function run($db): void
            {
                // @tenant-guard-ignore: system-tenant (id 0) sees all tenants
                $stmt = $db->prepare('SELECT * FROM announcements WHERE id = ?');
            }
        }
        PHP;

        self::assertSame([], (new TenantPredicateScanner($this->registry()))->scanSource($source, 'Probe.php'));
    }

    public function testInsertIsOutOfScope(): void
    {
        self::assertSame([], $this->scan('INSERT INTO announcements (tenant_id, body) VALUES (?, ?)'));
    }
}
