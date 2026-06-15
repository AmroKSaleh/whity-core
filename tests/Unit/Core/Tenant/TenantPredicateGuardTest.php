<?php

declare(strict_types=1);

namespace Tests\Unit\Core\Tenant;

use PHPUnit\Framework\TestCase;
use Whity\Core\Tenant\TenantPredicateGuard;

/**
 * Detection-logic tests for the WC-192 tenant-predicate guard.
 *
 * These pin the scanner's contract so its teeth (and its restraint) cannot
 * regress: an unscoped SELECT/UPDATE/DELETE on a tenant-owned table is FLAGGED;
 * a scoped one, a sanctioned-global one, an INSERT, and an annotated one are
 * NOT. The guard runs as a CI gate (scripts/ci-tenant-predicate-guard.php), so a
 * false negative here is a tenant-isolation hole shipped to CI as green.
 */
final class TenantPredicateGuardTest extends TestCase
{
    private TenantPredicateGuard $guard;

    protected function setUp(): void
    {
        $this->guard = new TenantPredicateGuard();
    }

    /** @param non-empty-string $sql */
    private function scan(string $sql, string $extra = ''): array
    {
        // Wrap a SQL literal in a minimal method so the statement-scoped parser
        // sees a realistic function body.
        $source = <<<PHP
        <?php
        class Probe
        {
            public function run(\$db): void
            {
                {$extra}
                \$stmt = \$db->prepare('{$sql}');
            }
        }
        PHP;

        return $this->guard->scanSource($source, 'Probe.php');
    }

    public function testUnscopedSelectOnTenantTableIsFlagged(): void
    {
        $violations = $this->scan('DELETE FROM users WHERE id = ?');

        self::assertCount(1, $violations, 'An unscoped DELETE on users must be flagged.');
        self::assertSame(['users'], $violations[0]['tables']);
    }

    public function testUnscopedSelectAndUpdateAreFlagged(): void
    {
        self::assertCount(1, $this->scan('SELECT * FROM persons WHERE id = ?'));
        self::assertCount(1, $this->scan('UPDATE relations SET note = ? WHERE id = ?'));
    }

    public function testScopedQueryWithTenantPredicateIsNotFlagged(): void
    {
        self::assertSame([], $this->scan('DELETE FROM users WHERE id = ? AND tenant_id = ?'));
        self::assertSame([], $this->scan('SELECT * FROM persons WHERE id = :id AND tenant_id = :tid'));
        self::assertSame([], $this->scan('UPDATE roles SET name = ? WHERE id = ? AND tenant_id = ?'));
    }

    public function testAliasedTenantPredicateIsNotFlagged(): void
    {
        self::assertSame(
            [],
            $this->scan('SELECT u.id FROM users u JOIN roles r ON u.role_id = r.id WHERE u.tenant_id = ?')
        );
    }

    public function testTransitiveJoinPredicateIsNotFlagged(): void
    {
        // persons scoped to the relation's tenant via the join condition.
        self::assertSame(
            [],
            $this->scan('SELECT p.id FROM relations r JOIN persons p ON p.id = r.to_person_id AND p.tenant_id = r.tenant_id')
        );
    }

    public function testCorrelatedExistsPredicateIsNotFlagged(): void
    {
        self::assertSame(
            [],
            $this->scan('DELETE FROM users WHERE id = ? AND EXISTS (SELECT 1 FROM roles r WHERE r.id = users.role_id AND r.tenant_id = ?)')
        );
    }

    public function testTenantIdInProjectionListIsNotASufficientPredicate(): void
    {
        // tenant_id appears only in the SELECT list, not as a predicate — the row
        // is NOT scoped, so this MUST be flagged.
        $violations = $this->scan('SELECT id, tenant_id, email FROM users WHERE id = ?');

        self::assertCount(1, $violations, 'A projected tenant_id column does not scope the row.');
    }

    public function testSanctionedGlobalTableIsNeverFlagged(): void
    {
        self::assertSame([], $this->scan('DELETE FROM revoked_tokens WHERE jti = ?'));
        self::assertSame([], $this->scan('SELECT 1 FROM core_schema_migrations WHERE migration = ?'));
    }

    public function testNonTenantTablesAreNotPoliced(): void
    {
        // permissions / role_permissions / backup_codes carry no tenant_id column
        // and are scoped transitively via a parent — not directly scannable.
        self::assertSame([], $this->scan('SELECT * FROM permissions WHERE name = ?'));
        self::assertSame([], $this->scan('DELETE FROM role_permissions WHERE role_id = ?'));
        self::assertSame([], $this->scan('SELECT * FROM backup_codes WHERE user_id = ?'));
        self::assertSame([], $this->scan('SELECT * FROM relationship_types WHERE id = ?'));
        self::assertSame([], $this->scan('SELECT * FROM tenants WHERE id = ?'));
    }

    public function testInsertIsOutOfScope(): void
    {
        self::assertSame([], $this->scan('INSERT INTO users (tenant_id, email) VALUES (?, ?)'));
    }

    public function testUpsertWithDoUpdateIsOutOfScope(): void
    {
        // INSERT ... ON CONFLICT ... DO UPDATE SET — the trailing UPDATE must not
        // trip the guard; the conflict target carries (tenant_id, ...).
        self::assertSame(
            [],
            $this->scan('INSERT INTO deployments (tenant_id, current_version) VALUES (?, ?) ON CONFLICT (tenant_id, current_version) DO UPDATE SET status = ?')
        );
    }

    public function testAnnotationSuppressesAFlagWhenReasonGiven(): void
    {
        $source = <<<'PHP'
        <?php
        class Probe
        {
            public function run($db): void
            {
                // @tenant-guard-ignore: system-tenant (id 0) sees all tenants
                $stmt = $db->prepare('SELECT * FROM users WHERE id = ?');
            }
        }
        PHP;

        self::assertSame([], $this->guard->scanSource($source, 'Probe.php'));
    }

    public function testAnnotationOnSameLineSuppresses(): void
    {
        $source = <<<'PHP'
        <?php
        class Probe
        {
            public function run($db): void
            {
                $sql = 'DELETE FROM persons WHERE id = ?'; // @tenant-guard-ignore: bootstrap
                $db->prepare($sql);
            }
        }
        PHP;

        self::assertSame([], $this->guard->scanSource($source, 'Probe.php'));
    }

    public function testAnnotationWithoutReasonDoesNotSuppress(): void
    {
        $source = <<<'PHP'
        <?php
        class Probe
        {
            public function run($db): void
            {
                // @tenant-guard-ignore:
                $stmt = $db->prepare('SELECT * FROM users WHERE id = ?');
            }
        }
        PHP;

        self::assertCount(
            1,
            $this->guard->scanSource($source, 'Probe.php'),
            'A reason-less annotation must NOT suppress the flag.'
        );
    }

    public function testConcatenatedUpdateWithTrailingTenantPredicateIsNotFlagged(): void
    {
        // The implode()-built SET plus a trailing literal carrying the tenant
        // predicate must be merged into one scoped statement (UsersApiHandler
        // WC-190 pattern).
        $source = <<<'PHP'
        <?php
        class Probe
        {
            public function run($db, array $updates, $id, $tenantId): void
            {
                $sql = 'UPDATE users SET ' . implode(', ', $updates) . ' WHERE id = ? AND tenant_id = ?';
                $db->prepare($sql);
            }
        }
        PHP;

        self::assertSame([], $this->guard->scanSource($source, 'Probe.php'));
    }

    public function testBuilderVariableAppendCarriesPredicate(): void
    {
        // $sql .= ' WHERE ' . 'tenant_id = :tid' builder pattern is reassembled.
        $source = <<<'PHP'
        <?php
        class Probe
        {
            public function run($db): void
            {
                $sql = 'SELECT * FROM audit_log';
                $sql .= ' WHERE tenant_id = :tid';
                $db->prepare($sql);
            }
        }
        PHP;

        self::assertSame([], $this->guard->scanSource($source, 'Probe.php'));
    }

    public function testInterpolatedDoubleQuotedScopedQueryIsNotFlagged(): void
    {
        // "... {$column} ... AND (tenant_id = ? OR tenant_id IS NULL)" — the
        // interior {$column} interpolation must NOT truncate the SQL before the
        // tenant predicate (UsersApiHandler::resolveVisibleRoleId WC-110 pattern).
        $source = <<<'PHP'
        <?php
        class Probe
        {
            public function run($db, $column): void
            {
                $stmt = $db->prepare("SELECT id FROM roles WHERE {$column} = ? AND (tenant_id = ? OR tenant_id IS NULL) LIMIT 1");
            }
        }
        PHP;

        self::assertSame([], $this->guard->scanSource($source, 'Probe.php'));
    }

    public function testInterpolatedDoubleQuotedUnscopedQueryIsFlagged(): void
    {
        $source = <<<'PHP'
        <?php
        class Probe
        {
            public function run($db, $column): void
            {
                $stmt = $db->prepare("SELECT id FROM roles WHERE {$column} = ? LIMIT 1");
            }
        }
        PHP;

        self::assertCount(1, $this->guard->scanSource($source, 'Probe.php'));
    }

    public function testCleanSourceWithNoSqlProducesNoViolations(): void
    {
        $source = <<<'PHP'
        <?php
        class Probe
        {
            public function run(): int
            {
                return 1 + 1;
            }
        }
        PHP;

        self::assertSame([], $this->guard->scanSource($source, 'Probe.php'));
    }
}
